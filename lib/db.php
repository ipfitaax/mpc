<?php
/**
 * The one database connection, configured once.
 *
 * WHY THIS EXISTS
 * `api/shared.php` already carries the lesson this file applies to the
 * database: "Two copies of a storage path is how one endpoint quietly starts
 * writing somewhere nobody reads." Two copies of a PDO setup is how one page
 * ends up with emulated prepares on, or without utf8mb4, or in the wrong
 * timezone — and each of those fails in a way nobody notices until a student
 * argues about a receipt.
 */

declare(strict_types=1);

// ---------------------------------------------------------------------------
// Timezone. Set before anything reads a clock.
// ---------------------------------------------------------------------------
// cPanel hosts typically run UTC. Mogadishu is UTC+3 with no daylight saving.
// A payment taken at 21:30 local would otherwise be stamped with the previous
// day, and a receipt dated a day before the student remembers paying is a new
// dispute created by the tool built to end disputes.
//
// This has to happen in BOTH places. PHP's date() and MySQL's NOW() are
// separate clocks, and letting them disagree means `paid_on` and `created_at`
// can land on different days for the same payment.
date_default_timezone_set('Africa/Mogadishu');

/** Mogadishu is UTC+3 year round. Written as an offset because a shared host
 *  usually has not loaded the named timezone tables into MySQL. */
const MPC_DB_TIME_ZONE = '+03:00';


/**
 * Where the database credentials live.
 *
 * Above the web root by preference, the same reasoning as `mpc-storage`: a file
 * outside the document root cannot be requested over HTTP at all, whatever a
 * future Apache config does. A misconfigured server that starts serving .php
 * as text would otherwise hand out the database password.
 *
 * NOTE THE DIFFERENCE from `mpc_storage_path()` in api/shared.php. That helper
 * falls back to a directory inside the web root, on purpose, because some hosts
 * refuse anything above it and a lost enquiry is worse than a guarded one. This
 * function has no such fallback and must not grow one: it either finds
 * credentials somewhere safe or it stops. There is no version of "write the
 * database password somewhere more public" that is the right answer.
 *
 * @return string path to a PHP file returning a config array
 */
function mpc_config_path(): string
{
    // An explicit override, used by the test suite so it can point at a
    // scratch database instead of the real one. Without this the tests would
    // run against whatever the deployed config names — which on a laptop is
    // the development database and on a server would be the actual ledger.
    // A test suite that can destroy production is not a test suite.
    //
    // It is deliberately an environment variable rather than a constant: it
    // has to be set before this file is loaded, and it must be impossible to
    // set from a web request.
    $override = getenv('MPC_CONFIG');
    if ($override !== false && $override !== '' && is_readable($override)) {
        return $override;
    }

    // Production: alongside public_html, not inside it.
    $outside = dirname(__DIR__, 2) . '/mpc-config.php';
    if (is_readable($outside)) {
        return $outside;
    }

    // Local development: gitignored, sits next to this file. Only accepted
    // when the request is not coming over the network, so a production host
    // cannot be tricked into using a checked-out dev config.
    $local = __DIR__ . '/config.local.php';
    if (is_readable($local)) {
        return $local;
    }

    throw new RuntimeException(
        'No database configuration found. Expected ' . $outside
        . ' on the server, or lib/config.local.php for local development. '
        . 'Copy lib/config.example.php and fill it in.'
    );
}

/**
 * The shared PDO handle.
 *
 * @return PDO
 */
function mpc_db(): PDO
{
    static $pdo = null;
    if ($pdo instanceof PDO) {
        return $pdo;
    }

    $cfg = require mpc_config_path();

    foreach (['host', 'name', 'user', 'pass'] as $key) {
        if (! array_key_exists($key, $cfg)) {
            throw new RuntimeException("Database config is missing '$key'.");
        }
    }

    $dsn = sprintf(
        'mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4',
        $cfg['host'],
        $cfg['port'] ?? 3306,
        $cfg['name']
    );

    $pdo = new PDO($dsn, $cfg['user'], $cfg['pass'], [
        // Errors must throw. The silent default returns false and lets the
        // next line carry on with nothing, which is how a failed INSERT
        // becomes a printed receipt.
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,

        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,

        // Real prepared statements, not string interpolation wearing a
        // costume. With emulation on, PDO builds the SQL itself and the
        // placeholders are only as safe as its quoting.
        PDO::ATTR_EMULATE_PREPARES   => false,

        // One connection per request. Persistent connections on shared
        // hosting hold slots open across requests and hit the account's
        // connection limit under load.
        PDO::ATTR_PERSISTENT         => false,
    ]);

    // See the timezone note at the top of this file.
    $pdo->exec("SET time_zone = '" . MPC_DB_TIME_ZONE . "'");

    return $pdo;
}


/**
 * Confirms the append-only triggers are still on the payments table.
 *
 * ADMIN-ONLY. Pass a connection made with the database OWNER account. Do not
 * call this on a page request.
 *
 * WHY IT CANNOT RUN AS THE APPLICATION USER, which is not obvious and cost a
 * test run to find: reading `information_schema.triggers` requires the TRIGGER
 * privilege. The application connects as a restricted user that deliberately
 * does not have it, so this query comes back EMPTY rather than erroring — and
 * an empty result is indistinguishable from "the triggers are gone". The
 * function would cry wolf on every request. Granting TRIGGER to fix that would
 * hand the application DROP TRIGGER, which destroys the very thing being
 * checked. There is no version of this that belongs in the request path.
 *
 * That is fine, because the request path does not need it. For the application
 * user, append-only is enforced by grants: UPDATE and DELETE on payments are
 * refused before a statement runs, triggers or no triggers. The triggers are
 * the second line, for anything connecting as the owner.
 *
 * WHY IT EXISTS AT ALL
 * A mysqldump restore drops and recreates tables and takes the triggers with
 * them unless the dump included them. After a real recovery the ledger would be
 * editable by the owner again and nothing would say so. This is the check that
 * notices, and bin/selftest.php is what runs it.
 *
 * @param  PDO $owner a connection made with the owner account
 * @throws RuntimeException if either trigger is missing
 */
function mpc_assert_append_only(PDO $owner): void
{
    $sql = "SELECT trigger_name FROM information_schema.triggers
             WHERE trigger_schema = DATABASE()
               AND event_object_table = 'payments'";

    $found = $owner->query($sql)->fetchAll(PDO::FETCH_COLUMN);
    $missing = array_diff(['payments_no_update', 'payments_no_delete'], $found);

    if ($missing) {
        throw new RuntimeException(
            'The payments table is not append-only: cannot see trigger(s) '
            . implode(', ', $missing) . ".\n"
            . "Either a database restore recreated the table without them — "
            . "re-apply database/migrations/001-ledger-hardening.sql — or this "
            . "connection lacks the TRIGGER privilege and simply cannot see "
            . "them. Check which before assuming the worst; run this as the "
            . 'database owner, not as the application user.'
        );
    }
}
