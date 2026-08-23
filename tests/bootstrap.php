<?php
/**
 * Builds a scratch database, then points the application at it.
 *
 * THE FIRST THING THIS FILE DOES IS REFUSE TO RUN AGAINST THE REAL DATABASE.
 * These tests insert, reverse and assert their way through the ledger, and
 * several of them exist to confirm that destructive things are refused — which
 * means the suite has to be pointed somewhere it is safe to be wrong. A test
 * run that can damage production is worse than no tests, because it will be
 * run without thinking about it.
 *
 * The scratch database is created from database/ledger.sql, not from a fixture
 * dump. That means every run also tests that ledger.sql itself still loads, and
 * that the triggers and constraints the suite asserts on are the ones a fresh
 * install would actually get.
 *
 * Two connections are used throughout:
 *   OWNER — creates the database, loads the schema, and tears fixtures down.
 *           Only the owner can delete a payment, and only by dropping a trigger
 *           first, which is exactly the power the application must not have.
 *   APP   — what the code under test runs as. SELECT and INSERT, plus UPDATE on
 *           a couple of tables. If a test passes as owner but fails as app, the
 *           grants are wrong, and that is a finding rather than a nuisance.
 */

declare(strict_types=1);

// Buffer everything for the whole run.
//
// PHPUnit prints its progress dots as it goes, which makes headers_sent() true,
// and session_regenerate_id() then refuses to work — so every test that signs
// somebody in would fail for a reason that has nothing to do with the code.
//
// The fix belongs HERE and not in lib/auth.php. Making the regeneration
// conditional on headers_sent() would silently weaken the real defence against
// session fixation on the one screen that writes money, in order to make a test
// runner happy. The test environment bends; the production path does not.
ob_start();

require __DIR__ . '/../vendor/autoload.php';

const MPC_TEST_DB   = 'mpc_db_test';
const MPC_TEST_USER = 'mpc_app_test';
const MPC_TEST_PASS = 'test-only-not-a-secret';

// Read the owner credentials from whatever config this machine already has,
// rather than inventing a second place to put them.
$devConfigPath = getenv('MPC_DEV_CONFIG') ?: __DIR__ . '/../lib/config.local.php';

if (! is_readable($devConfigPath)) {
    fwrite(STDERR, "Cannot find a config to borrow owner credentials from.\n"
        . "Expected $devConfigPath. Copy lib/config.example.php and fill it in,\n"
        . "including owner_user and owner_pass.\n");
    exit(1);
}

$dev = require $devConfigPath;

if (empty($dev['owner_user'])) {
    fwrite(STDERR, "The config has no 'owner_user'. The suite needs owner rights to\n"
        . "create a scratch database and to load the schema.\n");
    exit(1);
}

// ---------------------------------------------------------------------------
// Refuse to run anywhere near the real database.
// ---------------------------------------------------------------------------
if (MPC_TEST_DB === ($dev['name'] ?? '')) {
    fwrite(STDERR, "The test database and the configured database are the same name.\n"
        . "Refusing to run.\n");
    exit(1);
}

$ownerDsn = sprintf('mysql:host=%s;port=%d;charset=utf8mb4',
    $dev['host'], $dev['port'] ?? 3306);

try {
    $owner = new PDO($ownerDsn, $dev['owner_user'], (string) ($dev['owner_pass'] ?? ''), [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
} catch (Throwable $e) {
    fwrite(STDERR, "Cannot connect as the owner: " . $e->getMessage() . "\n");
    exit(1);
}

// ---------------------------------------------------------------------------
// Build it fresh from the real schema file.
// ---------------------------------------------------------------------------
$owner->exec('DROP DATABASE IF EXISTS ' . MPC_TEST_DB);
$owner->exec('CREATE DATABASE ' . MPC_TEST_DB . ' CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
$owner->exec('USE ' . MPC_TEST_DB);

$schema = file_get_contents(__DIR__ . '/../database/ledger.sql');
if ($schema === false) {
    fwrite(STDERR, "Cannot read database/ledger.sql\n");
    exit(1);
}

// STRIP COMMENTS FIRST, THEN SPLIT. The order is the whole trick, and getting
// it wrong failed twice in two different ways.
//
// Splitting first and skipping comment-only chunks throws away nearly every
// statement, because ledger.sql documents each table in a block directly above
// it — the chunk holding `CREATE TABLE payments` starts with twenty lines of
// prose. Splitting first and stripping comments afterwards is worse: this file
// contains the line
//
//     -- Set only on a reversal. The application requires a reason alongside it;
//
// and that trailing semicolon splits the payments table in half. The symptom
// was a syntax error twenty-six lines into a statement that should have been
// forty.
//
// So: remove every comment from the whole file, then split. ledger.sql has no
// stored procedure bodies and its triggers are single-statement, so no
// DELIMITER handling is needed. This would break on a `--` inside a string
// literal; there is none, and a seed value containing one would fail loudly
// here rather than silently.
$schema = preg_replace('/--[^\n]*/', '', $schema) ?? '';

foreach (explode(';', $schema) as $chunk) {
    $statement = trim($chunk);
    if ($statement === '') {
        continue;
    }
    try {
        $owner->exec($statement);
    } catch (PDOException $e) {
        fwrite(STDERR, "Failed loading ledger.sql:\n  " . $e->getMessage()
            . "\n  near: " . substr($statement, 0, 120) . "\n");
        exit(1);
    }
}

// ---------------------------------------------------------------------------
// The restricted user the application runs as. Same grants as production.
// ---------------------------------------------------------------------------
$owner->exec("DROP USER IF EXISTS '" . MPC_TEST_USER . "'@'localhost'");
$owner->exec("CREATE USER '" . MPC_TEST_USER . "'@'localhost' IDENTIFIED BY '" . MPC_TEST_PASS . "'");
$owner->exec('GRANT SELECT, INSERT ON ' . MPC_TEST_DB . ".* TO '" . MPC_TEST_USER . "'@'localhost'");
$owner->exec('GRANT UPDATE, DELETE ON ' . MPC_TEST_DB . ".users TO '" . MPC_TEST_USER . "'@'localhost'");
$owner->exec('GRANT UPDATE ON ' . MPC_TEST_DB . ".enrollments TO '" . MPC_TEST_USER . "'@'localhost'");
$owner->exec('GRANT DELETE ON ' . MPC_TEST_DB . ".verify_attempts TO '" . MPC_TEST_USER . "'@'localhost'");
$owner->exec('FLUSH PRIVILEGES');

// ---------------------------------------------------------------------------
// Point the application at it.
// ---------------------------------------------------------------------------
$testConfig = sys_get_temp_dir() . '/mpc-test-config-' . getmypid() . '.php';
file_put_contents($testConfig, "<?php return " . var_export([
    'host' => $dev['host'],
    'port' => $dev['port'] ?? 3306,
    'name' => MPC_TEST_DB,
    'user' => MPC_TEST_USER,
    'pass' => MPC_TEST_PASS,
], true) . ";\n");

putenv('MPC_CONFIG=' . $testConfig);
register_shutdown_function(static fn() => @unlink($testConfig));

// Started before any output so mpc_session_start() finds an active session and
// never tries to set cookie parameters, which CLI cannot do once PHPUnit has
// printed its first character.
if (session_status() !== PHP_SESSION_ACTIVE) {
    @session_start();
}

require_once __DIR__ . '/../lib/auth.php';
require_once __DIR__ . '/../lib/ledger.php';
require_once __DIR__ . '/../lib/oauth.php';

/** The owner connection, for fixtures and for asserting on things the app cannot see. */
function test_owner(): PDO
{
    static $pdo = null;
    if (! $pdo) {
        global $dev;
        $pdo = new PDO(
            sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4',
                $dev['host'], $dev['port'] ?? 3306, MPC_TEST_DB),
            $dev['owner_user'], (string) ($dev['owner_pass'] ?? ''),
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]
        );
    }

    return $pdo;
}

/**
 * Empties the ledger between tests.
 *
 * The order matters and it cost two debugging sessions to learn: reversal rows
 * reference the payments they cancel through a self-FK with RESTRICT, so a bulk
 * DELETE FROM payments fails. And the delete trigger has to come off first,
 * then go straight back on — a teardown that leaves it dropped makes every
 * later append-only assertion pass for the wrong reason.
 */
function test_reset(): void
{
    $o = test_owner();
    $o->exec('DROP TRIGGER IF EXISTS payments_no_delete');
    $o->exec('DELETE FROM payments WHERE reverses_payment_id IS NOT NULL');
    $o->exec('DELETE FROM payments');
    $o->exec('DELETE FROM enrollments');
    $o->exec('DELETE FROM intakes');
    $o->exec('DELETE FROM users');
    $o->exec('DELETE FROM login_attempts');
    $o->exec('DELETE FROM verify_attempts');
    $o->exec("CREATE TRIGGER payments_no_delete BEFORE DELETE ON payments
              FOR EACH ROW SIGNAL SQLSTATE '45000'
              SET MESSAGE_TEXT = 'payments is append-only: a payment row is never deleted'");

    $_SESSION = [];
}
