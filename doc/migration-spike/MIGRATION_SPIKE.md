# PostgreSQL → MariaDB migration — spike notes

This package moved its database backend from **PostgreSQL** to **MariaDB**.
Fresh installs go straight to MariaDB; existing PostgreSQL instances are migrated
in place by `scripts/upgrade`. This document explains how that migration works,
why it is built the way it is, what is **verified** vs. **unverified**, and how
to evaluate it before trusting it in production.

## TL;DR

- Migration mechanism: Moodle's core admin tool **`tool_dbtransfer`**, driven
  non-interactively by `conf/dbtransfer_cli.php` (Moodle ships no CLI for it).
- It is wired into `scripts/upgrade` and runs **once**, automatically, on the
  first upgrade of a legacy PostgreSQL instance.
- It is designed to **fail safe**: if anything goes wrong the upgrade aborts
  *before* `config.php` is switched or the PostgreSQL database is dropped, so the
  site keeps working on PostgreSQL. A safety dump is also written to the data dir.
- ⚠️ The Moodle-internal transfer path could **not be executed in the
  environment where this was written** (no live Moodle instance). Treat it as a
  prototype and validate it with `migrate_pg_to_mariadb.sh` on a staging copy of
  real data before rolling it out.

## Why this shape (the YunoHost constraints)

A per-install "PostgreSQL *or* MariaDB" choice is **not** cleanly supportable,
which is why this is a full switch rather than a dual-DB option:

1. YunoHost's `[resources.database]` `type` is a **static literal**
   (`mysql`/`postgresql`); it cannot be driven by an install question, and TOML
   has no conditionals.
2. On **upgrade**, `AppResourceManager` **deprovisions any resource removed from
   the manifest** (`yunohost/src/utils/resources.py` `compute_todos`, invoked
   from `app.py` with `action="upgrade"`). For the database resource,
   deprovision means **`DROP DATABASE`**. So simply dropping the resource to
   manage the DB by hand would destroy every existing site's data.

Changing the resource `type` from `postgresql` to `mysql` is instead applied as
a resource **update** (the resource keeps the name `database`), which
*provisions the new MariaDB database and never drops the PostgreSQL one*. That
gives us an empty MariaDB target created by the framework, while the old data is
still safely in PostgreSQL — the ideal starting point for the transfer.

## How the automatic migration works (`scripts/upgrade`)

Order matters. The migration runs **before** `ynh_setup_source`, i.e. while the
**old** Moodle code and the PostgreSQL `config.php` are still in place, so Moodle
bootstraps consistently against PostgreSQL (no version mismatch):

1. Detect a legacy instance: `database == postgresql` **and** a PostgreSQL DB
   for this app exists.
2. Write a safety dump to `$data_dir/pre-mariadb-migration.pgsql.sql` (included
   in backups).
3. Reset the MariaDB target to an empty **utf8mb4 / utf8mb4_unicode_ci** database
   (Moodle requires utf8mb4; also makes retries idempotent — `tool_dbtransfer`
   refuses a non-empty target).
4. Enable Moodle maintenance mode.
5. Run `conf/dbtransfer_cli.php`, which bootstraps Moodle against the current
   (PostgreSQL) `config.php`, connects to the MariaDB target, and calls
   `tool_dbtransfer_transfer_database($DB, $targetdb)`.
6. Verify the MariaDB database now holds `mdl_*` tables; abort if not.
7. Regenerate `config.php` from the template (now `dbtype = 'mariadb'`).
8. Drop the old PostgreSQL database and user.
9. Record `database = mariadb`.

Then the normal upgrade continues: `ynh_setup_source` (new code, keeping the new
`config.php`), config regeneration, LDAP re-config, `admin/cli/upgrade.php`
(migrates the MariaDB schema to the new Moodle version), cache purge, and
maintenance mode off.

### PostgreSQL decommissioning

`manifest.toml`'s apt resource uses `packages_from_raw_bash` to pull in
`postgresql` + `php8.3-pgsql` **only while a PostgreSQL database for the app
still exists**. After a successful migration the PostgreSQL DB is gone, so the
*next* upgrade no longer lists those packages and APT autoremoves them. Fresh
installs never pull PostgreSQL in.

## The CLI wrapper (`conf/dbtransfer_cli.php`)

`tool_dbtransfer` only has a web UI (`admin/tool/dbtransfer/index.php`). The
wrapper mirrors that flow for the CLI:

- `define('CLI_SCRIPT', true)`, `require` the source `config.php`, then
  `require_once .../admin/tool/dbtransfer/locallib.php`.
- `moodle_database::get_driver_instance('mariadb', 'native')`, `connect(...)`,
  and refuse a non-empty target (same check as the web form).
- Set `$CFG->tool_dbransfer_migration_running = true` around the transfer.
- `tool_dbtransfer_transfer_database($DB, $targetdb, new text_progress_trace())`.

API confirmed present in `MOODLE_500_STABLE`
(`admin/tool/dbtransfer/locallib.php`: `tool_dbtransfer_transfer_database`,
`moodle_database::get_driver_instance($type, $library, $external=false)`,
`connect($dbhost, $dbuser, $dbpass, $dbname, $prefix, ?array $dboptions)`).

## Verified vs. unverified

**Verified (by reading core source):**
- YunoHost resource deprovision-on-removal behaviour and the resource-`update`
  semantics of changing `type`.
- `packages_from_raw_bash` receives app settings as env vars and runs before the
  upgrade script.
- YunoHost 2.1 mysql/psql helper signatures used throughout.
- Presence and signatures of the `tool_dbtransfer` functions in Moodle 5.0.

**NOT yet verified (needs a live instance):**
- That `tool_dbtransfer` bootstraps cleanly with **new code before
  `admin/cli/upgrade.php` has run** — we deliberately run it with the *old* code
  to avoid this, but the end-to-end sequence still needs a real run.
- Data fidelity for large / edge-case datasets (sequences, boolean columns,
  binary/`bytea`, long text, custom collations).
- That `tool_dbtransfer` still exists and behaves identically in Moodle **5.1**
  (the tag was not published at authoring time; it exists through 5.0).
- Performance on large sites (the transfer is row-by-row).

## How to evaluate before trusting it

Use the **non-destructive** evaluator on a staging clone of real data:

```bash
# as root on the (staging) YunoHost server, from this directory:
./migrate_pg_to_mariadb.sh moodle
```

It copies the live PostgreSQL data into a **throwaway** MariaDB database, prints
table and key row counts (PostgreSQL vs. MariaDB), then drops the throwaway DB.
`config.php` and the live databases are never modified.

Recommended validation checklist:
1. Run the evaluator; confirm table counts match and key tables
   (`mdl_user`, `mdl_course`, `mdl_config`) have equal row counts.
2. On a full staging clone, run the real upgrade end-to-end and log in.
3. Check Site administration → Reports for DB errors, and run
   `php admin/cli/check_database_schema.php` if available.
4. Only then roll out to production, and take a backup first.
