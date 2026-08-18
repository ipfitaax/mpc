<?php
/**
 * Creates an office account, or resets one's password.
 *
 *     php bin/adduser.php --email=amina@mpc.so --name="Amina Cabdi"
 *     php bin/adduser.php --email=amina@mpc.so --name="Amina Cabdi" --role=admin
 *     php bin/adduser.php --email=amina@mpc.so --reset
 *     php bin/adduser.php --list
 *
 * WHY THIS EXISTS
 * admin/login.php authenticates people. Nothing creates them. Without this
 * file the first account has to be made by hand-crafting a bcrypt hash and
 * pasting SQL into phpMyAdmin — and so does every forgotten password, forever,
 * on a site with no IT staff. That is not a workflow, it is a trap.
 *
 * There is deliberately no password reset by email in v1. Reset links need a
 * mail path that works, a token table, an expiry policy and a way to fail
 * safely when the mail does not arrive. The office is five people in one room.
 * An administrator running this command is a better answer than all of that,
 * and it is honest about who is trusted.
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("bin/adduser.php is a command-line tool.\n");
}

require_once dirname(__DIR__) . '/lib/db.php';

const MIN_PASSWORD_LENGTH = 12;

/**
 * Where the login page actually is, for THIS install.
 *
 * This used to print a hardcoded /mpc/admin/login.php, which is correct on a
 * local XAMPP checkout and nowhere else. Deployed into public_html/office it is
 * a 404 - so the single instruction handed to the person who just got an
 * account sent them to a missing page, and "the app does not work" is a fair
 * conclusion to draw from that.
 *
 * There is no reliable way to learn the site's URL from the command line, so
 * this derives the path from where the file sits rather than inventing a host,
 * and says plainly when it cannot work it out.
 */
function mpc_login_url(): string
{
    $root = str_replace(chr(92), '/', dirname(__DIR__));

    if (preg_match('~/public_html(/.*)?$~', $root, $m)) {
        return rtrim($m[1] ?? '', '/') . '/admin/login.php';
    }

    return 'admin/login.php  (relative to wherever this site is served from)';
}


$opts = getopt('', ['email:', 'name:', 'role:', 'password:', 'reset', 'list', 'help']);

if (isset($opts['help']) || $opts === []) {
    echo <<<TXT

Creates an office account, or resets one's password.

  --email=...     the address they sign in with (required)
  --name="..."    their full name (required when creating)
  --role=staff    staff (default) or admin
  --reset         reset an existing account's password
  --password=...  set it directly. AVOID: this lands in your shell history.
                  Omit it and you will be prompted, or given a generated one.
  --list          show existing office accounts and stop

Only staff and admin accounts can sign in to the office tool. Students are
rows in the same table with role 'student' and cannot reach it.


TXT;
    exit(0);
}

try {
    $db = mpc_db();
} catch (Throwable $e) {
    exit('Cannot reach the database: ' . $e->getMessage() . "\n");
}


// ---------------------------------------------------------------------------
// --list
// ---------------------------------------------------------------------------
if (isset($opts['list'])) {
    $rows = $db->query(
        "SELECT id, full_name, email, role, status, last_login_at
           FROM users WHERE role IN ('staff','admin') ORDER BY id"
    )->fetchAll();

    if (! $rows) {
        echo "\nNo office accounts exist yet. Nobody can sign in.\n"
           . "Create the first one:\n"
           . "  php bin/adduser.php --email=you@mpc.so --name=\"Your Name\" --role=admin\n\n";
        exit(0);
    }

    printf("\n  %-4s %-24s %-28s %-8s %-10s %s\n", 'ID', 'NAME', 'EMAIL', 'ROLE', 'STATUS', 'LAST LOGIN');
    foreach ($rows as $r) {
        printf("  %-4s %-24s %-28s %-8s %-10s %s\n",
            $r['id'], mb_substr($r['full_name'], 0, 24), mb_substr((string) $r['email'], 0, 28),
            $r['role'], $r['status'], $r['last_login_at'] ?? 'never');
    }
    echo "\n";
    exit(0);
}


// ---------------------------------------------------------------------------
// Validate what we were given
// ---------------------------------------------------------------------------
$email = trim((string) ($opts['email'] ?? ''));
$reset = isset($opts['reset']);

if ($email === '' || ! filter_var($email, FILTER_VALIDATE_EMAIL)) {
    exit("--email is required and must be a valid address.\n");
}

$role = strtolower(trim((string) ($opts['role'] ?? 'staff')));
if (! in_array($role, ['staff', 'admin'], true)) {
    exit("--role must be 'staff' or 'admin'.\n");
}

$stmt = $db->prepare("SELECT id, full_name, role, status FROM users WHERE email = ?");
$stmt->execute([$email]);
$existing = $stmt->fetch();

if ($reset && ! $existing) {
    exit("No account with that address. Drop --reset to create one.\n");
}

if (! $reset && $existing) {
    exit("An account with that address already exists (id {$existing['id']}, "
       . "role {$existing['role']}). Use --reset to change its password.\n");
}

$name = trim((string) ($opts['name'] ?? ''));
if (! $reset && $name === '') {
    exit("--name is required when creating an account.\n");
}

// A student row already using this address would be silently promoted to staff
// by a careless --reset. Refuse, and make the operator think about it.
if ($reset && ! in_array($existing['role'], ['staff', 'admin'], true)) {
    exit("That address belongs to a '{$existing['role']}' account, not office "
       . "staff. Refusing to change it — promoting a student to staff should "
       . "be a deliberate act, not a side effect of a password reset.\n");
}


// ---------------------------------------------------------------------------
// Get a password
// ---------------------------------------------------------------------------

/**
 * Reads a line without echoing it, where the terminal allows.
 *
 * `stty` exists on the Linux host and inside Git Bash on Windows, but not in
 * every shell this might be run from. When it is unavailable the honest move is
 * NOT to fall back to reading the password in plain view — someone will be
 * standing behind the desk — but to generate one instead and show it once.
 */
function read_hidden(string $prompt): ?string
{
    // Windows has no stty, and probing for it with a POSIX redirect makes
    // cmd.exe print "The system cannot find the path specified" straight to the
    // terminal — output PHP cannot suppress, because it comes from the child
    // process, not from PHP. Don't probe; just don't claim to hide input here.
    // The host is Linux, which is where this actually matters.
    if (PHP_OS_FAMILY === 'Windows' || ! function_exists('shell_exec')) {
        return null;
    }

    $sttyPath = trim((string) @shell_exec('command -v stty 2>/dev/null'));
    if ($sttyPath === '') {
        return null;
    }

    $before = trim((string) @shell_exec('stty -g 2>/dev/null'));
    if ($before === '') {
        return null;
    }

    echo $prompt;
    @shell_exec('stty -echo 2>/dev/null');
    $line = fgets(STDIN);
    @shell_exec('stty ' . escapeshellarg($before) . ' 2>/dev/null');
    echo "\n";

    return $line === false ? null : rtrim($line, "\r\n");
}

/** Readable, unambiguous, and long enough that nobody is tempted to shorten it. */
function generate_password(): string
{
    // Crockford base32 minus vowels: no I/L/O/U to misread, no accidental words.
    $alphabet = '23456789ABCDEFGHJKMNPQRSTVWXYZ';
    $out = '';
    for ($i = 0; $i < 16; $i++) {
        $out .= $alphabet[random_int(0, strlen($alphabet) - 1)];
        if ($i % 4 === 3 && $i < 15) {
            $out .= '-';
        }
    }
    return $out;
}

$generated = false;

if (isset($opts['password'])) {
    $password = (string) $opts['password'];
    echo "\nWARNING: a password passed with --password is now in your shell\n"
       . "history and, on a shared machine, in the process list. Change it\n"
       . "soon, or use the prompt instead.\n";
} else {
    $password = read_hidden('New password (leave blank to generate one): ');

    if ($password === null || $password === '') {
        $password  = generate_password();
        $generated = true;
    } else {
        $again = read_hidden('Type it again: ');
        if ($again !== $password) {
            exit("Those did not match. Nothing was changed.\n");
        }
    }
}

if (! $generated && mb_strlen($password) < MIN_PASSWORD_LENGTH) {
    exit('Password must be at least ' . MIN_PASSWORD_LENGTH . " characters.\n");
}


// ---------------------------------------------------------------------------
// Write it
// ---------------------------------------------------------------------------
$hash = password_hash($password, PASSWORD_DEFAULT);

try {
    if ($reset) {
        $db->prepare("UPDATE users SET password_hash = ? WHERE id = ?")
           ->execute([$hash, $existing['id']]);
        $who = $existing['full_name'];
        $id  = $existing['id'];
        echo "\nPassword reset for {$who} <{$email}>.\n";
    } else {
        $db->prepare(
            "INSERT INTO users (full_name, email, password_hash, role, status)
             VALUES (?, ?, ?, ?, 'active')"
        )->execute([$name, $email, $hash, $role]);
        $id = $db->lastInsertId();
        echo "\nCreated {$role} account for {$name} <{$email}> (id {$id}).\n";
    }
} catch (PDOException $e) {
    // The application user holds INSERT on the schema and UPDATE on users, and
    // nothing else. If those grants are wrong this is where it shows, so say
    // what to check rather than printing a driver message and stopping.
    exit("\nCould not write the account: " . $e->getMessage() . "\n"
       . "If this is a privileges error, the application user needs INSERT on\n"
       . "the database and UPDATE on `users`. See lib/config.example.php.\n");
}

if ($generated) {
    echo "\n  Password:  {$password}\n\n"
       . "  This is shown once and is not stored anywhere in readable form.\n"
       . "  Write it down now, hand it over in person, and have them change it.\n";
}

// Anyone who has to be told twice that a password is temporary will not change
// it. Say it where it cannot be missed.
if ($generated || isset($opts['password'])) {
    echo "
  Sign in at: " . mpc_login_url() . "
";
}

echo "\n";
exit(0);
