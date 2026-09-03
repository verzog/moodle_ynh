<?php
/**
 * Standalone pre-flight downgrade checker for the Moodle YunoHost package.
 *
 * Predicts Moodle's own `cannotdowngrade` protection BEFORE the destructive
 * PostgreSQL -> MariaDB migration in scripts/upgrade runs. It reads every
 * installed component version straight from the (still-active) source database
 * and compares each against the version shipped by the target Moodle codebase.
 * If any installed component -- core OR a plugin -- is NEWER than the target,
 * Moodle would refuse the upgrade with `cannotdowngrade`; by then the migration
 * would already have moved (and dropped) the data. Detecting it here lets the
 * upgrade abort up front, with the source database fully intact, and name every
 * offending component so the admin knows exactly what to fix.
 *
 * A common trigger is a third-party plugin that was later absorbed into Moodle
 * core with a LOWER version number (e.g. the standalone aiprovider_gemini
 * add-on vs. the copy bundled since Moodle 5.2): core-version comparison alone
 * misses it, which is why this checks per component.
 *
 * Deliberately does NOT bootstrap Moodle: it connects via PDO and parses
 * version.php files textually, so it is safe to run read-only against the
 * source database (no caches touched, no upgrade side effects).
 *
 * Exit codes:
 *   0 - no downgrade detected (safe to proceed)
 *   2 - one or more components would be downgraded (report printed to stdout)
 *   1 - the check itself could not run (usage error, no DB, ...); the caller
 *       treats this as "could not verify" rather than a hard block.
 */

define('CLI_SCRIPT', true);

/**
 * Extract the numeric core $version from a Moodle core version.php.
 */
function moodle_pkg_parse_core_version(string $file): ?string {
    $src = @file_get_contents($file);
    if ($src === false) {
        return null;
    }
    if (preg_match('/\$version\s*=\s*([0-9]+(?:\.[0-9]+)?)/', $src, $m)) {
        return $m[1];
    }
    return null;
}

/**
 * Extract [component, version] from a Moodle plugin version.php.
 *
 * Every Moodle plugin declares its frankenstyle name in $plugin->component,
 * which matches the `plugin` column of mdl_config_plugins exactly, so no
 * plugin-type/directory mapping is needed. Returns [null, ...] when the file
 * does not declare a component (skipped by the caller).
 */
function moodle_pkg_parse_plugin_version(string $file): array {
    $src = @file_get_contents($file);
    if ($src === false) {
        return [null, null];
    }
    $component = null;
    $version = null;
    if (preg_match('/\$plugin->component\s*=\s*[\'"]([^\'"]+)[\'"]/', $src, $m)) {
        $component = $m[1];
    }
    if (preg_match('/\$plugin->version\s*=\s*([0-9]+(?:\.[0-9]+)?)/', $src, $m)) {
        $version = $m[1];
    } else if (preg_match('/\$module->version\s*=\s*([0-9]+(?:\.[0-9]+)?)/', $src, $m)) {
        // Legacy activity modules declared their version on $module.
        $version = $m[1];
    }
    return [$component, $version];
}

/**
 * True if version $a is strictly newer than $b. Moodle versions are numeric
 * (YYYYMMDDXX, core carries a .XX increment), safely comparable as floats.
 */
function moodle_pkg_ver_gt(string $a, string $b): bool {
    return ((float) $a) > ((float) $b);
}

$opts = getopt('', [
    'db-type:', 'db-host:', 'db-name:', 'db-user:', 'db-pass:',
    'db-prefix:', 'targetroot:',
]);

foreach (['db-type', 'db-host', 'db-name', 'db-user', 'db-prefix', 'targetroot'] as $req) {
    if (!isset($opts[$req])) {
        fwrite(STDERR, "Missing required option --$req\n");
        exit(1);
    }
}
$dbtype     = $opts['db-type'];
$dbhost     = $opts['db-host'];
$dbname     = $opts['db-name'];
$dbuser     = $opts['db-user'];
$dbpass     = $opts['db-pass'] ?? '';
$prefix     = $opts['db-prefix'];
$targetroot = rtrim($opts['targetroot'], '/');

// --- Connect to the source database (read-only usage) --------------------
if ($dbtype === 'pgsql') {
    $dsn = "pgsql:host=$dbhost;dbname=$dbname";
} else if ($dbtype === 'mysql' || $dbtype === 'mariadb') {
    $dsn = "mysql:host=$dbhost;dbname=$dbname;charset=utf8mb4";
} else {
    fwrite(STDERR, "Unsupported --db-type: $dbtype\n");
    exit(1);
}
try {
    $pdo = new PDO($dsn, $dbuser, $dbpass, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
} catch (Throwable $e) {
    fwrite(STDERR, "Could not connect to the source database: " . $e->getMessage() . "\n");
    exit(1);
}

// --- Read installed versions from the database ---------------------------
$installed = []; // component => version string
try {
    $corever = $pdo->query("SELECT value FROM {$prefix}config WHERE name = 'version'")->fetchColumn();
    if ($corever !== false && $corever !== null) {
        $installed['core'] = (string) $corever;
    }
    $rows = $pdo->query("SELECT plugin, value FROM {$prefix}config_plugins WHERE name = 'version'")
                ->fetchAll(PDO::FETCH_NUM);
    foreach ($rows as $row) {
        $installed[(string) $row[0]] = (string) $row[1];
    }
} catch (Throwable $e) {
    fwrite(STDERR, "Could not read component versions from the database: " . $e->getMessage() . "\n");
    exit(1);
}

// --- Read versions shipped by the target codebase ------------------------
$coreversionfile = is_file("$targetroot/public/version.php") ? "$targetroot/public/version.php"
                 : (is_file("$targetroot/version.php") ? "$targetroot/version.php" : null);
$scanroot = is_dir("$targetroot/public") ? "$targetroot/public" : $targetroot;
if (!is_dir($scanroot)) {
    fwrite(STDERR, "Target Moodle code not found under $targetroot\n");
    exit(1);
}

$target = []; // component => version string
if ($coreversionfile !== null) {
    $cv = moodle_pkg_parse_core_version($coreversionfile);
    if ($cv !== null) {
        $target['core'] = $cv;
    }
}
$corereal = $coreversionfile !== null ? realpath($coreversionfile) : null;
$rii = new RecursiveIteratorIterator(
    new RecursiveDirectoryIterator($scanroot, FilesystemIterator::SKIP_DOTS)
);
foreach ($rii as $file) {
    if ($file->getFilename() !== 'version.php') {
        continue;
    }
    $path = $file->getPathname();
    if ($corereal !== null && realpath($path) === $corereal) {
        continue; // core, already handled
    }
    [$component, $version] = moodle_pkg_parse_plugin_version($path);
    if ($component !== null && $version !== null) {
        $target[$component] = $version;
    }
}

// --- Compare: any installed component newer than the target is a downgrade
$downgrades = [];
foreach ($installed as $component => $dbver) {
    if (!isset($target[$component])) {
        // Not shipped by the target (e.g. an add-on with no bundled successor).
        // That surfaces later as a "missing plugin", not a downgrade, and does
        // not trip cannotdowngrade -- so it is out of scope here.
        continue;
    }
    if (moodle_pkg_ver_gt($dbver, $target[$component])) {
        $downgrades[$component] = [$dbver, $target[$component]];
    }
}

if (!empty($downgrades)) {
    ksort($downgrades);
    echo "The following installed components are NEWER than the target Moodle, so\n";
    echo "Moodle would refuse the upgrade (cannotdowngrade):\n";
    foreach ($downgrades as $component => $pair) {
        printf("  - %s: installed %s -> target %s\n", $component, $pair[0], $pair[1]);
    }
    exit(2);
}
exit(0);
