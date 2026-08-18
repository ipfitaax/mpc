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

// Already signed in? Nothing to do here.
if (mpc_current_user()) {
    header('Location: ./intakes.php');
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
    $next = './intakes.php';
}

$error = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    mpc_csrf_check();

    $error = mpc_attempt_login(
        (string) ($_POST['email'] ?? ''),
        (string) ($_POST['password'] ?? '')
    );

    if ($error === null) {
        // Redirect after a successful POST so a refresh does not resubmit the
        // password, and so the browser's back button does not land on a form
        // still holding it.
        header('Location: ' . $next);
        exit;
    }
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
