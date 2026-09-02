#!/bin/bash
#
# EXPERIMENTAL / SPIKE — non-destructive evaluation of the PostgreSQL -> MariaDB
# migration for a YunoHost Moodle instance.
#
# This does NOT touch the live instance: it copies the current PostgreSQL data
# into a THROWAWAY MariaDB database, reports what came across, and drops the
# throwaway database again. config.php and the live databases are left untouched.
#
# Use it on a staging server to gauge how reliable Moodle's tool_dbtransfer is
# for your data set BEFORE trusting the automatic migration wired into
# scripts/upgrade.
#
# Usage (as root, on the YunoHost server):
#     ./migrate_pg_to_mariadb.sh <app_instance_name>   # e.g. moodle
#
set -euo pipefail

APP="${1:-}"
if [ -z "$APP" ]; then
    echo "Usage: $0 <app_instance_name>" >&2
    exit 1
fi
if [ "$(id -u)" -ne 0 ]; then
    echo "This script must be run as root." >&2
    exit 1
fi

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
WRAPPER="${DBTRANSFER_CLI:-$SCRIPT_DIR/../../conf/dbtransfer_cli.php}"
if [ ! -r "$WRAPPER" ]; then
    echo "Cannot find dbtransfer_cli.php (looked at: $WRAPPER)." >&2
    echo "Set DBTRANSFER_CLI=/path/to/dbtransfer_cli.php and retry." >&2
    exit 1
fi

setting() { yunohost app setting "$APP" "$1"; }

INSTALL_DIR="$(setting install_dir)"
DB_NAME="$(setting db_name)"
PHP_VERSION="$(setting php_version || echo 8.3)"
CONFIG_PHP="$INSTALL_DIR/config.php"

if [ ! -r "$CONFIG_PHP" ]; then
    echo "config.php not found at $CONFIG_PHP" >&2
    exit 1
fi

echo "==> Evaluating migration for app '$APP'"
echo "    install_dir = $INSTALL_DIR"
echo "    source db   = $DB_NAME (PostgreSQL)"

# --- Sanity: the source really is PostgreSQL ---------------------------------
if ! grep -qE "dbtype\s*=\s*'pgsql'" "$CONFIG_PHP"; then
    echo "config.php does not declare dbtype='pgsql'; this instance may already be on MariaDB. Aborting." >&2
    exit 1
fi

# --- Create a throwaway MariaDB target ---------------------------------------
TEST_DB="${DB_NAME}_migtest"
TEST_USER="${DB_NAME}_migtest"
TEST_PWD="$(openssl rand -hex 16)"

cleanup() {
    echo "==> Cleaning up throwaway MariaDB database '$TEST_DB'"
    mysql -B <<SQL || true
DROP DATABASE IF EXISTS \`$TEST_DB\`;
DROP USER IF EXISTS '$TEST_USER'@'localhost';
SQL
}
trap cleanup EXIT

echo "==> Creating throwaway MariaDB database '$TEST_DB'"
mysql -B <<SQL
DROP DATABASE IF EXISTS \`$TEST_DB\`;
CREATE DATABASE \`$TEST_DB\` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER IF NOT EXISTS '$TEST_USER'@'localhost' IDENTIFIED BY '$TEST_PWD';
GRANT ALL PRIVILEGES ON \`$TEST_DB\`.* TO '$TEST_USER'@'localhost';
FLUSH PRIVILEGES;
SQL

# --- Run the transfer (source pgsql -> throwaway mariadb) --------------------
echo "==> Running tool_dbtransfer (this reads the live PostgreSQL data read-only)"
TMP_WRAPPER="$INSTALL_DIR/.dbtransfer_cli_spike.php"
cp -f "$WRAPPER" "$TMP_WRAPPER"
trap 'rm -f "$TMP_WRAPPER"; cleanup' EXIT

"php${PHP_VERSION}" "$TMP_WRAPPER" \
    --configphp="$CONFIG_PHP" \
    --target-dbtype="mariadb" \
    --target-dbhost="localhost" \
    --target-dbname="$TEST_DB" \
    --target-dbuser="$TEST_USER" \
    --target-dbpass="$TEST_PWD" \
    --target-prefix="mdl_"

rm -f "$TMP_WRAPPER"
trap cleanup EXIT

# --- Compare a few counts ----------------------------------------------------
echo "==> Comparing table counts"
PG_TABLES="$(sudo --login --user=postgres psql -tAc \
    "SELECT count(*) FROM information_schema.tables WHERE table_schema='public' AND table_name LIKE 'mdl\_%'" "$DB_NAME")"
MY_TABLES="$(mysql -B -N <<SQL
SELECT COUNT(*) FROM information_schema.tables WHERE table_schema='$TEST_DB' AND table_name LIKE 'mdl\_%';
SQL
)"
echo "    PostgreSQL mdl_ tables : $PG_TABLES"
echo "    MariaDB    mdl_ tables : $MY_TABLES"

ROW_MISMATCH=0
for t in mdl_user mdl_course mdl_config; do
    PG_ROWS="$(sudo --login --user=postgres psql -tAc "SELECT count(*) FROM public.$t" "$DB_NAME" 2>/dev/null || echo '?')"
    MY_ROWS="$(mysql -B -N "$TEST_DB" <<< "SELECT COUNT(*) FROM $t;" 2>/dev/null || echo '?')"
    FLAG=""
    if [ "$PG_ROWS" != "$MY_ROWS" ]; then
        ROW_MISMATCH=1
        FLAG="  <-- MISMATCH"
    fi
    printf "    %-14s  pg=%s  mariadb=%s%s\n" "$t" "$PG_ROWS" "$MY_ROWS" "$FLAG"
done

echo
if [ "$PG_TABLES" = "$MY_TABLES" ] && [ "$MY_TABLES" -gt 0 ] && [ "$ROW_MISMATCH" -eq 0 ]; then
    echo "RESULT: table counts and sampled row counts match — transfer looks complete."
    RESULT=0
else
    echo "RESULT: DIFFERENCES found (table or row counts) — inspect the transfer output above."
    RESULT=1
fi
echo "(The throwaway MariaDB database will now be dropped; the live site was not modified.)"
exit "$RESULT"
