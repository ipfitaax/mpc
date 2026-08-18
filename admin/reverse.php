<?php
/**
 * Reverse a payment.
 *
 * THIS IS A ONE-WAY DOOR AND THE SCREEN SAYS SO TWICE.
 * A reversal cannot itself be undone. It is a permanent entry on the student's
 * history, visible to them on the verify page, and there is no edit and no
 * delete anywhere in this system. So this page asks for a written reason and an
 * explicit tick before it will do anything — not as ceremony, but because the
 * cost of a careless click here cannot be refunded by a later click.
 *
 * WHY THE ANSWER TO A MISTAKE IS ANOTHER ENTRY
 * The alternative is editing the original, which is what the notebook does when
 * somebody crosses a line out. That is exactly the state this project was built
 * to leave: a record only one side can vouch for. Two visible entries — the
 * payment and its cancellation — are a history both sides can read, and the
 * student can check either of them from their own phone.
 */

declare(strict_types=1);

require_once dirname(__DIR__) . '/lib/auth.php';
require_once dirname(__DIR__) . '/lib/page.php';
require_once dirname(__DIR__) . '/lib/ledger.php';

$user    = mpc_require_login();
$id      = (int) ($_GET['id'] ?? $_POST['id'] ?? 0);
$payment = $id > 0 ? mpc_payment($id) : null;
$error   = null;

mpc_page_head('Reverse a payment', $user);

if (! $payment) {
    echo '<div class="card"><p class="msg-bad">There is no payment with that number.</p>'
       . '<p><a href="./payments.php">Back to payments</a></p></div>';
    mpc_page_foot();
    exit;
}

// Both of these are re-checked inside mpc_reverse_payment(), which is where the
// row is locked and the decision is actually safe. Checking here too is not
// duplication for its own sake: it lets the office see WHY the button is not
// there, instead of finding out by pressing it.
$db      = mpc_db();
$stmt    = $db->prepare('SELECT id FROM payments WHERE reverses_payment_id = ?');
$stmt->execute([$id]);
$already = $stmt->fetchColumn();

$isItselfAReversal = $payment['reverses_payment_id'] !== null;

if ($isItselfAReversal) {
    echo '<div class="card"><p class="msg-bad">That entry is already a reversal.</p>'
       . '<p>Reversing a cancellation to put money back would leave a history '
       . 'nobody can read afterwards. If this student really did pay again, '
       . '<a href="./payments.php">record it as a new payment</a>.</p></div>';
    mpc_page_foot();
    exit;
}

if ($already) {
    echo '<div class="card"><p class="msg-bad">That payment has already been reversed.</p>'
       . '<p><a href="./receipt.php?id=' . (int) $already . '">See the reversal</a> &middot; '
       . '<a href="./students.php?id=' . (int) $payment['user_id'] . '">See the full history</a></p></div>';
    mpc_page_foot();
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    mpc_csrf_check();

    // The tick is checked before anything else so a missing one never produces
    // a half-done action, and so the message names the thing they skipped.
    if (empty($_POST['understood'])) {
        $error = 'Tick the box to confirm you understand this cannot be undone.';
    } else {
        try {
            $reversalId = mpc_reverse_payment(
                $id,
                (string) ($_POST['reason'] ?? ''),
                (int) $user['id']
            );

            // Straight to the reversal's own receipt. The student should walk
            // away with paper proof of the correction exactly as they did for
            // the payment — a cancellation nobody can show is the same problem
            // in a different direction.
            header('Location: ./receipt.php?id=' . $reversalId, true, 303);
            exit;
        } catch (Throwable $e) {
            $error = $e->getMessage();
        }
    }
}

$balance = mpc_enrollment_balance((int) $payment['enrollment_id']);
?>

<div class="card" style="border-color:<?= MPC_RED ?>">
  <h2 style="font-size:1.05rem;color:<?= MPC_RED ?>;margin-bottom:14px">
    You are about to cancel this payment
  </h2>

  <table style="margin-bottom:18px">
    <tr><th style="width:38%">Student</th>
        <td><strong><?= e($payment['full_name']) ?></strong></td></tr>
    <tr><th>Class</th><td><?= e($payment['intake_name']) ?></td></tr>
    <tr><th>Amount</th>
        <td><strong style="font-size:1.15rem"><?= e(mpc_money($payment['amount'])) ?></strong></td></tr>
    <tr><th>Date received</th><td><?= e($payment['paid_on']) ?></td></tr>
    <tr><th>Receipt code</th><td><code><?= e($payment['verify_code']) ?></code></td></tr>
    <tr><th>Taken by</th><td><?= e($payment['recorded_by_name'] ?? '') ?></td></tr>
  </table>

  <p style="margin-bottom:6px">
    After this, <strong><?= e($payment['full_name']) ?></strong> will owe
    <strong><?= e(mpc_money($balance['balance'] + (float) $payment['amount'])) ?></strong>
    instead of <?= e(mpc_money($balance['balance'])) ?>.
  </p>
  <p class="muted" style="margin-top:0">
    The original payment is not deleted. Both it and this cancellation stay on
    the record, and the student can check either one at mpc.so/verify.
  </p>
</div>

<div class="card">
  <form method="post">
    <?php mpc_csrf_field(); ?>
    <input type="hidden" name="id" value="<?= (int) $payment['id'] ?>">

    <div style="margin-bottom:18px">
      <label for="reason">Why is this being reversed?</label>
      <input type="text" id="reason" name="reason" required minlength="5" maxlength="255" autofocus
             placeholder="Recorded against the wrong student"
             value="<?= e($_POST['reason'] ?? '') ?>">
      <p class="muted" style="margin:6px 0 0">
        Written on the record permanently, and shown to anyone who reads this
        student&rsquo;s history. Write it for somebody reading it in a year.
      </p>
    </div>

    <label style="display:flex;gap:10px;align-items:flex-start;font-weight:400;margin-bottom:20px">
      <input type="checkbox" name="understood" value="1" style="width:auto;margin-top:4px">
      <span>I understand this cannot be undone, and that both entries stay
            visible on the student&rsquo;s record.</span>
    </label>

    <button type="submit" style="background:<?= MPC_RED ?>;border-color:<?= MPC_RED ?>">
      Reverse this payment
    </button>
    <a href="./receipt.php?id=<?= (int) $payment['id'] ?>"
       style="margin-left:16px;font-weight:600">Cancel, leave it alone</a>

    <?php mpc_message($error, false); ?>
  </form>
</div>

<?php
mpc_page_foot();
