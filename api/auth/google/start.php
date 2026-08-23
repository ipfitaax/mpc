<?php
/**
 * Step 1 of Google sign-in: send the browser to Google.
 *
 * A GET, and deliberately so — this is a plain link on the login page, not a
 * form post. Nothing has happened to any account by the time this redirect
 * fires, so there is nothing here for CSRF to protect. The `state` value
 * generated below is what protects the step that does.
 */

declare(strict_types=1);

require_once __DIR__ . '/../../../lib/oauth.php';
require_once __DIR__ . '/../../../lib/auth.php';

mpc_session_start();

if (! mpc_google_configured()) {
    // Not an error page. On a deployment where the OAuth client has not been
    // registered this is the expected state, and the visitor needs the same
    // thing they needed before the button existed: a way to reach the office.
    http_response_code(503);
    header('Content-Type: text/html; charset=utf-8');
    exit('<!DOCTYPE html><meta charset="utf-8"><title>Sign-in unavailable</title>'
       . '<p style="font-family:sans-serif;max-width:34em;margin:3em auto;line-height:1.6">'
       . 'Google sign-in is not switched on for this site yet. '
       . 'To ask about enrollment, call <strong>+252 770 51 90 98</strong> or use the '
       . '<a href="../../../index.html#apply-form">enrollment form</a>.</p>');
}

// One-time values for this attempt, kept server-side. `state` comes back as a
// query parameter and is compared in the callback; `nonce` comes back inside
// the ID token. They are separate values on purpose — state proves the browser
// that returns is the one that left, nonce proves the token was minted for this
// departure rather than replayed from an older one.
$state = bin2hex(random_bytes(32));
$nonce = bin2hex(random_bytes(32));

$_SESSION['google_state'] = $state;
$_SESSION['google_nonce'] = $nonce;

// Stamped so the callback can refuse a stale attempt. A state left in the
// session from a login someone abandoned days ago should not still be a valid
// key to walk through.
$_SESSION['google_started'] = time();

header('Location: ' . mpc_google_auth_url($state, $nonce), true, 302);
exit;
