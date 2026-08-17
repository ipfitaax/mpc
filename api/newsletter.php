<?php
/**
 * Newsletter signup for MPC programme announcements.
 *
 * Same rule as enquiry.php: the address is written to disk before the visitor
 * is thanked. The page previously just cleared the box and changed the
 * placeholder to "Subscribed — thank you!", which was a message, not a
 * subscription.
 */

header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/shared.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'This endpoint accepts form submissions only.']);
    exit;
}

// Hidden field; only automated submissions fill it in.
if (mpc_clean($_POST['website'] ?? '') !== '') {
    echo json_encode(['ok' => true]);
    exit;
}

$email = mpc_clean($_POST['email'] ?? '', 160);

if ($email === '' || ! filter_var($email, FILTER_VALIDATE_EMAIL)) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Please enter a valid email address.']);
    exit;
}

$ok = mpc_append('subscribers.jsonl', [
    'email'         => $email,
    'subscribed_at' => date('c'),
    'ip'            => $_SERVER['REMOTE_ADDR'] ?? '',
]);

if (! $ok) {
    error_log('MPC: could not save subscriber ' . $email);
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'Sorry — we could not save your address. Please try again later.']);
    exit;
}

echo json_encode(['ok' => true]);
