<?php
/**
 * Receives a student enquiry from the MPC website.
 *
 * WHY THIS EXISTS
 * The page shipped with `onsubmit="event.preventDefault(); showThanks();"` — it
 * showed the student "Thank you for contacting MPC" and sent nothing to anyone.
 * Every prospective student who filled it in was lost, silently, while the page
 * reported success. The whole plan makes "Apply Now" the primary call to
 * action, so that one line was quietly discarding the business.
 *
 * THE ORDER OF OPERATIONS IS THE POINT
 * The enquiry is written to disk BEFORE any email is attempted, and the student
 * is only told it worked if that write succeeded. Mail can fail for reasons
 * nobody controls — a full mailbox, a spam filter, a bad afternoon at the
 * provider. A lead that is on disk can still be followed up tomorrow. A lead
 * that only ever existed inside a failed mail() call is gone forever.
 */

header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/shared.php';

// ---------------------------------------------------------------------------
// Settings. Change the recipient here, not in three places further down.
// ---------------------------------------------------------------------------
const NOTIFY_TO   = 'info@mpc.so';
const NOTIFY_FROM = 'info@mpc.so';   // must be an mpc.so address: the domain's
                                     // SPF authorises this host, so mail from
                                     // it passes authentication. A From on
                                     // another domain gets rejected outright.
const SITE_NAME   = 'Mogadishu Professional Certificate';

function fail($message, $status = 400)
{
    http_response_code($status);
    echo json_encode(['ok' => false, 'error' => $message]);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    fail('This endpoint accepts form submissions only.', 405);
}

// A hidden field real students never see and never fill in. Bots fill every
// field they find, so anything here means the submission is automated. Answer
// 200 and discard, rather than 400 — telling a bot it failed teaches it to
// retry differently.
if (mpc_clean($_POST['website'] ?? '') !== '') {
    echo json_encode(['ok' => true]);
    exit;
}

$enquiry = [
    'received_at' => date('c'),
    'name'        => mpc_clean($_POST['name'] ?? '', 120),
    'phone'       => mpc_clean($_POST['phone'] ?? '', 40),
    'email'       => mpc_clean($_POST['email'] ?? '', 160),
    'education'   => mpc_clean($_POST['education'] ?? '', 60),
    'program'     => mpc_clean($_POST['program'] ?? '', 120),
    'interest'    => mpc_clean($_POST['interest'] ?? '', 200),
    'best_time'   => mpc_clean($_POST['best_time'] ?? '', 40),
    'message'     => mpc_clean($_POST['message'] ?? '', 2000),
    'ip'          => $_SERVER['REMOTE_ADDR'] ?? '',
    'user_agent'  => mpc_clean($_SERVER['HTTP_USER_AGENT'] ?? '', 300),
];

// Only what is genuinely needed to phone the student back. Email is NOT
// required: this business runs on phones, and rejecting an enquiry from a
// student without an email address would turn away exactly the beginners the
// programs are for.
if ($enquiry['name'] === '')    fail('Please enter your full name.');
if ($enquiry['phone'] === '')   fail('Please enter a phone number so we can reach you.');
if ($enquiry['program'] === '') fail('Please choose the program that interests you.');

if ($enquiry['email'] !== '' && ! filter_var($enquiry['email'], FILTER_VALIDATE_EMAIL)) {
    fail('That email address does not look right. Check it, or leave it blank.');
}

// --- Save first. Everything below here can fail without losing the lead. ----
if (! mpc_append('enquiries.jsonl', $enquiry)) {
    // Do not thank the student for something that did not happen. Give them a
    // way to reach MPC that does not depend on this server working.
    error_log('MPC: could not write enquiry to ' . mpc_storage_path());
    fail('Sorry — we could not record your enquiry. Please call or WhatsApp +252 770 51 90 98.', 500);
}

// --- Then notify. A mail failure must not undo a saved lead. ---------------
$whatsapp = 'https://wa.me/' . preg_replace('/\D+/', '', $enquiry['phone']);

$body = "A new student enquiry from the MPC website.\n\n"
      . "WHO\n"
      . '  Name       : ' . $enquiry['name'] . "\n"
      . '  Phone      : ' . $enquiry['phone'] . "\n"
      . '  WhatsApp   : ' . $whatsapp . "\n"
      . '  Email      : ' . ($enquiry['email'] ?: '— not given') . "\n\n"
      . "WHAT THEY WANT\n"
      . '  Program    : ' . $enquiry['program'] . "\n"
      . '  Finished school: ' . ($enquiry['education'] ?: '—') . "\n"
      . '  Interest   : ' . ($enquiry['interest'] ?: '—') . "\n"
      . '  Best time  : ' . ($enquiry['best_time'] ?: '—') . "\n\n"
      . "MESSAGE\n"
      . '  ' . ($enquiry['message'] ?: '— none') . "\n\n"
      . 'Received   : ' . date('D j M Y, H:i') . "\n"
      . "Also saved on the server, so this lead is not lost if the email is.\n";

$subject = 'New enquiry: ' . $enquiry['name'] . ' — ' . $enquiry['program'];

$headers = [
    'From: ' . SITE_NAME . ' <' . NOTIFY_FROM . '>',
    'Content-Type: text/plain; charset=utf-8',
];

// Reply-To is the student, so hitting Reply in the inbox writes to them rather
// than to MPC's own address. Guarded: an unvalidated address here is how a
// header injection would get in.
if ($enquiry['email'] !== '' && filter_var($enquiry['email'], FILTER_VALIDATE_EMAIL)) {
    $headers[] = 'Reply-To: ' . $enquiry['email'];
}

$mailed = @mail(NOTIFY_TO, $subject, $body, implode("\r\n", $headers));

if (! $mailed) {
    // Expected on local XAMPP, which has no mail server. The student is still
    // told the truth — their enquiry IS recorded — and the failure is logged
    // for whoever checks. Never report a send that did not happen.
    error_log('MPC: enquiry saved but email to ' . NOTIFY_TO . ' failed.');
}

echo json_encode(['ok' => true, 'notified' => (bool) $mailed]);
