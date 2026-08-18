<?php
/**
 * Checks the assumptions this system rests on, on the machine it runs on.
 *
 *     php bin/selftest.php                      # what the app user can verify
 *     php bin/selftest.php --owner-user=root --owner-pass=secret
 *
 * WHY THIS EXISTS RATHER THAN JUST UNIT TESTS
 * The unit suite runs on a laptop. Several things this design depends on are
 * properties of the SERVER, and shared hosting is where they go wrong:
 *
 *   - Does the host grant the TRIGGER privilege at all?
 *   - Is shell_exec disabled, making the backup script a silent no-op?
 *   - Does mysqldump exist, and does its output actually contain the triggers?
 *   - Do PHP and MySQL agree what day it is?
 *   - Is the backup directory above the web root, and writable?
 *
 * Every one of those can be true locally and false on mpc.so. None of them
 * announces itself. A backup that never runs looks exactly like a backup that
 * runs, until the day you need it.
 *
 * A NOTE ON HOW THIS TESTS THE GRANTS, because the obvious way is dangerous.
 * The tempting check is to attempt an UPDATE or a TRUNCATE and confirm it is
 * refused. Do not. If the grants are wrong — the exact case this is looking for
 * — then TRUNCATE succeeds, and the check destroys the ledger it was written to
 * protect. So this reads SHOW GRANTS and reasons about it. A test must not be
 * able to cause the failure it is testing for.
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("bin/selftest.php is a command-line tool.\n");
}

require_once dirname(__DIR__) . '/lib/db.php';

$opts  = getopt('', ['owner-user:', 'owner-pass:', 'owner-host:']);
$pass  = 0;
$fail  = 0;
$skip  = 0;

function result(string $state, string $what, string $detail = ''): void
{
    global $pass, $fail, $skip;
    $state === 'PASS' ? $pass++ : ($state === 'FAIL' ? $fail++ : $skip++);
    printf("  %-4s  %-44s %s\n", $state, $what, $detail);
}
function pass(string $w, string $d = ''): void { result('PASS', $w, $d); }
function fail(string $w, string $d = ''): void { result('FAIL', $w, $d); }
function skip(string $w, string $d = ''): void { result('SKIP', $w, $d); }
function section(string $t): void { echo "\n" . $t . "\n"; }


// ---------------------------------------------------------------------------
section('PHP');
// ---------------------------------------------------------------------------

// README records the host as PHP 8.4. Local development has been on 8.0, so a
// mismatch here is worth seeing rather than assuming.
version_compare(PHP_VERSION, '8.0', '>=')
    ? pass('PHP version', PHP_VERSION)
    : fail('PHP version', PHP_VERSION . ' — 8.0 or newer expected');

extension_loaded('pdo_mysql')
    ? pass('pdo_mysql extension', 'loaded')
    : fail('pdo_mysql extension', 'MISSING — nothing can reach the database');

// shell_exec is frequently disabled on shared hosting. If it is, bin/backup.php
// cannot call mysqldump and would produce nothing, quietly, every night.
$disabled = array_map('trim', explode(',', (string) ini_get('disable_functions')));
$shellOk  = ! in_array('shell_exec', $disabled, true) && function_exists('shell_exec');
$shellOk
    ? pass('shell_exec available', 'backup can call mysqldump')
    : fail('shell_exec available', 'DISABLED — bin/backup.php needs the PHP fallback');


// ---------------------------------------------------------------------------
section('Configuration');
// ---------------------------------------------------------------------------

try {
    $configPath = mpc_config_path();
    $webRoot    = dirname(__DIR__);
    $isOutside  = ! str_starts_with(realpath($configPath) ?: $configPath, realpath($webRoot) ?: $webRoot);

    pass('config file found', $configPath);

    // Inside the web root is survivable — lib/.htaccess denies it — but outside
    // cannot be requested at all, whatever a future Apache config does.
    //
    // lib/config.local.php is the documented DEVELOPMENT path, so finding it
    // there is not a fault, it is the point. Reporting it as a failure on a
    // laptop trains people to ignore red lines, and an ignored selftest is
    // worse than none. On the server, where mpc-config.php should have been
    // found instead, it is a real finding.
    $isDevConfig = basename($configPath) === 'config.local.php';
    if ($isOutside) {
        pass('credentials above the web root', 'unreachable over HTTP by construction');
    } elseif ($isDevConfig) {
        skip('credentials above the web root', 'using lib/config.local.php (development)');
    } else {
        fail('credentials above the web root', 'inside it; protected only by lib/.htaccess');
    }
} catch (Throwable $e) {
    fail('config file found', $e->getMessage());
    echo "\nCannot continue without configuration.\n";
    exit(1);
}

// The .htaccess is the only thing standing between a misconfigured .php handler
// and the database password, when the config sits inside the web root.
is_readable(dirname(__DIR__) . '/lib/.htaccess')
    ? pass('lib/.htaccess present', 'deny rule shipped')
    : fail('lib/.htaccess present', 'MISSING — lib/ may be web-readable');


// ---------------------------------------------------------------------------
section('Database');
// ---------------------------------------------------------------------------

try {
    $db = mpc_db();
    pass('connects', $db->getAttribute(PDO::ATTR_SERVER_VERSION));
} catch (Throwable $e) {
    fail('connects', $e->getMessage());
    echo "\nCannot continue without a database.\n";
    exit(1);
}

// MariaDB and MySQL are not interchangeable here: CHECK constraints are
// enforced on MariaDB 10.2+ and silently ignored on MySQL 5.7. Anything that
// ever relies on one needs to know which this is.
$server = (string) $db->getAttribute(PDO::ATTR_SERVER_VERSION);
pass('server flavour', str_contains(strtolower($server), 'maria') ? 'MariaDB' : 'MySQL');

$dbTz  = (string) $db->query('SELECT @@session.time_zone')->fetchColumn();
$dbTz === MPC_DB_TIME_ZONE
    ? pass('MySQL session timezone', $dbTz)
    : fail('MySQL session timezone', "$dbTz — expected " . MPC_DB_TIME_ZONE);

// The check that matters more than either clock on its own. If these disagree,
// paid_on and created_at can land on different days for one payment, and the
// receipt in the student's hand will not match the ledger.
$phpDay = date('Y-m-d H');
$dbDay  = (string) $db->query("SELECT DATE_FORMAT(NOW(), '%Y-%m-%d %H')")->fetchColumn();
$phpDay === $dbDay
    ? pass('PHP and MySQL agree on the hour', $dbDay)
    : fail('PHP and MySQL agree on the hour', "php=$phpDay mysql=$dbDay");

// Six tables, and only six. If future.sql has been loaded by accident, say so:
// it is not harmful, but the database no longer matches what ledger.sql builds.
$expected = ['courses', 'enrollments', 'intakes', 'login_attempts', 'payments', 'users'];
$tables = $db->query(
    "SELECT table_name FROM information_schema.tables WHERE table_schema = DATABASE()"
)->fetchAll(PDO::FETCH_COLUMN);
$missing = array_diff($expected, $tables);
$extra   = array_diff($tables, $expected);

$missing
    ? fail('ledger tables present', 'missing: ' . implode(', ', $missing))
    : pass('ledger tables present', count($expected) . ' of ' . count($expected));

$extra
    ? skip('only the ledger tables exist', count($extra) . ' extra (future.sql loaded?)')
    : pass('only the ledger tables exist', 'no unbuilt tables');


// ---------------------------------------------------------------------------
section('Append-only enforcement');
// ---------------------------------------------------------------------------

/**
 * Reads this connection's own grants and decides whether it could damage the
 * payments table. Nothing destructive is attempted — see the note at the top.
 */
function dangerous_privileges_on_payments(PDO $db, string $schema): array
{
    $risky = ['UPDATE', 'DELETE', 'DROP', 'ALTER', 'ALL PRIVILEGES'];
    $found = [];

    foreach ($db->query('SHOW GRANTS FOR CURRENT_USER()')->fetchAll(PDO::FETCH_COLUMN) as $line) {
        if (! preg_match('/^GRANT (.+?) ON (\S+) TO /i', (string) $line, $m)) {
            continue;
        }

        $privs = strtoupper($m[1]);
        $scope = str_replace('`', '', $m[2]);

        // Does this grant cover schema.payments?
        $covers = in_array($scope, ['*.*', "$schema.*", "$schema.payments"], true);
        if (! $covers) {
            continue;
        }

        foreach ($risky as $p) {
            // USAGE is "no privileges" and never dangerous.
            if ($p !== 'ALL PRIVILEGES' && str_contains($privs, $p)) {
                $found[] = "$p on $scope";
            } elseif ($p === 'ALL PRIVILEGES' && str_contains($privs, 'ALL PRIVILEGES')) {
                $found[] = "ALL PRIVILEGES on $scope";
            }
        }
    }

    return array_unique($found);
}

$schema = (string) $db->query('SELECT DATABASE()')->fetchColumn();
$risky  = dangerous_privileges_on_payments($db, $schema);

// THE decisive check. TRUNCATE fires no trigger, so without a restricted user
// the append-only guarantee is decoration. Tested: TRUNCATE silently emptied
// the table and reset AUTO_INCREMENT, after which a reversal pointed at a
// different payment than the one it was written to cancel.
$risky
    ? fail('app user cannot damage payments', implode('; ', $risky))
    : pass('app user cannot damage payments', 'no UPDATE/DELETE/DROP/ALTER in scope');

// Triggers need the TRIGGER privilege even to be SEEN, which the app user
// deliberately lacks. Without owner credentials this is unanswerable, and
// saying so is better than guessing.
if (isset($opts['owner-user'])) {
    try {
        $cfg = require mpc_config_path();
        $owner = new PDO(
            sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4',
                $opts['owner-host'] ?? $cfg['host'], $cfg['port'] ?? 3306, $cfg['name']),
            (string) $opts['owner-user'],
            (string) ($opts['owner-pass'] ?? ''),
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
        );
        mpc_assert_append_only($owner);
        pass('append-only triggers present', 'both, verified as owner');
    } catch (Throwable $e) {
        fail('append-only triggers present', trim(explode("\n", $e->getMessage())[0]));
    }
} else {
    skip('append-only triggers present', 'needs --owner-user (TRIGGER privilege)');
}


// ---------------------------------------------------------------------------
section('Backup');
// ---------------------------------------------------------------------------

// Hard-fails rather than falling back, unlike mpc_storage_path(). A nightly
// dump of every payment MPC has taken must never land inside the web root.
$backupDir = dirname(__DIR__, 2) . '/mpc-storage';

if (! is_dir($backupDir)) {
    fail('backup directory exists', $backupDir . ' — bin/backup.php will abort');
} elseif (! is_writable($backupDir)) {
    fail('backup directory writable', $backupDir);
} else {
    pass('backup directory writable', $backupDir);
}

$mysqldump = null;
$dumpVersion = '';
if ($shellOk) {
    $candidates = [
        'mysqldump', '/usr/bin/mysqldump', '/usr/local/bin/mysqldump',
        'C:/xampp/mysql/bin/mysqldump.exe',   // local development only
    ];

    foreach ($candidates as $candidate) {
        $probe = (string) @shell_exec(escapeshellarg($candidate) . ' --version 2>&1');

        // Match the version banner, NOT the word "mysqldump". Windows' "'X' is
        // not recognized as an internal or external command" echoes the name
        // back, so a substring test on it reports success when the binary is
        // absent — which it did, on the first run of this file.
        if (preg_match('/\bVer\s+[\d.]+/i', $probe)) {
            $mysqldump   = $candidate;
            $dumpVersion = trim(explode("\n", $probe)[0]);
            break;
        }
    }
}

if (! $shellOk) {
    skip('mysqldump available', 'shell_exec disabled');
} elseif ($mysqldump === null) {
    fail('mysqldump available', 'not found — bin/backup.php needs the PHP fallback');
} else {
    pass('mysqldump available', $dumpVersion);
}

// The check that answers the restore drill without performing one. A dump that
// omits the triggers restores a table that is no longer append-only, and
// nothing would say so afterwards.
if ($mysqldump !== null && isset($opts['owner-user'])) {
    $cfg = require mpc_config_path();
    $cmd = sprintf(
        '%s --no-data --triggers --host=%s --user=%s %s %s 2>&1',
        escapeshellarg($mysqldump),
        escapeshellarg($cfg['host']),
        escapeshellarg((string) $opts['owner-user']),
        isset($opts['owner-pass']) && $opts['owner-pass'] !== ''
            ? '--password=' . escapeshellarg((string) $opts['owner-pass']) : '',
        escapeshellarg($cfg['name'])
    );
    $dump = (string) @shell_exec($cmd);

    if (trim($dump) === '') {
        fail('dump is non-empty', 'produced nothing — a zero-byte nightly dump is worse than none');
    } else {
        pass('dump is non-empty', strlen($dump) . ' bytes (schema only)');

        substr_count($dump, 'CREATE') && str_contains($dump, 'payments_no_update')
            ? pass('dump contains the triggers', 'they survive a restore')
            : fail('dump contains the triggers', 'a restore would silently drop append-only');

        // DEFINER clauses name the account that created the trigger. Restoring
        // into a database owned by a different user fails on them, which is
        // exactly the scratch-database restore a drill would use.
        if (preg_match('/DEFINER=([^\s*]+)/', $dump, $m)) {
            skip('trigger DEFINER', $m[1] . ' — restores need this account to exist');
        }
    }
} elseif ($mysqldump !== null) {
    skip('dump contains the triggers', 'needs --owner-user');
}


// ---------------------------------------------------------------------------
printf("\n  %d passed, %d failed, %d skipped\n", $pass, $fail, $skip);

if ($skip > 0 && ! isset($opts['owner-user'])) {
    echo "\n  Re-run with --owner-user=... --owner-pass=... to check the triggers\n"
       . "  and the dump. Those need privileges the application does not have,\n"
       . "  and deliberately should not have.\n";
}

exit($fail === 0 ? 0 : 1);
