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
 * The roles that sign in with a password, at admin/login.php.
 *
 * ONE list, in one place, because it is read by three files that must never
 * disagree: mpc_attempt_login() below admits exactly these roles, lib/oauth.php
 * REFUSES exactly these roles a Google identity, and lib/quiz.php gates the
 * office quiz screens on them. Those three are the same set seen from three
 * directions — "who may hold a password", "who may not use Google", "who works
 * here" — and the day they are three separate arrays is the day someone adds a
 * role to one and creates an account that has office access and is enterable
 * through somebody's Gmail.
 *
 * `instructor` is on this list, and it was added the day instructors got quiz
 * screens. Before that the role existed in the users ENUM and nothing read it.
 * It is here rather than treated as a lesser staff member because the account
 * can now see every student's grades and write the papers they are marked on:
 * that is not the money ledger, but it is not a student account either.
 *
 * Adding a role here has TWO consequences, and the second is easy to miss:
 * it can sign in, AND it stops being reachable by Google sign-in. Both are
 * intended. Read the top of lib/oauth.php before changing this line.
 */
const MPC_OFFICE_ROLES = ['instructor', 'staff', 'admin'];


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
        // `Lax`, not `Strict`, and Google sign-in is the reason.
        //
        // Strict withholds the cookie from any request whose initiator is
        // another site — including the top-level redirect Google sends the
        // browser back on at the end of sign-in. So the callback ran with a
        // brand-new empty session, found no `google_state` in it, and told
        // every visitor "That sign-in link has expired. Please try again."
        // Nothing had expired and trying again did exactly the same thing:
        // the state was never readable on the way back, so Google sign-in
        // could not succeed for anybody, not once.
        //
        // Lax still withholds it from cross-site POSTs and from sub-resource
        // loads, which is the part that was doing the work. It sends it on a
        // top-level GET navigation, which is precisely and only what the
        // OAuth return leg is. The CSRF token stays the actual defence; this
        // flag was only ever the second line behind it.
        'samesite' => 'Lax',
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

    // Cached per user id, not in a bare static.
    //
    // The point of the cache is one query per request rather than one per call,
    // and a plain `static $user` delivers that — right up until a second user is
    // looked up in the same process, at which point it hands back the first one
    // and is confidently wrong. A web request only ever signs in one person, so
    // production never noticed; the test suite runs many logins in one process
    // and noticed immediately. Keying it costs nothing and removes the class of
    // bug entirely, including the version of it that would appear the day a CLI
    // script iterates over users.
    static $cache = [];

    $id = (int) $_SESSION['user_id'];
    if (isset($cache[$id])) {
        return $cache[$id];
    }

    $stmt = mpc_db()->prepare(
        "SELECT id, full_name, email, role, status FROM users WHERE id = ?"
    );
    $stmt->execute([$id]);
    $found = $stmt->fetch();

    // A suspended account is logged out immediately rather than at next login.
    // Without this, revoking someone's access does nothing until they happen to
    // sign out — which, with a session that lasts as long as the browser, could
    // be never.
    if (! $found || $found['status'] !== 'active') {
        mpc_logout();
        return null;
    }

    return $cache[$id] = $found;
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
 *  trail as much as the rate limiter.
 *
 *  `$method` matches the ENUM on login_attempts. It exists so that "was this
 *  account broken into" can be answered per route: a burst of failures against
 *  one email means something different when they are password attempts than
 *  when they are Google callbacks, and a single column of undifferentiated
 *  attempts cannot tell you which you are looking at. */
function mpc_log_login_attempt(
    string $email,
    ?int $userId,
    bool $ok,
    string $method = 'password'
): void {
    $stmt = mpc_db()->prepare(
        "INSERT INTO login_attempts (email, user_id, successful, method, ip, user_agent)
         VALUES (?, ?, ?, ?, ?, ?)"
    );
    $stmt->execute([
        mb_substr($email, 0, 190),
        $userId,
        $ok ? 1 : 0,
        $method,
        mpc_client_ip(),
        mb_substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 300),
    ]);
}

/**
 * Puts a user id into the session, the one way.
 *
 * Both login routes end here, and the session-fixation defence is why it is one
 * function rather than two lines copied. Without session_regenerate_id() an id
 * set on the browser BEFORE the login still works after it, so anyone who
 * managed to fix a session id beforehand is now signed in as that user. The
 * password path had this from the start; the Google path must not be the one
 * that forgets, and the way to guarantee that is to leave it nowhere to forget.
 */
function mpc_establish_session(int $userId): void
{
    mpc_session_start();
    session_regenerate_id(true);
    $_SESSION['user_id'] = $userId;

    mpc_db()->prepare("UPDATE users SET last_login_at = NOW() WHERE id = ?")
            ->execute([$userId]);
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
        || ! in_array($user['role'], MPC_OFFICE_ROLES, true)) {
        mpc_log_login_attempt($email, $user['id'] ?? null, false);
        return $generic;
    }

    // New session id on privilege change, and last_login_at, both in the one
    // helper the Google route also goes through.
    mpc_establish_session((int) $user['id']);

    // Re-hash if the cost factor has moved on since this password was set.
    if (password_needs_rehash($hash, PASSWORD_DEFAULT)) {
        $upd = mpc_db()->prepare("UPDATE users SET password_hash = ? WHERE id = ?");
        $upd->execute([password_hash($password, PASSWORD_DEFAULT), $user['id']]);
    }

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
            'samesite' => $p['samesite'] ?? 'Lax',
        ]);
    }

    session_destroy();
}
