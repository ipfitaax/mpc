<?php
/**
 * Step 2 of Google sign-in: Google sends the browser back here.
 *
 * THE RULE THIS FILE INHERITS
 * api/enquiry.php never reports success for something that did not happen, and
 * api-form.js never shows a message the server did not confirm. The same rule
 * governs a login, and more sharply: this endpoint must never leave the visitor
 * looking at a signed-in page unless a session actually exists. Every failure
 * below therefore ends at the login page with a message, not at a page that
 * merely looks welcoming.
 *
 * WHY EVERY FAILURE SAYS SO LITTLE
 * The visitor gets a short, fixed sentence; the detail goes to the error log.
 * The difference between "that email belongs to an account we could not link"
 * and "no such account" is exactly the difference an attacker wants, and a
 * login screen is the last place to be helpful about which accounts exist.
 */

declare(strict_types=1);

require_once __DIR__ . '/../../../lib/oauth.php';
require_once __DIR__ . '/../../../lib/auth.php';

/** How long a started attempt stays valid. Long enough to pick an account and
 *  type a password at Google, short enough that an abandoned attempt is not a
 *  key left under the mat. */
const MPC_GOOGLE_ATTEMPT_TTL = 900;

mpc_session_start();

/** Back to the login page carrying a message it can show. Nothing else in this
 *  file ends the request, so there is exactly one way to fail. */
function mpc_google_fail(string $visitorMessage, string $logDetail): never
{
    error_log('[mpc][google] ' . $logDetail);

    unset($_SESSION['google_state'], $_SESSION['google_nonce'], $_SESSION['google_started']);

    header('Location: ../../../mpc-login.html?error=' . rawurlencode($visitorMessage), true, 302);
    exit;
}

$generic = 'Google sign-in did not complete. Please try again, or call +252 770 51 90 98.';

// Google reports a refusal in the query string rather than by failing. The
// commonest by far is the student pressing Cancel, which is not an error worth
// alarming anyone about.
if (isset($_GET['error'])) {
    $denied = ($_GET['error'] === 'access_denied');
    mpc_google_fail(
        $denied ? 'Sign-in was cancelled.' : $generic,
        'provider returned error: ' . substr((string) $_GET['error'], 0, 100)
    );
}

$state = $_GET['state'] ?? '';
$code  = $_GET['code'] ?? '';

if (! is_string($state) || ! is_string($code) || $code === '') {
    mpc_google_fail($generic, 'callback missing code or state');
}

// The state check. Compared against the session with hash_equals, and the
// session copy is removed immediately afterwards so one authorisation code
// cannot be walked through twice.
$expectedState = $_SESSION['google_state'] ?? '';
$expectedNonce = $_SESSION['google_nonce'] ?? '';
$started       = (int) ($_SESSION['google_started'] ?? 0);

unset($_SESSION['google_state'], $_SESSION['google_nonce'], $_SESSION['google_started']);

if ($expectedState === '' || ! hash_equals($expectedState, $state)) {
    mpc_google_fail(
        'That sign-in link has expired. Please try again.',
        'state mismatch or absent — CSRF, a stale tab, or a lost session cookie'
    );
}

if ($started === 0 || (time() - $started) > MPC_GOOGLE_ATTEMPT_TTL) {
    mpc_google_fail('That sign-in took too long. Please try again.', 'attempt expired');
}

try {
    $claims = mpc_google_claims_from_code($code, $expectedNonce);
} catch (Throwable $e) {
    mpc_google_fail($generic, 'code exchange failed: ' . $e->getMessage());
}

// Logged before the outcome is known, with whatever address Google gave, so a
// refused link leaves the same trail a refused password does. An attempt that
// fails at the link step is invisible otherwise.
$email = $claims['email'] ?? '';

try {
    [$userId, $linkError] = mpc_google_link_user($claims);
} catch (Throwable $e) {
    mpc_log_login_attempt($email, null, false, 'google');
    mpc_google_fail($generic, 'link failed: ' . $e->getMessage());
}

if ($userId === null) {
    mpc_log_login_attempt($email, null, false, 'google');

    // $linkError is written by lib/oauth.php for the visitor to read — the
    // staff-account and suspended-account cases, where saying nothing would
    // leave someone retrying a thing that can never work.
    mpc_google_fail($linkError ?? $generic, 'link refused: ' . ($linkError ?? 'unknown'));
}

mpc_establish_session($userId);
mpc_log_login_attempt($email, $userId, true, 'google');

header('Location: ../../../account.php', true, 302);
exit;
