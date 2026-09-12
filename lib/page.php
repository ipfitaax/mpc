<?php
/**
 * The chrome around every office screen.
 *
 * WHY THIS IS NOT THE `dc` RUNTIME THE REST OF THE SITE USES
 * The public pages are omelette/design-canvas templates that load React and
 * Babel from unpkg at page load. CLAUDE.md records the consequence plainly:
 * "The page needs network access to unpkg to render at all." That is an
 * acceptable trade for a marketing page. It is the wrong trade for the screen
 * an office uses to record money, because it fails on exactly the bad day this
 * tool has to survive — and it fails as a blank white page, which reads as
 * "the system is down" rather than "the internet is slow".
 *
 * So these are plain server-rendered PHP pages with inline styles. They render
 * with no JavaScript at all and no external request of any kind.
 *
 * The palette is deliberately the one from index.html, because the office
 * should recognise this as the same institution's software.
 */

declare(strict_types=1);

// The nav renders a CSRF-protected sign-out form, so this needs the session
// helpers. Both files are include-once and auth.php pulls in db.php, so a page
// can require either one and get a working set.
require_once __DIR__ . '/auth.php';

const MPC_GREEN      = '#24A68A';
const MPC_GREEN_DARK = '#1C8570';
const MPC_TEXT       = '#646965';
const MPC_HEADING    = '#1d1f20';
const MPC_BORDER     = '#e2e5e4';
const MPC_RED        = '#c0392b';

/**
 * Office screens, in the order they appear in the nav: file => [label, roles].
 *
 * Entries whose file is not present are skipped — see mpc_page_head().
 *
 * THE ROLE LIST IS NOT DECORATION, and it is not the access check either. The
 * check is the mpc_require_* call on the first line of each screen; this list
 * is what stops the nav OFFERING a link that check will refuse. Those are two
 * different jobs and both are needed: without the check the nav is the security,
 * and without the list an instructor sees "Payments", clicks it, and is bounced
 * to a login page while already logged in — which reads as a broken system
 * rather than as a closed door.
 *
 * Keep the two in step. A screen added here with the wrong roles is a link that
 * lies; a screen added here that forgets its own require call is a screen with
 * no door at all.
 */
const MPC_NAV = [
    'payments.php' => ['Payments', ['staff', 'admin']],
    'students.php' => ['Students', ['staff', 'admin']],
    'intakes.php'  => ['Intakes',  ['staff', 'admin']],
    'quizzes.php'  => ['Quizzes',  ['instructor', 'staff', 'admin']],
];

/** The nav entries this user may actually open, as file => label. */
function mpc_nav_for(?array $user): array
{
    if (! $user) {
        return [];
    }

    $out = [];

    foreach (MPC_NAV as $file => [$label, $roles]) {
        if (in_array($user['role'], $roles, true)
            && is_readable(__DIR__ . '/../admin/' . $file)) {
            $out[$file] = $label;
        }
    }

    return $out;
}

/** Escapes for HTML. Short name because it appears on nearly every output line,
 *  and a long one is a name people skip. */
function e(?string $v): string
{
    return htmlspecialchars($v ?? '', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

/** Formats money for display. Never used for arithmetic. */
function mpc_money(string|float|int $amount, string $currency = 'USD'): string
{
    return e($currency) . ' ' . number_format((float) $amount, 2);
}

/**
 * Opens the page.
 *
 * @param string      $title shown in the tab and as the h1
 * @param array|null  $user  the signed-in staff member, or null on the login page
 */
function mpc_page_head(string $title, ?array $user = null): void
{
    // The logo links to the first screen that actually exists AND that this
    // user may open, so it is never a route to a 404 while the tool is still
    // being built out, and never a route to a redirect for an instructor whose
    // first screen is not the payments desk.
    $nav  = mpc_nav_for($user);
    $home = $nav === [] ? './login.php' : './' . array_key_first($nav);

    $green = MPC_GREEN;
    $border = MPC_BORDER;
    $text = MPC_TEXT;
    $heading = MPC_HEADING;

    echo <<<HTML
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>{$title} — MPC Office</title>
<link rel="icon" href="../assets/favicon.ico" sizes="any">
<style>
  *{box-sizing:border-box}
  body{margin:0;font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',sans-serif;
       color:{$text};background:#fafafa;line-height:1.5}
  a{color:{$green}}
  h1,h2,h3{font-family:inherit;color:{$heading};margin:0 0 12px}
  label{display:block;font-size:.85rem;font-weight:700;color:{$heading};margin-bottom:6px}
  input,select,textarea{width:100%;padding:12px 14px;border:1px solid {$border};
       border-radius:6px;font-size:1rem;color:{$text};background:#fff;font-family:inherit}
  button{padding:13px 26px;border-radius:6px;font-weight:700;font-size:.95rem;
       background:{$green};color:#fff;border:2px solid {$green};cursor:pointer;font-family:inherit}
  button:hover{background:#1C8570;border-color:#1C8570}
  button[disabled]{opacity:.6;cursor:default}
  table{width:100%;border-collapse:collapse;background:#fff}
  th,td{text-align:left;padding:10px 12px;border-bottom:1px solid {$border};font-size:.93rem}
  th{font-weight:700;color:{$heading}}
  .card{background:#fff;border:1px solid {$border};border-radius:6px;padding:26px;margin-bottom:20px}
  .msg-ok{color:{$green};font-weight:600}
  .msg-bad{color:#c0392b;font-weight:600}
  .muted{color:#9aa09e;font-size:.85rem}
  /* Amounts right-aligned and tabular so columns of money line up on the
     decimal point. Reading a column of figures is the whole job of this tool. */
  .num{text-align:right;font-variant-numeric:tabular-nums}

  /* The receipt is printed and handed to a student. Everything that is not the
     receipt disappears, and it prints on white regardless of the screen theme. */
  @media print{
    body{background:#fff}
    .no-print{display:none !important}
    .card{border:none;padding:0;margin:0}
  }
</style>
</head>
<body>
<header class="no-print" style="background:#fff;border-bottom:1px solid {$border};margin-bottom:26px">
  <div style="max-width:960px;margin:0 auto;padding:14px 24px;display:flex;
              align-items:center;justify-content:space-between;gap:16px;flex-wrap:wrap">
    <a href="{$home}" style="display:flex;align-items:center;gap:10px;text-decoration:none;color:inherit">
      <span style="width:40px;height:40px;background:{$green};color:#fff;font-weight:800;
                   display:flex;align-items:center;justify-content:center;border-radius:6px">MPC</span>
      <strong style="color:{$heading}">Office</strong>
    </a>
HTML;

    if ($user) {
        // Only pages that exist, and only the ones this role may open. A nav
        // link to a 404 in an office tool reads as "the system is broken", not
        // "that part is not built yet"; a nav link that bounces you to a login
        // screen you are already past reads the same way. Add each entry to
        // MPC_NAV when its screen lands.
        $links = [];
        foreach ($nav as $file => $label) {
            $links[] = '<a href="./' . $file . '" style="font-weight:600;text-decoration:none">'
                     . e($label) . '</a>';
        }

        // Sign out is a POST with a CSRF token, not a link. A GET logout can be
        // fired by any image tag on any page a staff member opens — harmless,
        // except when it happens mid-payment.
        $signOut = '<form method="post" action="./logout.php" style="margin:0">'
                 . '<input type="hidden" name="csrf" value="' . e(mpc_csrf_token()) . '">'
                 . '<button type="submit" style="background:none;border:none;padding:0;'
                 . 'color:' . MPC_GREEN . ';font-weight:600;font-size:.9rem;cursor:pointer">'
                 . 'Sign out</button></form>';

        echo '<nav style="display:flex;align-items:center;gap:18px;font-size:.9rem">'
           . implode('', $links)
           . '<span class="muted">' . e($user['full_name']) . '</span>'
           . $signOut
           . '</nav>';
    }

    echo '</div></header><main style="max-width:960px;margin:0 auto;padding:0 24px 60px">';
    echo '<h1 style="font-size:1.5rem;font-weight:800;margin-bottom:20px">' . e($title) . '</h1>';
}

/** Closes the page. */
function mpc_page_foot(): void
{
    echo '</main></body></html>';
}

/**
 * A hidden CSRF field. Every POST form in the office tool includes this.
 *
 * It is a function rather than a snippet people copy, so that if the token
 * mechanism ever changes there is one place to change it — and no form is left
 * behind still posting the old shape and being rejected.
 */
function mpc_csrf_field(): void
{
    echo '<input type="hidden" name="csrf" value="' . e(mpc_csrf_token()) . '">';
}

/** A green confirmation or a red failure. Nothing else renders a message, so
 *  a success can never be styled like a failure or the reverse. */
function mpc_message(?string $text, bool $ok): void
{
    if ($text === null || $text === '') {
        return;
    }

    echo '<p class="' . ($ok ? 'msg-ok' : 'msg-bad') . '">' . e($text) . '</p>';
}
