<?php
/**
 * Where a signed-in student lands. The whole of the signed-in area, currently.
 *
 * WHY THIS PAGE IS SO SMALL, AND WHY IT SAYS SO
 * Google sign-in was built as sign-in and nothing else: there is no portal
 * behind it. No lessons, no recordings, no fee statement. Those tables are
 * designed in database/future.sql and nothing creates them.
 *
 * So this page tells the student exactly that. The temptation is to dress it up
 * — a dashboard shell, some empty cards, "coming soon" tiles — and that is the
 * same mistake CLAUDE.md already names on the other side of the login: "a
 * message is not a login". Its mirror image is just as bad. A page that looks
 * like a portal and holds nothing teaches a student that the portal is broken,
 * which is worse than being told plainly that it is not built.
 *
 * It also serves a second purpose that is not cosmetic: it is the only proof
 * a student has that signing in worked at all. The public pages are static
 * .html files rendered in the browser, so none of them can show a signed-in
 * state. Without this page, a successful Google login would be invisible.
 *
 * PHP, not a dc template, for the same reason lib/page.php is: this has to
 * render from the server's own session, and the dc runtime cannot see one.
 */

declare(strict_types=1);

require_once __DIR__ . '/lib/auth.php';
require_once __DIR__ . '/lib/page.php';   // for e()

mpc_session_start();

// Sign out. POST with a CSRF token, never a GET — a GET logout can be fired by
// any image tag on any page the student happens to open. Harmless here today,
// but it is the same rule the office tool follows and having two rules is how
// the wrong one gets copied later.
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' && isset($_POST['signout'])) {
    mpc_csrf_check();
    mpc_logout();

    header('Location: ./index.html', true, 302);
    exit;
}

$user = mpc_current_user();

if (! $user) {
    header('Location: ./mpc-login.html', true, 302);
    exit;
}

// Staff who somehow arrive here are sent to their own tool rather than shown a
// student page. Their accounts cannot be reached by Google sign-in at all (see
// lib/oauth.php), so this is only reachable by a staff member who signed in
// with a password and then typed this URL — but a page that renders the wrong
// role's view is worth closing off wherever it appears.
if (in_array($user['role'], ['staff', 'admin'], true)) {
    header('Location: ./office/admin/login.php', true, 302);
    exit;
}

$green   = '#24A68A';
$text    = '#646965';
$heading = '#1d1f20';
$border  = '#e2e5e4';

$name  = e($user['full_name']);
$email = e($user['email'] ?? '');
$csrf  = e(mpc_csrf_token());

header('Content-Type: text/html; charset=utf-8');

echo <<<HTML
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Your account — MPC</title>
<link rel="icon" href="./assets/favicon.ico" sizes="any">
<style>
  *{box-sizing:border-box}
  body{margin:0;font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',sans-serif;
       color:{$text};background:#fafafa;line-height:1.6}
  a{color:{$green}}
  h1{color:{$heading};font-size:1.5rem;font-weight:800;margin:0 0 6px}
  .card{background:#fff;border:1px solid {$border};border-radius:6px;padding:28px}
  .muted{color:#9aa09e;font-size:.9rem}
  button{padding:12px 24px;border-radius:6px;font-weight:700;font-size:.95rem;
         background:#fff;color:{$green};border:2px solid {$green};cursor:pointer;
         font-family:inherit}
  button:hover{background:{$green};color:#fff}
</style>
</head>
<body>
<header style="background:#fff;border-bottom:1px solid {$border}">
  <div style="max-width:720px;margin:0 auto;padding:14px 24px">
    <a href="./index.html" style="display:flex;align-items:center;gap:10px;
       text-decoration:none;color:{$heading};font-weight:800">
      <img src="./assets/logo-mark.png" alt="" style="height:40px;width:auto;display:block">
      Mogadishu Professional Certificate
    </a>
  </div>
</header>

<main style="max-width:720px;margin:0 auto;padding:34px 24px 60px">
  <div class="card">
    <h1>You are signed in</h1>
    <p class="muted" style="margin:0 0 22px">Signed in with Google as {$name}<br>{$email}</p>

    <p style="margin:0 0 20px">
      There is nothing to see here yet. The student portal — course materials,
      recordings and fee statements — is not built. Signing in does not give you
      access to anything at the moment; it only confirms who you are, so the
      portal has something to build on when it exists.
    </p>

    <p style="margin:0 0 26px">
      To ask about enrollment or your fees, use the
      <a href="./index.html#apply-form">enrollment form</a> or call
      <strong>+252 770 51 90 98</strong>.
    </p>

    <form method="post" action="./account.php" style="margin:0">
      <input type="hidden" name="csrf" value="{$csrf}">
      <button type="submit" name="signout" value="1">Sign out</button>
    </form>
  </div>
</main>
</body>
</html>
HTML;
