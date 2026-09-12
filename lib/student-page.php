<?php
/**
 * The chrome around every signed-in student screen.
 *
 * WHY THIS IS SEPARATE FROM lib/page.php
 * That file is the OFFICE chrome, and the difference is not cosmetic. It renders
 * a nav of office screens and a staff member's name; putting a student inside it
 * would show them links they cannot open, which is the exact failure the role
 * list in MPC_NAV exists to prevent. They are two audiences with two navs, so
 * they are two functions.
 *
 * WHY IT IS SERVER-RENDERED PHP AND NOT A dc TEMPLATE
 * Same reason as the office tool, and it applies harder here. The public pages
 * load React and Babel from unpkg at page load, so they cannot render without
 * network access to a third party — and they cannot see a PHP session at all.
 * A student sitting an exam is the last person who should meet a blank page
 * because a CDN is slow. These screens render from this server alone.
 *
 * The palette is the public site's, because a student should not feel handed
 * off to a different institution halfway through.
 */

declare(strict_types=1);

require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/page.php';   // for e(), and the colour constants

/**
 * Opens a student page.
 *
 * $current is a filename from the nav below, so the screen you are on is not a
 * link to itself.
 */
function mpc_student_page_head(string $title, array $user, string $current = ''): void
{
    $green   = MPC_GREEN;
    $text    = MPC_TEXT;
    $heading = MPC_HEADING;
    $border  = MPC_BORDER;
    $red     = MPC_RED;

    $nav = ['quizzes.php' => 'Quizzes', 'account.php' => 'Your account'];

    header('Content-Type: text/html; charset=utf-8');

    echo <<<HTML
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>{$title} — MPC</title>
<link rel="icon" href="./assets/favicon.ico" sizes="any">
<style>
  *{box-sizing:border-box}
  body{margin:0;font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',sans-serif;
       color:{$text};background:#fafafa;line-height:1.6}
  a{color:{$green}}
  h1{color:{$heading};font-size:1.5rem;font-weight:800;margin:0 0 18px}
  h2{color:{$heading};font-size:1.05rem;font-weight:700;margin:0 0 10px}
  label{display:block;font-size:.85rem;font-weight:700;color:{$heading};margin-bottom:6px}
  .card{background:#fff;border:1px solid {$border};border-radius:6px;padding:28px;margin-bottom:20px}
  .muted{color:#9aa09e;font-size:.9rem}
  .ok{color:{$green};font-weight:700}
  .bad{color:{$red};font-weight:700}
  button{padding:12px 24px;border-radius:6px;font-weight:700;font-size:.95rem;
         background:{$green};color:#fff;border:2px solid {$green};cursor:pointer;
         font-family:inherit}
  button:hover{background:#1C8570;border-color:#1C8570}
  button[disabled]{opacity:.6;cursor:default}
  table{width:100%;border-collapse:collapse}
  th,td{text-align:left;padding:10px 12px;border-bottom:1px solid {$border};font-size:.93rem}
  th{font-weight:700;color:{$heading}}
  .num{text-align:right;font-variant-numeric:tabular-nums}
</style>
</head>
<body>
<header style="background:#fff;border-bottom:1px solid {$border}">
  <div style="max-width:820px;margin:0 auto;padding:14px 24px;display:flex;
              align-items:center;justify-content:space-between;gap:16px;flex-wrap:wrap">
    <a href="./index.html" style="display:flex;align-items:center;gap:10px;
       text-decoration:none;color:{$heading};font-weight:800">
      <img src="./assets/logo-mark.png" alt="" style="height:36px;width:auto;display:block">
      Mogadishu Professional Certificate
    </a>
    <nav style="display:flex;align-items:center;gap:18px;font-size:.9rem">
HTML;

    foreach ($nav as $file => $label) {
        echo $file === $current
            ? '<span style="font-weight:700;color:' . MPC_HEADING . '">' . e($label) . '</span>'
            : '<a href="./' . $file . '" style="font-weight:600;text-decoration:none">' . e($label) . '</a>';
    }

    // Sign out is a POST with a CSRF token, never a GET. Same rule as the office
    // tool: a GET logout can be fired by any image tag on any page a student
    // opens, and doing that to somebody thirty minutes into an exam is worse
    // than an inconvenience.
    echo '<form method="post" action="./account.php" style="margin:0">'
       . '<input type="hidden" name="csrf" value="' . e(mpc_csrf_token()) . '">'
       . '<button type="submit" name="signout" value="1" style="background:none;border:none;'
       . 'padding:0;color:' . MPC_GREEN . ';font-weight:600;font-size:.9rem;cursor:pointer">'
       . 'Sign out</button></form>';

    echo '</nav></div></header>'
       . '<main style="max-width:820px;margin:0 auto;padding:34px 24px 60px">'
       . '<h1>' . e($title) . '</h1>';
}

/** Closes a student page. */
function mpc_student_page_foot(): void
{
    echo '<p class="muted" style="margin-top:30px">'
       . 'Something wrong? Call the office on <strong>+252 770 51 90 98</strong>.'
       . '</p></main></body></html>';
}

/**
 * The signed-in student, or a redirect away from here.
 *
 * Staff are bounced to their own tool rather than shown a student page. Their
 * accounts cannot be reached by Google sign-in at all (lib/oauth.php), so this
 * is only reachable by an office account that signed in with a password and
 * then typed this URL — but a page that renders the wrong role's view is worth
 * closing off wherever it appears, and there are now several places it appears.
 */
function mpc_require_student(): array
{
    mpc_session_start();

    $user = mpc_current_user();

    if (! $user) {
        header('Location: ./mpc-login.html', true, 302);
        exit;
    }

    if (in_array($user['role'], MPC_OFFICE_ROLES, true)) {
        header('Location: ./admin/login.php', true, 302);
        exit;
    }

    return $user;
}
