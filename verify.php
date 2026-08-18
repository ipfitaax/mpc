<?php
/**
 * Check a receipt. Public, no login.
 *
 * WHY THIS PAGE IS THE POINT
 * A ledger only MPC can read does not settle an argument — it makes MPC more
 * confident while it argues. This is the half that makes the record something
 * both sides hold: the student types the code from their slip and sees the
 * same amount and date back, from the institute's own system, without asking
 * anyone's permission.
 *
 * WHAT IT DELIBERATELY DOES NOT SHOW
 * A first name, the amount, the date, the class. Not the full name, not the
 * phone number, not the balance, not any other payment. Someone holding one
 * receipt code has proof of one payment, and that is all this page is for.
 * The balance is between the student and the office, on the receipt they were
 * handed and in a conversation at the desk.
 *
 * ON UNKNOWN CODES
 * A wrong code and a right one produce the same page shape. There is no "no
 * such code" versus "that code belongs to someone else" — both are simply not
 * found. What actually protects the codes is their size: twelve characters of
 * a thirty-symbol alphabet is roughly 5.3e17 combinations, so nobody is
 * walking the space. The rate limit is about not letting a script hammer a
 * shared host, and is honest about being that rather than a secrecy measure.
 */

declare(strict_types=1);

require_once __DIR__ . '/lib/db.php';
require_once __DIR__ . '/lib/ledger.php';

/** Failed lookups allowed from one address before it is asked to slow down. */
const MPC_VERIFY_MAX_FAILURES = 20;
const MPC_VERIFY_WINDOW_MIN   = 60;

/**
 * See the caveat in lib/auth.php: behind Cloudflare or any host-level proxy,
 * every visitor shares one address, and this limit would then apply to the
 * whole internet at once. Confirm what the host sets before relying on it.
 */
function verify_client_ip(): ?string
{
    $ip = $_SERVER['REMOTE_ADDR'] ?? null;

    return is_string($ip) ? mb_substr($ip, 0, 45) : null;
}

$code    = strtoupper(trim((string) ($_GET['c'] ?? $_POST['c'] ?? '')));
$payment = null;
$state   = 'ask';          // ask | found | notfound | slowdown
$db      = null;

if ($code !== '') {
    try {
        $db = mpc_db();

        // Prune opportunistically rather than on a cron. Roughly one request in
        // fifty pays for the cleanup, which keeps the table small without
        // adding a scheduled job nobody would remember to check.
        if (random_int(1, 50) === 1) {
            $db->prepare('DELETE FROM verify_attempts WHERE created_at < (NOW() - INTERVAL ? MINUTE)')
               ->execute([MPC_VERIFY_WINDOW_MIN * 2]);
        }

        // The shape of the code is checked before the database is asked, so
        // obvious rubbish costs nothing. This cannot leak whether a code
        // exists: it only says the string could not be one of ours.
        $looksRight = strlen($code) === MPC_CODE_LENGTH
            && strspn($code, MPC_CODE_ALPHABET) === MPC_CODE_LENGTH;

        if ($looksRight) {
            $stmt = $db->prepare(
                'SELECT p.id, p.amount, p.currency, p.paid_on, p.method,
                        p.verify_code, p.reverses_payment_id,
                        u.full_name, i.name AS intake_name, c.title AS course_title,
                        (SELECT r.id FROM payments r WHERE r.reverses_payment_id = p.id) AS reversed_by
                   FROM payments p
                   JOIN enrollments e ON e.id = p.enrollment_id
                   JOIN users u       ON u.id = e.user_id
                   JOIN intakes i     ON i.id = e.intake_id
                   JOIN courses c     ON c.id = i.course_id
                  WHERE p.verify_code = ?'
            );
            $stmt->execute([$code]);
            $payment = $stmt->fetch() ?: null;
        }

        if ($payment) {
            // A CORRECT CODE ALWAYS RESOLVES, even from an address that has
            // been failing. This was found by testing: the rate limit
            // originally ran first and blocked valid lookups too, so one
            // person probing from a shared connection or behind a NAT could
            // lock a student out of their own receipt. Holding a real code is
            // itself the proof of legitimacy, and serving it costs the same
            // one indexed lookup either way.
            //
            // It makes the limiter weaker against a flood, and that trade is
            // deliberate: the limiter was never what protects these codes.
            // Their size is. See the note at the top of this file.
            $state = 'found';
        } else {
            // Only failures are counted. A student checking their own receipt
            // over and over — which is exactly what a worried person does —
            // must never be locked out of it.
            $db->prepare('INSERT INTO verify_attempts (ip) VALUES (?)')
               ->execute([verify_client_ip()]);

            $stmt = $db->prepare(
                'SELECT COUNT(*) FROM verify_attempts
                  WHERE ip = ? AND created_at > (NOW() - INTERVAL ? MINUTE)'
            );
            $stmt->execute([verify_client_ip(), MPC_VERIFY_WINDOW_MIN]);

            $state = (int) $stmt->fetchColumn() > MPC_VERIFY_MAX_FAILURES
                ? 'slowdown'
                : 'notfound';
        }
    } catch (Throwable $e) {
        // Never show a driver message to the public. The student is told the
        // truth — we could not check it right now — and given the phone number,
        // which is the same shape of answer api-form.js gives when a form fails.
        error_log('verify.php: ' . $e->getMessage());
        $state = 'error';
    }
}

/** First name only. See the note at the top of this file. */
function first_name(string $full): string
{
    $parts = preg_split('/\s+/', trim($full)) ?: [];

    return $parts[0] ?? '';
}

function h(?string $v): string
{
    return htmlspecialchars($v ?? '', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}
?><!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Check a receipt — MPC</title>
<style>
  /* Self-contained: no fonts, no scripts, no request to anywhere. A student
     checks this on a phone on whatever connection they have, and a page that
     needs a CDN to render is a page that fails them. */
  *{box-sizing:border-box}
  body{margin:0;padding:24px;font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',sans-serif;
       color:#646965;background:#fafafa;line-height:1.55}
  .wrap{max-width:460px;margin:0 auto}
  .card{background:#fff;border:1px solid #e2e5e4;border-radius:6px;padding:26px;margin-bottom:18px}
  h1{color:#1d1f20;font-size:1.35rem;margin:0 0 6px}
  label{display:block;font-size:.85rem;font-weight:700;color:#1d1f20;margin-bottom:6px}
  input{width:100%;padding:14px;border:1px solid #e2e5e4;border-radius:6px;font-size:1.15rem;
        font-family:monospace;letter-spacing:.12em;text-transform:uppercase;background:#fff;color:#1d1f20}
  button{width:100%;margin-top:14px;padding:14px;border-radius:6px;font-weight:700;font-size:1rem;
         background:#24A68A;color:#fff;border:2px solid #24A68A;cursor:pointer;font-family:inherit}
  button:hover{background:#1C8570;border-color:#1C8570}
  table{width:100%;border-collapse:collapse}
  th,td{text-align:left;padding:9px 0;border-bottom:1px solid #f0f2f1;font-size:.95rem;vertical-align:top}
  th{color:#1d1f20;font-weight:700;width:42%}
  .muted{color:#9aa09e;font-size:.85rem}
  .big{font-size:1.7rem;font-weight:800;color:#1d1f20;font-variant-numeric:tabular-nums}
  .bad{color:#c0392b;font-weight:600}
  .logo{width:44px;height:44px;background:#24A68A;color:#fff;font-weight:800;border-radius:6px;
        display:flex;align-items:center;justify-content:center}
</style>
</head>
<body>
<div class="wrap">

  <div style="display:flex;align-items:center;gap:12px;margin-bottom:20px">
    <span class="logo">MPC</span>
    <div>
      <strong style="color:#1d1f20">Mogadishu Professional Certificate</strong>
      <div class="muted">Check a receipt</div>
    </div>
  </div>

<?php if ($state === 'found'): ?>
  <?php $isReversal = $payment['reverses_payment_id'] !== null; ?>
  <div class="card">
    <?php if ($payment['reversed_by']): ?>
      <p class="bad" style="margin-top:0">
        This payment was later cancelled by MPC. It no longer counts towards
        what has been paid. Ask the office if you were not told why.
      </p>
    <?php elseif ($isReversal): ?>
      <p class="bad" style="margin-top:0">
        This entry is a cancellation of an earlier payment, not a payment.
      </p>
    <?php else: ?>
      <p style="margin-top:0;color:#24A68A;font-weight:700">This receipt is genuine.</p>
    <?php endif; ?>

    <div class="big" style="margin:14px 0 18px">
      <?= h($payment['currency']) ?> <?= h(number_format((float) $payment['amount'], 2)) ?>
    </div>

    <table>
      <tr><th>Received from</th><td><?= h(first_name((string) $payment['full_name'])) ?></td></tr>
      <tr><th>Date</th><td><?= h($payment['paid_on']) ?></td></tr>
      <tr><th>Program</th><td><?= h($payment['course_title']) ?></td></tr>
      <tr><th>Class</th><td><?= h($payment['intake_name']) ?></td></tr>
      <tr><th>Receipt code</th><td style="font-family:monospace"><?= h($payment['verify_code']) ?></td></tr>
    </table>

    <p class="muted" style="margin-bottom:0">
      Only the first name is shown here, on purpose. If any of this does not
      match your slip, call the office on <strong>+252&nbsp;770&nbsp;51&nbsp;90&nbsp;98</strong>.
    </p>
  </div>
  <p style="text-align:center"><a href="./verify.php" style="color:#24A68A;font-weight:600">Check another code</a></p>

<?php elseif ($state === 'notfound'): ?>
  <div class="card">
    <p class="bad" style="margin-top:0">No receipt matches that code.</p>
    <p style="margin-bottom:0">
      Check the letters again — the code has no letter I, L, O or U, so what
      looks like one of those is a 1, a 0 or something else. If it still does
      not work, call the office on <strong>+252&nbsp;770&nbsp;51&nbsp;90&nbsp;98</strong>
      and read the code out.
    </p>
  </div>

<?php elseif ($state === 'slowdown'): ?>
  <div class="card">
    <p class="bad" style="margin-top:0">Too many failed checks from this connection.</p>
    <p style="margin-bottom:0">
      Wait an hour and try again, or call the office on
      <strong>+252&nbsp;770&nbsp;51&nbsp;90&nbsp;98</strong> and read your code out.
    </p>
  </div>

<?php elseif ($state === 'error'): ?>
  <div class="card">
    <p class="bad" style="margin-top:0">We could not check that right now.</p>
    <p style="margin-bottom:0">
      Nothing is wrong with your receipt — our system could not answer. Try
      again shortly, or call <strong>+252&nbsp;770&nbsp;51&nbsp;90&nbsp;98</strong>.
    </p>
  </div>
<?php endif; ?>

<?php if ($state !== 'found'): ?>
  <div class="card">
    <form method="get">
      <label for="c">Receipt code</label>
      <input type="text" id="c" name="c" maxlength="12" required autofocus
             autocapitalize="characters" autocomplete="off" spellcheck="false"
             placeholder="ABCD2345EFGH" value="<?= h($code) ?>">
      <button type="submit">Check this receipt</button>
    </form>
    <p class="muted" style="margin-bottom:0">
      The code is printed on the receipt you were given. Twelve characters,
      no spaces.
    </p>
  </div>
<?php endif; ?>

  <p class="muted" style="text-align:center">
    A payment recorded by MPC is never edited or deleted. If something is
    corrected, it appears as a separate entry that you can check here too.
  </p>

</div>
</body>
</html>
