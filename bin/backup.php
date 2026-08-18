<?php
/**
 * Nightly database backup.
 *
 *     php bin/backup.php                      # credentials from the config file
 *     php bin/backup.php --keep=14
 *     php bin/backup.php --email=info@mpc.so  # opt-in offsite copy
 *
 * cPanel cron, once a night:
 *     /usr/local/bin/php /home/USER/public_html/bin/backup.php
 *
 * IT MUST RUN AS THE DATABASE OWNER, not as the application user, and this is
 * not a preference. Dumping triggers requires the TRIGGER privilege, which the
 * application account deliberately does not have. A backup taken as the
 * application user would restore a payments table with no append-only triggers
 * on it — a backup that quietly destroys the guarantee it was taken to
 * preserve. Owner credentials go in the config file as `owner_user` and
 * `owner_pass`, NOT on this command line, because a cron line is readable by
 * anyone who can list the crontab.
 *
 * THIS SCRIPT HARD-FAILS. It never writes somewhere more convenient.
 * `mpc_storage_path()` in api/shared.php falls back into the web root on
 * purpose, because a lost enquiry is worse than a guarded one. The opposite is
 * true here: a dump of every payment MPC has ever taken, sitting under
 * public_html behind one .htaccess, is worse than no dump at all — and the
 * fallback would be the SUCCESS path, so nothing would error and nobody would
 * find out.
 *
 * A FAILURE HERE TALKS TO CRON, AND CRON TALKS TO NOBODY. That is the honest
 * weakness of this design. Non-zero exit and stderr are what cPanel emails, if
 * it is configured to; --email is what makes success visible too. Check that
 * backups are arriving. Do not assume.
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("bin/backup.php is a command-line tool.\n");
}

require_once dirname(__DIR__) . '/lib/db.php';

$opts = getopt('', ['keep:', 'dir:', 'email:', 'owner-user:', 'owner-pass:', 'quiet', 'php']);
$keep  = max(1, (int) ($opts['keep'] ?? 7));
$quiet = isset($opts['quiet']);

function say(string $line): void
{
    global $quiet;
    if (! $quiet) {
        echo $line . "\n";
    }
}

/** Everything that goes wrong here exits non-zero with the reason on stderr. */
function die_with(string $why): never
{
    fwrite(STDERR, "backup FAILED: " . $why . "\n");
    exit(1);
}


// ---------------------------------------------------------------------------
// Where it goes. No fallback. See the header.
// ---------------------------------------------------------------------------
$dir = rtrim((string) ($opts['dir'] ?? dirname(__DIR__, 2) . '/mpc-storage'), '/\\');

if (! is_dir($dir)) {
    die_with("backup directory does not exist: $dir\n"
        . "  Create it ALONGSIDE public_html, not inside it, and make it writable.\n"
        . "  This script will not write anywhere else.");
}

if (! is_writable($dir)) {
    die_with("backup directory is not writable: $dir");
}

// Refuse to write inside the application directory under any circumstances,
// including when someone passes --dir. This is the one mistake that cannot be
// undone by noticing it later: once a dump has been served over HTTP once, it
// has been served.
$appDir  = realpath(dirname(__DIR__));
$realDir = realpath($dir);
if ($appDir && $realDir && str_starts_with($realDir . DIRECTORY_SEPARATOR, $appDir . DIRECTORY_SEPARATOR)) {
    die_with("refusing to write backups inside the application directory ($realDir).\n"
        . "  That path is reachable over HTTP. Use a directory above the web root.");
}


// ---------------------------------------------------------------------------
// Credentials. Owner, for the reason in the header.
// ---------------------------------------------------------------------------
try {
    $cfg = require mpc_config_path();
} catch (Throwable $e) {
    die_with($e->getMessage());
}

$user = (string) ($opts['owner-user'] ?? $cfg['owner_user'] ?? '');
$pass = (string) ($opts['owner-pass'] ?? $cfg['owner_pass'] ?? '');

if ($user === '') {
    die_with("no owner credentials.\n"
        . "  Add 'owner_user' and 'owner_pass' to the config file — not to the\n"
        . "  cron line, which anyone who can list the crontab can read.\n"
        . "  Dumping triggers needs the TRIGGER privilege the application user\n"
        . "  does not have, so this cannot run as the application user.");
}

$stamp = date('Y-m-d-His');
$base  = $dir . '/mpc-' . $stamp . '.sql';


// ---------------------------------------------------------------------------
// Take the dump. mysqldump if it is there, PHP if it is not.
// ---------------------------------------------------------------------------
$shellOk = function_exists('shell_exec')
    && ! in_array('shell_exec', array_map('trim', explode(',', (string) ini_get('disable_functions'))), true);

// --php forces the fallback. Not a test-only hook: it is the only way to find
// out whether the fallback works BEFORE the day a host upgrade disables
// shell_exec and you need it.
$forcePhp = isset($opts['php']);

$mysqldump = null;
if ($shellOk && ! $forcePhp) {
    foreach (['mysqldump', '/usr/bin/mysqldump', '/usr/local/bin/mysqldump',
              'C:/xampp/mysql/bin/mysqldump.exe'] as $candidate) {
        if (preg_match('/\bVer\s+[\d.]+/i', (string) @shell_exec(escapeshellarg($candidate) . ' --version 2>&1'))) {
            $mysqldump = $candidate;
            break;
        }
    }
}

if ($mysqldump !== null) {
    // --triggers is on by default, but say it out loud: silently losing them
    // is the failure this whole file is arranged around.
    // --single-transaction so the dump is consistent without locking the office
    // out of the tool while it runs.
    $cmd = sprintf(
        '%s --host=%s --port=%d --user=%s %s --single-transaction --triggers --routines '
        . '--default-character-set=utf8mb4 %s 2>&1',
        escapeshellarg($mysqldump),
        escapeshellarg((string) $cfg['host']),
        (int) ($cfg['port'] ?? 3306),
        escapeshellarg($user),
        $pass !== '' ? '--password=' . escapeshellarg($pass) : '',
        escapeshellarg((string) $cfg['name'])
    );

    $sql = (string) @shell_exec($cmd);
    $how = 'mysqldump';
} else {
    say('mysqldump not available — using the PHP fallback.');
    $sql = php_dump($cfg, $user, $pass);
    $how = 'php';
}


// ---------------------------------------------------------------------------
// Is it real? A zero-byte nightly dump is worse than none, because it looks
// like a backup in a directory listing.
// ---------------------------------------------------------------------------
if (trim($sql) === '') {
    die_with("the dump was empty. Nothing has been written.");
}

// mysqldump writes its errors to stdout when 2>&1, so an "error" that produced
// no CREATE TABLE is a failure wearing the costume of a dump file.
if (! str_contains($sql, 'CREATE TABLE') || ! str_contains($sql, 'payments')) {
    die_with("the dump does not contain the payments table. First line was:\n  "
        . trim(explode("\n", trim($sql))[0]));
}

// The check that gives this file its point. A dump without the triggers
// restores a ledger that is no longer append-only, and nothing afterwards
// would say so.
$hasTriggers = str_contains($sql, 'payments_no_update') && str_contains($sql, 'payments_no_delete');
if (! $hasTriggers) {
    die_with("the dump does not contain the append-only triggers.\n"
        . "  Restoring it would produce an editable payments table.\n"
        . "  This usually means the account used lacks the TRIGGER privilege.");
}


// ---------------------------------------------------------------------------
// Write it, compressed if we can, readable only by its owner.
// ---------------------------------------------------------------------------
$path = $base;
if (function_exists('gzencode')) {
    $path = $base . '.gz';
    $body = gzencode($sql, 9);
} else {
    $body = $sql;
}

if (@file_put_contents($path, $body) === false) {
    die_with("could not write $path");
}

// Student names, phone numbers and payment amounts. Not world-readable.
@chmod($path, 0600);

$size = filesize($path) ?: 0;
say(sprintf('Wrote %s (%s, %s, triggers included)', basename($path), format_bytes($size), $how));


// ---------------------------------------------------------------------------
// Rotation. Keep the most recent $keep, delete the rest.
// ---------------------------------------------------------------------------
$existing = glob($dir . '/mpc-*.sql*') ?: [];
usort($existing, static fn($a, $b) => filemtime($b) <=> filemtime($a));

$removed = 0;
foreach (array_slice($existing, $keep) as $old) {
    if (@unlink($old)) {
        $removed++;
    }
}
say(sprintf('Keeping %d, removed %d older.', min($keep, count($existing)), $removed));


// ---------------------------------------------------------------------------
// Offsite copy. Opt-in, because the alternative is emailing student data
// around on a schedule nobody asked for.
// ---------------------------------------------------------------------------
if (isset($opts['email'])) {
    $to = (string) $opts['email'];

    // Keep the From on an mpc.so address. README records why at length: that
    // domain's SPF authorises this host, and moving the From elsewhere brought
    // outright rejection from Gmail.
    $from     = 'info@mpc.so';
    $boundary = 'mpc-' . bin2hex(random_bytes(12));

    $headers = "From: MPC Backup <$from>\r\n"
             . "MIME-Version: 1.0\r\n"
             . "Content-Type: multipart/mixed; boundary=\"$boundary\"\r\n";

    $body = "--$boundary\r\nContent-Type: text/plain; charset=utf-8\r\n\r\n"
          . "Database backup from " . date('Y-m-d H:i') . " (Africa/Mogadishu).\n"
          . basename($path) . ", " . format_bytes($size) . ", taken with $how.\n\n"
          . "This attachment contains students' names, phone numbers and payment\n"
          . "records. Keep it somewhere you would keep the paper register.\n\r\n"
          . "--$boundary\r\n"
          . "Content-Type: application/octet-stream; name=\"" . basename($path) . "\"\r\n"
          . "Content-Transfer-Encoding: base64\r\n"
          . "Content-Disposition: attachment; filename=\"" . basename($path) . "\"\r\n\r\n"
          . chunk_split(base64_encode((string) file_get_contents($path))) . "\r\n"
          . "--$boundary--";

    // Mail failing does NOT fail the backup. The dump is already safely on
    // disk, which is the same save-then-notify order api/enquiry.php uses and
    // for the same reason: the thing that matters already happened.
    @mail($to, 'MPC database backup ' . date('Y-m-d'), $body, $headers)
        ? say("Emailed a copy to $to.")
        : fwrite(STDERR, "warning: the backup was written but could not be emailed to $to\n");
}

exit(0);


// ---------------------------------------------------------------------------
// Fallback dump, for hosts with shell_exec disabled. Common on shared hosting,
// and selftest.php reports it, but reporting it is not the same as surviving
// it.
// ---------------------------------------------------------------------------
function php_dump(array $cfg, string $user, string $pass): string
{
    $pdo = new PDO(
        sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4',
            $cfg['host'], $cfg['port'] ?? 3306, $cfg['name']),
        $user, $pass,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_EMULATE_PREPARES => false]
    );

    $out = "-- MPC backup taken by bin/backup.php without mysqldump\n"
         . '-- ' . date('c') . "\n"
         . "SET NAMES utf8mb4;\nSET FOREIGN_KEY_CHECKS=0;\n\n";

    $tables = $pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN);

    foreach ($tables as $table) {
        $create = $pdo->query("SHOW CREATE TABLE `$table`")->fetch(PDO::FETCH_NUM)[1];
        $out .= "DROP TABLE IF EXISTS `$table`;\n$create;\n\n";

        $rows = $pdo->query("SELECT * FROM `$table`");
        foreach ($rows as $row) {
            $values = array_map(
                static fn($v) => $v === null ? 'NULL' : $pdo->quote((string) $v),
                array_values($row)
            );
            $cols = '`' . implode('`,`', array_keys($row)) . '`';
            $out .= "INSERT INTO `$table` ($cols) VALUES (" . implode(',', $values) . ");\n";
        }
        $out .= "\n";
    }

    // The triggers, explicitly. Losing these is the failure mode this file
    // exists to prevent, and SHOW TRIGGERS needs the TRIGGER privilege — which
    // is the same reason this must run as the owner.
    foreach ($pdo->query('SHOW TRIGGERS') as $t) {
        $out .= sprintf(
            "DROP TRIGGER IF EXISTS `%s`;\nDELIMITER ;;\nCREATE TRIGGER `%s` %s %s ON `%s` FOR EACH ROW %s;;\nDELIMITER ;\n\n",
            $t['Trigger'], $t['Trigger'], $t['Timing'], $t['Event'], $t['Table'], $t['Statement']
        );
    }

    return $out . "SET FOREIGN_KEY_CHECKS=1;\n";
}

function format_bytes(int $n): string
{
    return $n >= 1048576 ? round($n / 1048576, 1) . ' MB'
        : ($n >= 1024 ? round($n / 1024) . ' KB' : $n . ' B');
}
