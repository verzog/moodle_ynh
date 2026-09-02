<?php
// This file is part of the Moodle package for YunoHost.
//
// EXPERIMENTAL / SPIKE — cross-engine database migration.
//
// Moodle ships an admin tool, tool_dbtransfer ("Site administration > Server >
// Database > Migrate to another database server"), that copies the live
// database into a second, empty database of a *different* engine. It has no
// official CLI, so this wrapper drives the same internal API
// (tool_dbtransfer_transfer_database) non-interactively, mirroring the web flow
// in admin/tool/dbtransfer/index.php.
//
// It is used by scripts/upgrade to move a legacy PostgreSQL instance to MariaDB,
// and can also be run by hand for evaluation (see doc/migration-spike/).
//
// The script bootstraps Moodle with the *current* config.php (the source
// database, e.g. PostgreSQL) and transfers everything into the target database
// whose connection details are passed as options. The source is left untouched.

/**
 * Usage:
 *   php dbtransfer_cli.php \
 *       --configphp=/var/www/moodle/config.php \
 *       --target-dbtype=mariadb \
 *       --target-dbhost=localhost \
 *       --target-dbname=moodle \
 *       --target-dbuser=moodle \
 *       --target-dbpass=SECRET \
 *       [--target-dblibrary=native] \
 *       [--target-prefix=mdl_] \
 *       [--target-dbsocket=] \
 *       [--target-dbport=]
 */

define('CLI_SCRIPT', true);
define('NO_OUTPUT_BUFFERING', true);

// --- Minimal option parsing (we cannot use Moodle's cli helpers before boot) --
$options = getopt('', [
    'configphp:',
    'target-dbtype:',
    'target-dblibrary::',
    'target-dbhost:',
    'target-dbname:',
    'target-dbuser:',
    'target-dbpass:',
    'target-prefix::',
    'target-dbsocket::',
    'target-dbport::',
    'help',
]);

function fail($msg) {
    fwrite(STDERR, "ERROR: $msg\n");
    exit(1);
}

if (isset($options['help'])) {
    fwrite(STDOUT, "See the header of this file for usage.\n");
    exit(0);
}

foreach (['configphp', 'target-dbtype', 'target-dbhost', 'target-dbname', 'target-dbuser', 'target-dbpass'] as $required) {
    if (empty($options[$required])) {
        fail("missing required option --$required");
    }
}

$configphp = $options['configphp'];
if (!is_readable($configphp)) {
    fail("cannot read source config.php at '$configphp'");
}

$targettype    = $options['target-dbtype'];
$targetlibrary = isset($options['target-dblibrary']) && $options['target-dblibrary'] !== '' ? $options['target-dblibrary'] : 'native';
$targethost    = $options['target-dbhost'];
$targetname    = $options['target-dbname'];
$targetuser    = $options['target-dbuser'];
$targetpass    = $options['target-dbpass'];
$targetprefix  = isset($options['target-prefix']) && $options['target-prefix'] !== '' ? $options['target-prefix'] : 'mdl_';

$targetdboptions = [];
if (!empty($options['target-dbsocket'])) {
    $targetdboptions['dbsocket'] = $options['target-dbsocket'];
}
if (!empty($options['target-dbport'])) {
    $targetdboptions['dbport'] = $options['target-dbport'];
}
$targetdboptions['dbcollation'] = 'utf8mb4_unicode_ci';

// --- Bootstrap Moodle against the SOURCE database ----------------------------
require($configphp);
require_once($CFG->libdir . '/adminlib.php');
require_once($CFG->dirroot . '/admin/tool/dbtransfer/locallib.php');

// Guard: the transfer tool must be available in this Moodle version.
if (!function_exists('tool_dbtransfer_transfer_database')) {
    fail('tool_dbtransfer is not available in this Moodle version.');
}

if ($CFG->dbtype === $targettype) {
    fail("source and target database types are identical ('$targettype') — nothing to migrate.");
}

// --- Connect to the TARGET database ------------------------------------------
$targetdb = moodle_database::get_driver_instance($targettype, $targetlibrary, false);
if (!$targetdb) {
    fail("could not instantiate target driver '$targettype/$targetlibrary'.");
}

try {
    $targetdb->connect($targethost, $targetuser, $targetpass, $targetname, $targetprefix, $targetdboptions);
} catch (Throwable $e) {
    fail('could not connect to target database: ' . $e->getMessage());
}

// The target must be empty (no Moodle tables), exactly like the web tool checks.
if ($targetdb->get_tables()) {
    fail('target database is not empty — refusing to overwrite existing tables.');
}

fwrite(STDOUT, "Transferring database from '{$CFG->dbtype}' to '{$targettype}' (target: {$targetname})...\n");

// Tell Moodle a migration is in progress so bootstrap-time upgrade checks that
// may run during the transfer are skipped (mirrors index.php behaviour).
$CFG->tool_dbransfer_migration_running = true;

try {
    $feedback = new text_progress_trace();
    tool_dbtransfer_transfer_database($DB, $targetdb, $feedback);
    $feedback->finished();
} catch (Throwable $e) {
    unset($CFG->tool_dbransfer_migration_running);
    fail('transfer failed: ' . $e->getMessage());
}

unset($CFG->tool_dbransfer_migration_running);

// Sanity check: the target should now hold tables.
if (!$targetdb->get_tables()) {
    fail('transfer reported success but the target database still has no tables.');
}

fwrite(STDOUT, "Transfer completed successfully.\n");
$targetdb->dispose();
exit(0);
