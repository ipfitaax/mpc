<?php
/**
 * The receipt the student walks away with.
 *
 * READS THE COMMITTED ROW. It is reached by redirect after the payment
 * transaction commits, and it looks the payment up by id. If the transaction
 * failed there is no row and this page says so instead of printing anything.
 * A receipt cannot exist for a payment that does not — which is the rule this
 * repository was built on, applied to money rather than to an enquiry form.
 *
 * It also means a refresh reprints rather than re-charges, and the office can
 * come back to any payment later and print it again.
 */

declare(strict_types=1);

require_once dirname(__DIR__) . '/lib/auth.php';
require_once dirname(__DIR__) . '/lib/page.php';
require_once dirname(__DIR__) . '/lib/ledger.php';

$user    = mpc_require_login();
$id      = (int) ($_GET['id'] ?? 0);
$payment = $id > 0 ? mpc_payment($id) : null;

mpc_page_head('Receipt', $user);

if (! $payment) {
    echo '<div class="card"><p class="msg-bad">There is no payment with that number.</p>'
       . '<p class="muted">If you were recording a payment and landed here, it was '
       . 'not saved. Nothing was taken from the student&rsquo;s record and nothing '
       . 'was charged. <a href="./payments.php">Try again</a>.</p></div>';
    mpc_page_foot();
    exit;
}

$balance    = mpc_enrollment_balance((int) $payment['enrollment_id']);
$isReversal = $payment['reverses_payment_id'] !== null;
?>

<div class="no-print" style="margin-bottom:20px">
  <button type="button" onclick="window.print()">Print this receipt</button>
  <a href="./payments.php" style="margin-left:16px;font-weight:600">Record another payment</a>
  <?php if (! $isReversal): ?>
    <a href="./reverse.php?id=<?= (int) $payment['id'] ?>"
       style="margin-left:16px;font-weight:600;color:<?= MPC_RED ?>">Reverse this payment</a>
  <?php endif; ?>
</div>

<div class="card">
  <div style="display:flex;justify-content:space-between;align-items:flex-start;gap:20px;flex-wrap:wrap">
    <div>
      <div style="display:flex;align-items:center;gap:10px;margin-bottom:6px">
        <span style="width:40px;height:40px;background:<?= MPC_GREEN ?>;color:#fff;font-weight:800;
                     display:flex;align-items:center;justify-content:center;border-radius:6px">MPC</span>
        <strong style="color:<?= MPC_HEADING ?>">Mogadishu Professional Certificate</strong>
      </div>
      <p class="muted" style="margin:0">Learn by Doing It &middot; +252 770 51 90 98</p>
    </div>
    <div style="text-align:right">
      <p class="muted" style="margin:0">Receipt</p>
      <p style="margin:0;font-size:1.3rem;font-weight:800;font-family:monospace;
                letter-spacing:.08em;color:<?= MPC_HEADING ?>">
        <?= e($payment['verify_code']) ?>
      </p>
    </div>
  </div>

  <hr style="border:none;border-top:1px solid <?= MPC_BORDER ?>;margin:22px 0">

  <?php if ($isReversal): ?>
    <p class="msg-bad" style="margin-top:0">
      This is a reversal of payment #<?= (int) $payment['reverses_payment_id'] ?>.
      <?= $payment['reversal_reason'] ? e($payment['reversal_reason']) : '' ?>
    </p>
  <?php endif; ?>

  <table style="margin-bottom:22px">
    <tr><th style="width:38%">Student</th>
        <td><strong><?= e($payment['full_name']) ?></strong>
            <?= $payment['phone'] ? ' &middot; ' . e($payment['phone']) : '' ?></td></tr>
    <tr><th>Program</th><td><?= e($payment['course_title']) ?></td></tr>
    <tr><th>Class</th><td><?= e($payment['intake_name']) ?></td></tr>
    <tr><th>Date received</th><td><?= e($payment['paid_on']) ?></td></tr>
    <tr><th>Paid by</th><td><?= e(strtoupper((string) $payment['method'])) ?></td></tr>
    <?php if ($payment['reference']): ?>
      <tr><th>Reference</th><td><?= e($payment['reference']) ?></td></tr>
    <?php endif; ?>
    <?php if ($payment['note']): ?>
      <tr><th>Note</th><td><?= e($payment['note']) ?></td></tr>
    <?php endif; ?>
    <tr><th>Received by</th><td><?= e($payment['recorded_by_name'] ?? '—') ?></td></tr>
  </table>

  <div style="background:#f7faf9;border:1px solid <?= MPC_BORDER ?>;border-radius:6px;padding:18px;margin-bottom:22px">
    <div style="display:flex;justify-content:space-between;align-items:baseline">
      <strong style="color:<?= MPC_HEADING ?>;font-size:1.05rem">Amount received</strong>
      <strong style="font-size:1.5rem;color:<?= MPC_HEADING ?>;font-variant-numeric:tabular-nums">
        <?= e(mpc_money($payment['amount'])) ?>
      </strong>
    </div>
  </div>

  <table>
    <tr><th style="width:38%">Course fee</th>
        <td class="num"><?= e(mpc_money($balance['monthly'])) ?> &times;
            <?= (int) $balance['months'] ?> months =
            <strong><?= e(mpc_money($balance['total'])) ?></strong></td></tr>
    <tr><th>Paid to date</th>
        <td class="num"><?= e(mpc_money($balance['paid'])) ?>
            <span class="muted">(<?= (int) $balance['months_paid'] ?> of
            <?= (int) $balance['months'] ?> months)</span></td></tr>
    <tr><th>Remaining</th>
        <td class="num"><strong><?= e(mpc_money($balance['balance'])) ?></strong></td></tr>
  </table>

  <hr style="border:none;border-top:1px solid <?= MPC_BORDER ?>;margin:22px 0">

  <p class="muted" style="margin:0">
    Keep this receipt. You can check it yourself at
    <strong>mpc.so/verify</strong> using the code
    <strong style="font-family:monospace"><?= e($payment['verify_code']) ?></strong>.
    If anything here is wrong, tell the office today &mdash; a recorded payment
    is never edited, so a correction is added as a separate entry that both
    sides can see.
  </p>
</div>

<?php
mpc_page_foot();
