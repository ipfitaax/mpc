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
// Every directory that is not a page needs its own deny rule. database/ was
// missed until the ledger reached a real host and someone requested the URL:
// ledger.sql was being served, locally too, for as long as it had existed.
foreach (['lib', 'bin', 'database'] as $dir) {
    is_readable(dirname(__DIR__) . '/' . $dir . '/.htaccess')
        ? pass("$dir/.htaccess present", 'deny rule shipped')
        : fail("$dir/.htaccess present", "MISSING — $dir/ may be web-readable");
}


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

// Fourteen tables, and only fourteen. If future.sql has been loaded by accident,
// say so: it is not harmful in itself, but the database no longer matches what
// ledger.sql builds — and for the six assessment tables it is actively
// misleading, because future.sql creates them in the OLD shape. A database in
// that state loads the quiz screens and then dies with "Unknown column
// 'created_by'" on the first save. See migrations/004-assessment.sql, which
// opens with how to check for and clear exactly that.
$expected = ['courses', 'enrollments', 'intakes', 'intake_instructors',
             'login_attempts', 'payments', 'quizzes', 'quiz_answers',
             'quiz_attempts', 'quiz_options', 'quiz_questions',
             'social_accounts', 'users', 'verify_attempts'];
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
 * Reads this connection's own grants and decides whether it could damage a
 * given table. Nothing destructive is attempted — see the note at the top.
 *
 * $risky is passed in rather than fixed, because the answer differs per table.
 * `payments` must not be UPDATE-able at all. `quiz_attempts` must be — the
 * score is written by an UPDATE at submission — while DELETE on it is exactly
 * as forbidden. A single hardcoded list could only be right for one of them.
 */
function dangerous_privileges_on(PDO $db, string $schema, string $table, array $risky): array
{
    $found = [];

    foreach ($db->query('SHOW GRANTS FOR CURRENT_USER()')->fetchAll(PDO::FETCH_COLUMN) as $line) {
        if (! preg_match('/^GRANT (.+?) ON (\S+) TO /i', (string) $line, $m)) {
            continue;
        }

        $privs = strtoupper($m[1]);
        // Strip the backticks AND the backslashes. SHOW GRANTS escapes an
        // underscore because it is a LIKE wildcard, so a database named
        // mpcsispq_mpc comes back as `mpcsispq\_mpc`. Comparing that against
        // SELECT DATABASE() never matched, and this function reported
        // "nothing dangerous" for an account holding database-wide UPDATE and
        // DELETE. Found on the real host: local development used a database
        // with no underscore in its name, so the bug could not appear there.
        $scope = str_replace(array('`', chr(92)), '', $m[2]);   // chr(92) is a backslash

        // Does this grant cover schema.$table?
        $covers = in_array($scope, ['*.*', "$schema.*", "$schema.$table"], true);
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
$risky  = dangerous_privileges_on(
    $db, $schema, 'payments', ['UPDATE', 'DELETE', 'DROP', 'ALTER', 'ALL PRIVILEGES']
);

// THE decisive check. TRUNCATE fires no trigger, so without a restricted user
// the append-only guarantee is decoration. Tested: TRUNCATE silently emptied
// the table and reset AUTO_INCREMENT, after which a reversal pointed at a
// different payment than the one it was written to cancel.
$risky
    ? fail('app user cannot damage payments', implode('; ', $risky))
    : pass('app user cannot damage payments', 'no UPDATE/DELETE/DROP/ALTER in scope');

// The same question about grades. A quiz_attempts row is the record that a named
// student sat an exam and scored what they scored, and the application creates
// one but must never be able to destroy one.
//
// UPDATE is absent from the risky list on purpose and is not an oversight: the
// score columns are written by an UPDATE at submission, so an app that cannot
// UPDATE this table cannot mark anything. DELETE is what must not be there.
//
// Weaker than the payments check by design — there is no trigger behind it, so
// the owner can still delete an attempt. A mistaken grade is a smaller
// emergency than a mistaken payment, and the grant is what stops the
// application doing it by accident, which is the failure that actually happens.
if (in_array('quiz_attempts', $tables, true)) {
    $riskyGrades = dangerous_privileges_on(
        $db, $schema, 'quiz_attempts', ['DELETE', 'DROP', 'ALTER', 'ALL PRIVILEGES']
    );

    $riskyGrades
        ? fail('app user cannot delete a grade', implode('; ', $riskyGrades))
        : pass('app user cannot delete a grade', 'no DELETE/DROP/ALTER in scope');
} else {
    skip('app user cannot delete a grade', 'quiz_attempts not present');
}

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
// `backup_dir` in the config wins. The relative default assumes the repo IS
// public_html; deployed into a subdirectory it resolves INSIDE the web root,
// which is the one place a dump of every payment must never land.
$cfgBackup = @include mpc_config_path();
$backupDir = $cfgBackup['backup_dir'] ?? (dirname(__DIR__, 2) . '/mpc-storage');

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
        // Two banner shapes, and they are not the same across releases:
        //   MariaDB 10.4  mysqldump  Ver 10.19 Distrib 10.4.32-MariaDB
        //   MariaDB 11.4  /usr/bin/mysqldump from 11.4.12-MariaDB, client 10.19
        // Only the first carries "Ver". Matching just that reported mysqldump
        // as MISSING on the production host, where it is present and working.
        if (preg_match('/Ver\s+[\d.]+|from\s+[\d.]+-|Distrib\s+[\d.]+/i', $probe)) {
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
