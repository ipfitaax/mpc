<?php
/**
 * Record a payment. This is the screen the whole project exists for.
 *
 * ONE SCREEN, ONE SUBMIT. A student hands over cash at the desk. If recording
 * that takes three pages — create the student, create the enrolment, then the
 * payment — the office keeps the notebook for walk-ins and uses this only for
 * the tidy cases. So the student and the enrolment are created inline, in the
 * same transaction as the payment, and either all of it lands or none does.
 *
 * SEARCH IS A ROUND TRIP, NOT LIVE FILTERING. Typing a name and pressing Find
 * costs one request. The alternative — shipping the whole student roster into
 * the page and filtering in the browser — is faster and was rejected: it puts
 * every student's name and phone number in the cache and back-button history
 * of a machine several staff share.
 *
 * THE RECEIPT IS NOT RENDERED HERE. A successful POST commits and then
 * redirects; receipt.php re-reads the committed row. If the transaction did
 * not commit there is no row, so there is no receipt — a receipt cannot exist
 * for a payment that does not. That is this repository's founding rule
 * (README.md:41) applied to money, and it also makes a refresh reprint rather
 * than re-charge.
 */

declare(strict_types=1);

require_once dirname(__DIR__) . '/lib/auth.php';
require_once dirname(__DIR__) . '/lib/page.php';
require_once dirname(__DIR__) . '/lib/ledger.php';

$user = mpc_require_login();
$db   = mpc_db();

$error = null;
$q     = trim((string) ($_GET['q'] ?? ''));

$intakes = $db->query(
    "SELECT i.id, i.name, i.starts_on, c.title AS course_title,
            c.fee_amount, c.duration_months
       FROM intakes i JOIN courses c ON c.id = i.course_id
      WHERE i.status IN ('planned','open','running')
      ORDER BY i.starts_on DESC"
)->fetchAll();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    mpc_csrf_check();

    $studentId = (string) ($_POST['student_id'] ?? '');
    $isNew     = $studentId === 'new';
    $name      = trim((string) ($_POST['name'] ?? ''));
    $phone     = trim((string) ($_POST['phone'] ?? ''));
    $intakeId  = (int) ($_POST['intake_id'] ?? 0);
    $amount    = trim((string) ($_POST['amount'] ?? ''));
    $method    = (string) ($_POST['method'] ?? 'cash');
    $paidOn    = trim((string) ($_POST['paid_on'] ?? ''));
    $reference = trim((string) ($_POST['reference'] ?? ''));
    $note      = trim((string) ($_POST['note'] ?? ''));

    // Validated in reading order, so the first complaint is about the first
    // field they filled in.
    if ($studentId === '') {
        $error = 'Choose a student, or add a new one.';
    } elseif ($isNew && $name === '') {
        $error = 'A new student needs a name.';
    } elseif (! $intakeId) {
        $error = 'Choose which class this payment is for.';
    } elseif (! preg_match('/^\d+(\.\d{1,2})?$/', $amount) || (float) $amount <= 0) {
        // Rejects negatives, letters, and more than two decimal places. A
        // negative here would be a reversal without a reason attached, and an
        // append-only ledger cannot take that back.
        $error = 'Enter an amount in USD, like 100 or 100.50.';
    } elseif ((float) $amount > 100000) {
        // Not a real limit, a typo catcher. A slipped keypress turning 100 into
        // 10000000 is a permanent row that can only be annotated, never fixed.
        $error = 'That amount looks wrong. If it is right, record it in parts.';
    } elseif (! preg_match('/^\d{4}-\d{2}-\d{2}$/', $paidOn) || ! strtotime($paidOn)) {
        $error = 'The payment date is required, as YYYY-MM-DD.';
    } elseif ($paidOn > date('Y-m-d')) {
        $error = 'That date is in the future.';
    } elseif (! in_array($method, ['cash', 'evc', 'zaad', 'edahab', 'bank', 'other'], true)) {
        $error = 'Unknown payment method.';
    } else {
        try {
            $paymentId = mpc_record_payment([
                'student_id'  => $isNew ? null : (int) $studentId,
                'name'        => $name,
                'phone'       => $phone,
                'intake_id'   => $intakeId,
                'amount'      => $amount,
                'method'      => $method,
                'paid_on'     => $paidOn,
                'reference'   => $reference,
                'note'        => $note,
                'recorded_by' => (int) $user['id'],
            ]);

            // Committed. Only now is there something to print.
            header('Location: ./receipt.php?id=' . $paymentId, true, 303);
            exit;
        } catch (Throwable $e) {
            $error = 'Nothing was recorded: ' . $e->getMessage();
        }
    }
}

$matches = $q !== '' ? mpc_find_students($q) : [];

$recent = $db->query(
    'SELECT p.id, p.amount, p.paid_on, p.verify_code, p.reverses_payment_id,
            u.full_name, i.name AS intake_name
       FROM payments p
       JOIN enrollments e ON e.id = p.enrollment_id
       JOIN users u       ON u.id = e.user_id
       JOIN intakes i     ON i.id = e.intake_id
      ORDER BY p.id DESC LIMIT 10'
)->fetchAll();

mpc_page_head('Record a payment', $user);

if (! $intakes) {
    echo '<div class="card"><p class="msg-bad">There are no open classes.</p>'
       . '<p class="muted">A payment is recorded against a class, so one has to exist first. '
       . '<a href="./intakes.php">Create an intake</a>.</p></div>';
    mpc_page_foot();
    exit;
}
?>

<div class="card">
  <form method="get" style="display:flex;gap:12px;flex-wrap:wrap;align-items:flex-end">
    <div style="flex:1;min-width:240px">
      <label for="q">Find the student</label>
      <input type="search" id="q" name="q" value="<?= e($q) ?>" autofocus
             placeholder="Name or phone number">
    </div>
    <button type="submit">Find</button>
  </form>
  <?php if ($q !== '' && ! $matches): ?>
    <p class="muted" style="margin-bottom:0">
      No student matches &ldquo;<?= e($q) ?>&rdquo;. Add them as a new student below.
    </p>
  <?php endif; ?>
</div>

<div class="card">
  <h2 style="font-size:1.05rem">Payment</h2>

  <form method="post">
    <?php mpc_csrf_field(); ?>

    <fieldset style="border:1px solid <?= MPC_BORDER ?>;border-radius:6px;padding:16px;margin:0 0 20px">
      <legend style="font-size:.85rem;font-weight:700;color:<?= MPC_HEADING ?>;padding:0 6px">Student</legend>

      <?php foreach ($matches as $m): ?>
        <label style="display:flex;gap:10px;align-items:flex-start;font-weight:400;margin-bottom:10px">
          <input type="radio" name="student_id" value="<?= (int) $m['id'] ?>"
                 style="width:auto;margin-top:4px"
                 <?= (string) ($_POST['student_id'] ?? '') === (string) $m['id'] ? 'checked' : '' ?>>
          <span>
            <strong><?= e($m['full_name']) ?></strong>
            <?= $m['phone'] ? ' &middot; ' . e($m['phone']) : '' ?>
            <?php if ($m['intakes']): ?>
              <div class="muted"><?= e($m['intakes']) ?></div>
            <?php endif; ?>
          </span>
        </label>
      <?php endforeach; ?>

      <label style="display:flex;gap:10px;align-items:center;font-weight:400;margin-bottom:12px">
        <input type="radio" name="student_id" value="new" style="width:auto"
               <?= (string) ($_POST['student_id'] ?? ($matches ? '' : 'new')) === 'new' ? 'checked' : '' ?>>
        <span><strong>New student</strong> &mdash; not in the system yet</span>
      </label>

      <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:16px">
        <div>
          <label for="name">Full name</label>
          <input type="text" id="name" name="name" maxlength="160"
                 value="<?= e($_POST['name'] ?? '') ?>">
        </div>
        <div>
          <label for="phone">Phone number</label>
          <input type="tel" id="phone" name="phone" maxlength="32"
                 value="<?= e($_POST['phone'] ?? '') ?>">
          <p class="muted" style="margin:6px 0 0">Only used when adding a new student.</p>
        </div>
      </div>
    </fieldset>

    <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:16px;margin-bottom:16px">
      <div>
        <label for="intake_id">Class</label>
        <select id="intake_id" name="intake_id" required>
          <option value="">Select</option>
          <?php foreach ($intakes as $i): ?>
            <option value="<?= (int) $i['id'] ?>"
                    <?= (int) ($_POST['intake_id'] ?? 0) === (int) $i['id'] ? 'selected' : '' ?>>
              <?= e($i['name']) ?> &mdash; <?= e(mpc_money($i['fee_amount'])) ?>/month
            </option>
          <?php endforeach; ?>
        </select>
      </div>
      <div>
        <label for="amount">Amount received (USD)</label>
        <input type="text" inputmode="decimal" id="amount" name="amount" required
               placeholder="100.00" value="<?= e($_POST['amount'] ?? '') ?>">
      </div>
      <div>
        <label for="paid_on">Date received</label>
        <input type="date" id="paid_on" name="paid_on" required
               value="<?= e($_POST['paid_on'] ?? date('Y-m-d')) ?>">
      </div>
    </div>

    <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:16px;margin-bottom:20px">
      <div>
        <label for="method">How it was paid</label>
        <select id="method" name="method">
          <?php foreach (['cash' => 'Cash', 'evc' => 'EVC Plus', 'zaad' => 'Zaad',
                          'edahab' => 'eDahab', 'bank' => 'Bank transfer',
                          'other' => 'Other'] as $v => $label): ?>
            <option value="<?= e($v) ?>" <?= ($_POST['method'] ?? 'cash') === $v ? 'selected' : '' ?>>
              <?= e($label) ?>
            </option>
          <?php endforeach; ?>
        </select>
      </div>
      <div>
        <label for="reference">Reference</label>
        <input type="text" id="reference" name="reference" maxlength="80"
               value="<?= e($_POST['reference'] ?? '') ?>">
        <p class="muted" style="margin:6px 0 0">
          Mobile-money transaction id &mdash; or, if they paid in shillings,
          the amount they handed over and the rate used.
        </p>
      </div>
      <div>
        <label for="note">Note</label>
        <input type="text" id="note" name="note" maxlength="255"
               value="<?= e($_POST['note'] ?? '') ?>">
      </div>
    </div>

    <button type="submit">Record payment and print receipt</button>
    <?php mpc_message($error, false); ?>
    <p class="muted" style="margin-bottom:0">
      A recorded payment cannot be edited or deleted. A mistake is corrected
      with a reversal, which stays visible on the student&rsquo;s history.
    </p>
  </form>
</div>

<?php if ($recent): ?>
<div class="card">
  <h2 style="font-size:1.05rem">Last ten payments</h2>
  <table>
    <thead>
      <tr><th>Student</th><th>Class</th><th>Date</th><th class="num">Amount</th><th>Code</th><th></th></tr>
    </thead>
    <tbody>
      <?php foreach ($recent as $r): ?>
        <tr>
          <td><?= e($r['full_name']) ?></td>
          <td><?= e($r['intake_name']) ?></td>
          <td><?= e($r['paid_on']) ?></td>
          <td class="num"><?= e(mpc_money($r['amount'])) ?></td>
          <td><code><?= e($r['verify_code']) ?></code></td>
          <td><a href="./receipt.php?id=<?= (int) $r['id'] ?>">Receipt</a></td>
        </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
</div>
<?php endif; ?>

<?php
mpc_page_foot();
