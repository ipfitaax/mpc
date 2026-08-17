<?php
/**
 * Sessions, CSRF, and getting into the office tool.
 *
 * WHY ONE FILE
 * Four entry points need the same opening lines, and the dangerous failure is
 * not four copies of a header — it is adding a check to the guard later and
 * missing one of the four. That page then looks exactly like a protected page
 * and is not one. Same reasoning as api/shared.php.
 *
 * WHAT THIS PROTECTS
 * A form that writes money, on the public internet, used by a handful of staff
 * on a shared office machine. Three separate holes, none of which needs a
 * sophisticated attacker:
 *
 *   CSRF     another tab makes the staff member's browser submit the payment
 *            form without them knowing
 *   session  the cookie is readable by script, or survives a login unchanged
 *   guessing nothing stops ten thousand password attempts against five accounts
 *
 * A forged payment in an append-only ledger is the worst combination available:
 * it cannot be deleted, and it is indistinguishable from a real one.
 */

declare(strict_types=1);

require_once __DIR__ . '/db.php';

/** Failures allowed per email, and per IP, inside the window below. */
const MPC_LOGIN_MAX_FAILURES  = 8;
const MPC_LOGIN_WINDOW_MINUTES = 15;


/**
 * Starts the session with the flags that matter, exactly once.
 */
function mpc_session_start(): void
{
    if (session_status() === PHP_SESSION_ACTIVE) {
        return;
    }

    // `secure` only when the request actually arrived over HTTPS.
    //
    // Setting it unconditionally is a trap: on plain HTTP the browser accepts
    // the Set-Cookie and then never sends it back, so every login appears to
    // succeed and every subsequent page bounces to the login screen, with no
    // error anywhere to explain it. On a site with no IT staff that is close
    // to undebuggable. So: on if we are on HTTPS, off if we are not, and the
    // deployment checklist is what makes sure we are.
    $https = (! empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || ($_SERVER['SERVER_PORT'] ?? null) == 443;

    // If output has already gone out, the cookie parameters cannot be set and
    // session_start() will fail. That should never happen on a real request —
    // nothing echoes before this runs — but a stray warning or a byte-order
    // mark in an included file would do it, and a wall of PHP warnings is a
    // worse way to find out than one clear line. Also keeps the CLI test
    // harness usable, which is how this file gets exercised at all.
    if (headers_sent($file, $line)) {
        throw new RuntimeException(
            "Cannot start a session: output already sent at $file:$line. "
            . 'Something printed before the session was started.'
        );
    }

    session_set_cookie_params([
        'lifetime' => 0,          // dies with the browser session
        'path'     => '/',
        'httponly' => true,       // script cannot read it, so XSS cannot steal it
        'secure'   => $https,
        'samesite' => 'Strict',   // not sent on cross-site requests at all,
                                  // which is a second line behind the CSRF token
    ]);

    session_start();
}

/**
 * The CSRF token for this session, created on first use.
 *
 * One token per session rather than one per form. Per-form tokens are stronger
 * against a very narrow attack, and they break the moment someone opens two
 * tabs — which the office will do on day one.
 */
function mpc_csrf_token(): string
{
    mpc_session_start();

    if (empty($_SESSION['csrf'])) {
        $_SESSION['csrf'] = bin2hex(random_bytes(32));
    }

    return $_SESSION['csrf'];
}

/**
 * Rejects the request unless it carries this session's CSRF token.
 *
 * Call it first in every POST handler, before reading any other input.
 */
function mpc_csrf_check(): void
{
    mpc_session_start();

    $sent = $_POST['csrf'] ?? '';

    // hash_equals, not ===, so the comparison does not leak the token one
    // character at a time through how long it takes to fail.
    if (! is_string($sent) || empty($_SESSION['csrf'])
        || ! hash_equals($_SESSION['csrf'], $sent)) {
        http_response_code(400);
        exit('This form has expired. Go back, reload the page, and try again.');
    }
}

/** The signed-in staff member, or null. */
function mpc_current_user(): ?array
{
    mpc_session_start();

    if (empty($_SESSION['user_id'])) {
        return null;
    }

    static $user = null;
    if ($user !== null) {
        return $user;
    }

    $stmt = mpc_db()->prepare(
        "SELECT id, full_name, email, role, status FROM users WHERE id = ?"
    );
    $stmt->execute([$_SESSION['user_id']]);
    $found = $stmt->fetch();

    // A suspended account is logged out immediately rather than at next login.
    // Without this, revoking someone's access does nothing until they happen to
    // sign out — which, with a session that lasts as long as the browser, could
    // be never.
    if (! $found || $found['status'] !== 'active') {
        mpc_logout();
        return null;
    }

    return $user = $found;
}

/** Sends anyone who is not signed-in staff to the login page. */
function mpc_require_login(): array
{
    $user = mpc_current_user();

    if (! $user || ! in_array($user['role'], ['staff', 'admin'], true)) {
        $target = $_SERVER['REQUEST_URI'] ?? '/mpc/admin/';
        header('Location: ./login.php?next=' . urlencode($target));
        exit;
    }

    return $user;
}

/**
 * Counts recent failures, so guessing costs something.
 *
 * Checked against BOTH the email and the IP: by email so one account cannot be
 * ground down, by IP so a list of likely addresses cannot be walked.
 *
 * CAVEAT ON THE IP, and it is a real one. This reads REMOTE_ADDR. Behind
 * Cloudflare or any host-level proxy, every visitor shares one address, and the
 * IP limit would lock out the entire office the moment anyone mistyped a
 * password a few times. Confirm what the host actually sets before trusting
 * this half. Until then the email limit is the one doing the work.
 */
function mpc_login_is_rate_limited(string $email): bool
{
    $sql = "SELECT COUNT(*) FROM login_attempts
             WHERE successful = 0
               AND created_at > (NOW() - INTERVAL ? MINUTE)
               AND (email = ? OR ip = ?)";

    $stmt = mpc_db()->prepare($sql);
    $stmt->execute([MPC_LOGIN_WINDOW_MINUTES, $email, mpc_client_ip()]);

    return (int) $stmt->fetchColumn() >= MPC_LOGIN_MAX_FAILURES;
}

/** Records an attempt. Every attempt, successful or not — this is the audit
 *  trail as much as the rate limiter. */
function mpc_log_login_attempt(string $email, ?int $userId, bool $ok): void
{
    $stmt = mpc_db()->prepare(
        "INSERT INTO login_attempts (email, user_id, successful, method, ip, user_agent)
         VALUES (?, ?, ?, 'password', ?, ?)"
    );
    $stmt->execute([
        mb_substr($email, 0, 190),
        $userId,
        $ok ? 1 : 0,
        mpc_client_ip(),
        mb_substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 300),
    ]);
}

/** See the caveat in mpc_login_is_rate_limited(). */
function mpc_client_ip(): ?string
{
    $ip = $_SERVER['REMOTE_ADDR'] ?? null;

    return is_string($ip) ? mb_substr($ip, 0, 45) : null;
}

/**
 * Attempts a login. Returns an error string, or null on success.
 *
 * THE ERROR MESSAGE IS DELIBERATELY THE SAME for a wrong password and an
 * unknown address. Different messages turn this page into a way to discover
 * which staff emails exist, which is the first half of guessing a password.
 * The rate-limit message is allowed to differ because it reveals nothing —
 * you already know you have been trying.
 */
function mpc_attempt_login(string $email, string $password): ?string
{
    mpc_session_start();

    $email = trim($email);
    $generic = 'That email address and password do not match an account.';

    if ($email === '' || $password === '') {
        return $generic;
    }

    if (mpc_login_is_rate_limited($email)) {
        mpc_log_login_attempt($email, null, false);
        return 'Too many attempts. Wait ' . MPC_LOGIN_WINDOW_MINUTES
            . ' minutes and try again, or call the office.';
    }

    $stmt = mpc_db()->prepare(
        "SELECT id, password_hash, role, status FROM users WHERE email = ?"
    );
    $stmt->execute([$email]);
    $user = $stmt->fetch();

    // password_verify against a known-bad hash when the user does not exist, so
    // a missing account and a wrong password take the same time to reject. The
    // difference is otherwise measurable and tells an attacker which is which.
    $hash = $user['password_hash']
        ?? '$2y$12$usesomesillystringforsalt0000000000000000000000000000000';

    if (! password_verify($password, $hash) || ! $user
        || $user['status'] !== 'active'
        || ! in_array($user['role'], ['staff', 'admin'], true)) {
        mpc_log_login_attempt($email, $user['id'] ?? null, false);
        return $generic;
    }

    // New session id on privilege change. Without this, an id set before login
    // still works after it, so anyone who managed to fix a session id on the
    // browser beforehand is now signed in as staff.
    session_regenerate_id(true);
    $_SESSION['user_id'] = (int) $user['id'];

    // Re-hash if the cost factor has moved on since this password was set.
    if (password_needs_rehash($hash, PASSWORD_DEFAULT)) {
        $upd = mpc_db()->prepare("UPDATE users SET password_hash = ? WHERE id = ?");
        $upd->execute([password_hash($password, PASSWORD_DEFAULT), $user['id']]);
    }

    mpc_db()->prepare("UPDATE users SET last_login_at = NOW() WHERE id = ?")
            ->execute([$user['id']]);
    mpc_log_login_attempt($email, (int) $user['id'], true);

    return null;
}

/** Ends the session and removes the cookie. */
function mpc_logout(): void
{
    mpc_session_start();
    $_SESSION = [];

    if (ini_get('session.use_cookies')) {
        $p = session_get_cookie_params();
        setcookie(session_name(), '', [
            'expires'  => time() - 42000,
            'path'     => $p['path'],
            'domain'   => $p['domain'],
            'secure'   => $p['secure'],
            'httponly' => $p['httponly'],
            'samesite' => $p['samesite'] ?? 'Strict',
        ]);
    }

    session_destroy();
}
