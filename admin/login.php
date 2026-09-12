<?php
/**
 * Sign in to the office tool.
 *
 * Deliberately plain: no logo animation, no "welcome back", no JavaScript. It
 * renders and works with nothing loaded from anywhere else, which is the whole
 * reason the office tool is not built on the dc/React runtime the public pages
 * use. See lib/page.php.
 */

declare(strict_types=1);

require_once dirname(__DIR__) . '/lib/auth.php';
require_once dirname(__DIR__) . '/lib/page.php';

mpc_session_start();

/**
 * The screen to land on after signing in.
 *
 * NOT a hardcoded ./intakes.php, which is what this used to be and what broke
 * the day instructors could sign in. intakes.php admits staff and admin only,
 * so an instructor landed there, was redirected back to this page, was found to
 * be signed in already, and was sent to intakes.php again — a redirect loop that
 * the browser eventually gives up on with an error mentioning neither the role
 * nor the page. The first screen a role may actually open is the only safe
 * destination, and lib/page.php already knows which that is.
 */
function mpc_login_destination(?array $user): ?string
{
    $nav = mpc_nav_for($user);

    // NULL, not a fallback URL, when this role has no screen at all. Every
    // candidate fallback is a page that would bounce straight back here, so
    // there is no address to send them to — and inventing one is how a loop
    // gets rebuilt one redirect further out. The caller renders an explanation
    // instead, which is the only thing that actually helps the person reading it.
    return $nav === [] ? null : './' . array_key_first($nav);
}

// Already signed in, and there is somewhere to go? Nothing to do here.
$signedIn = mpc_current_user();

if ($signedIn && ($home = mpc_login_destination($signedIn)) !== null) {
    header('Location: ' . $home);
    exit;
}

/**
 * Where to go after signing in.
 *
 * Only ever a path on this site. Taking the raw ?next= would let a link like
 * login.php?next=https://example.com bounce a staff member off-site straight
 * after they typed their password, which is the shape of every credential
 * phishing flow. Anything that is not a plain relative path is discarded.
 */
$next = (string) ($_GET['next'] ?? '');
if ($next === '' || str_contains($next, '://') || str_starts_with($next, '//')) {
    $next = '';   // resolved per role below, once we know who signed in
}

$error = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    mpc_csrf_check();

    $error = mpc_attempt_login(
        (string) ($_POST['email'] ?? ''),
        (string) ($_POST['password'] ?? '')
    );

    if ($error === null) {
        $signedIn = mpc_current_user();

        if ($next === '') {
            $next = mpc_login_destination($signedIn);
        }

        // Redirect after a successful POST so a refresh does not resubmit the
        // password, and so the browser's back button does not land on a form
        // still holding it.
        if ($next !== null) {
            header('Location: ' . $next);
            exit;
        }

        // Signed in with nowhere to go. Fall through and say so.
        $error = null;
    }
}

// Signed in, but this role has no screen it may open. Showing a sign-in form to
// someone who is already signed in would be nonsense, so say what has actually
// happened. This is reachable only if MPC_NAV and the files on disk disagree —
// an admin/ deployed without quizzes.php, say — and it exists because the
// alternative is the redirect loop this page already had once.
if ($signedIn = mpc_current_user()) {
    mpc_page_head('Signed in', null);
    echo '<div class="card" style="max-width:520px">'
       . '<h2 style="font-size:1.05rem">There is nothing here for this account</h2>'
       . '<p>You are signed in as <strong>' . e($signedIn['full_name']) . '</strong> ('
       . e($signedIn['role']) . '), but none of the screens that role may open are '
       . 'installed on this server. That is a deployment problem, not something '
       . 'you can fix by signing in again.</p>'
       . '<form method="post" action="./logout.php" style="margin:0">'
       . '<input type="hidden" name="csrf" value="' . e(mpc_csrf_token()) . '">'
       . '<button type="submit">Sign out</button></form></div>';
    mpc_page_foot();
    exit;
}

mpc_page_head('Sign in');
?>
<div class="card" style="max-width:420px">
  <form method="post">
    <?php mpc_csrf_field(); ?>
    <div style="margin-bottom:16px">
      <label for="email">Email address</label>
      <input type="email" id="email" name="email" required autofocus
             value="<?= e($_POST['email'] ?? '') ?>">
    </div>
    <div style="margin-bottom:22px">
      <label for="password">Password</label>
      <input type="password" id="password" name="password" required>
    </div>
    <button type="submit" style="width:100%">Sign in</button>
    <?php mpc_message($error, false); ?>
  </form>
  <p class="muted" style="margin-top:20px">
    Accounts are created by an administrator with
    <code>bin/adduser.php</code>. There is no self-registration and no reset
    by email — if you are locked out, ask them to reset it.
  </p>
</div>
<?php
mpc_page_foot();
