<?php
/**
 * Students — who is enrolled, what they have paid, and who might be a duplicate.
 *
 * WHAT THIS SCREEN IS FOR
 * Success criterion 2 of the design: "Did Amina pay for the January intake?"
 * answered in under ten seconds without leaving the desk. That is the question
 * this page exists to answer, and everything on it is arranged around it.
 *
 * WHY DUPLICATES GET THEIR OWN SECTION
 * A student recorded twice breaks that criterion directly — half the payments
 * under one row, half under the other, and the balance wrong on both. Somali
 * name transliteration varies (Amina, Aamina, Aamino), so this is not a rare
 * accident; it is the expected failure of typing a name at a busy desk.
 *
 * And it is the one mistake this system cannot clean up. Moving a payment to
 * the correct student needs an UPDATE on payments, which nothing has and
 * nothing will. The recovery is to reverse the payment and re-enter it under
 * the right person, which leaves both rows visible forever. So the cheap fix is
 * noticing early, which is what this page is for.
 */

declare(strict_types=1);

require_once dirname(__DIR__) . '/lib/auth.php';
require_once dirname(__DIR__) . '/lib/page.php';
require_once dirname(__DIR__) . '/lib/ledger.php';

$user = mpc_require_login();
$db   = mpc_db();

$id = (int) ($_GET['id'] ?? 0);
$q  = trim((string) ($_GET['q'] ?? ''));

// ---------------------------------------------------------------------------
// One student, in full.
// ---------------------------------------------------------------------------
if ($id > 0) {
    $stmt = $db->prepare("SELECT * FROM users WHERE id = ? AND role = 'student'");
    $stmt->execute([$id]);
    $student = $stmt->fetch() ?: null;

    if (! $student) {
        mpc_page_head('Student', $user);
        echo '<div class="card"><p class="msg-bad">No student with that number.</p>'
           . '<p><a href="./students.php">Back to the list</a></p></div>';
        mpc_page_foot();
        exit;
    }

    $stmt = $db->prepare(
        'SELECT e.id, e.status, e.fee_agreed, e.fee_months, e.enrolled_at,
                i.name AS intake_name, i.starts_on, c.title AS course_title
           FROM enrollments e
           JOIN intakes i ON i.id = e.intake_id
           JOIN courses c ON c.id = i.course_id
          WHERE e.user_id = ?
          ORDER BY i.starts_on DESC'
    );
    $stmt->execute([$id]);
    $enrolments = $stmt->fetchAll();

    mpc_page_head('Student', $user);
    ?>
    <div class="card">
      <div style="display:flex;justify-content:space-between;gap:20px;flex-wrap:wrap">
        <div>
          <h2 style="font-size:1.25rem;margin-bottom:4px"><?= e($student['full_name']) ?></h2>
          <p class="muted" style="margin:0">
            <?= $student['phone'] ? e($student['phone']) : 'No phone number recorded' ?>
            <?= $student['email'] ? ' &middot; ' . e($student['email']) : '' ?>
          </p>
        </div>
        <a href="./payments.php?q=<?= urlencode($student['full_name']) ?>"
           style="font-weight:600;white-space:nowrap">Record a payment</a>
      </div>
    </div>

    <?php if (! $enrolments): ?>
      <div class="card"><p class="muted" style="margin:0">
        Not enrolled in any class yet. A student is enrolled by recording their
        first payment.
      </p></div>
    <?php endif; ?>

    <?php foreach ($enrolments as $en):
        $bal = mpc_enrollment_balance((int) $en['id']);
        $stmt = $db->prepare(
            'SELECT p.*, s.full_name AS recorded_by_name
               FROM payments p
               LEFT JOIN users s ON s.id = p.recorded_by
              WHERE p.enrollment_id = ?
              ORDER BY p.paid_on, p.id'
        );
        $stmt->execute([$en['id']]);
        $payments = $stmt->fetchAll();
    ?>
      <div class="card">
        <h2 style="font-size:1.05rem;margin-bottom:2px"><?= e($en['intake_name']) ?></h2>
        <p class="muted" style="margin-top:0"><?= e($en['course_title']) ?> &middot; <?= e($en['status']) ?></p>

        <div style="display:flex;gap:26px;flex-wrap:wrap;background:#f7faf9;border:1px solid <?= MPC_BORDER ?>;
                    border-radius:6px;padding:16px;margin-bottom:18px">
          <div><div class="muted">Course fee</div>
               <strong><?= e(mpc_money($bal['total'])) ?></strong>
               <div class="muted"><?= e(mpc_money($bal['monthly'])) ?> &times; <?= (int) $bal['months'] ?></div></div>
          <div><div class="muted">Paid</div>
               <strong><?= e(mpc_money($bal['paid'])) ?></strong>
               <div class="muted"><?= (int) $bal['months_paid'] ?> of <?= (int) $bal['months'] ?> months</div></div>
          <div><div class="muted">Remaining</div>
               <strong style="<?= $bal['balance'] <= 0 ? 'color:' . MPC_GREEN : '' ?>">
                 <?= e(mpc_money($bal['balance'])) ?></strong>
               <?php if ($bal['balance'] <= 0): ?><div class="muted">Paid in full</div><?php endif; ?></div>
        </div>

        <?php if (! $payments): ?>
          <p class="muted" style="margin:0">No payments recorded.</p>
        <?php else: ?>
          <table>
            <thead><tr>
              <th>Date</th><th class="num">Amount</th><th>How</th>
              <th>Code</th><th>Taken by</th><th></th>
            </tr></thead>
            <tbody>
              <?php foreach ($payments as $p):
                  $isReversal = $p['reverses_payment_id'] !== null; ?>
                <tr<?= $isReversal ? ' style="background:#fdf3f2"' : '' ?>>
                  <td><?= e($p['paid_on']) ?></td>
                  <td class="num"><?= e(mpc_money($p['amount'])) ?></td>
                  <td><?= e(strtoupper((string) $p['method'])) ?>
                      <?php if ($p['reference']): ?><div class="muted"><?= e($p['reference']) ?></div><?php endif; ?>
                      <?php if ($isReversal): ?>
                        <div class="msg-bad" style="font-size:.85rem">
                          Reversal of #<?= (int) $p['reverses_payment_id'] ?>
                          <?= $p['reversal_reason'] ? ' &mdash; ' . e($p['reversal_reason']) : '' ?>
                        </div>
                      <?php endif; ?>
                  </td>
                  <td><code><?= e($p['verify_code']) ?></code></td>
                  <td><?= $p['recorded_by_name'] ? e($p['recorded_by_name']) : '<span class="muted">&mdash;</span>' ?></td>
                  <td><a href="./receipt.php?id=<?= (int) $p['id'] ?>">Receipt</a>
                      <?php if (! $isReversal): ?>
                        <a href="./reverse.php?id=<?= (int) $p['id'] ?>"
                           style="color:<?= MPC_RED ?>;margin-left:8px">Reverse</a>
                      <?php endif; ?></td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        <?php endif; ?>
      </div>
    <?php endforeach; ?>

    <p><a href="./students.php" style="font-weight:600">Back to all students</a></p>
    <?php
    mpc_page_foot();
    exit;
}

// ---------------------------------------------------------------------------
// The list.
// ---------------------------------------------------------------------------
// Totals are aggregated in SQL rather than per row in PHP. At MPC's size either
// would be instant, but a query per student is the habit that stops being
// instant without anyone noticing when it does.
$params = [];
$where  = "u.role = 'student'";
if ($q !== '') {
    $like   = '%' . str_replace(['%', '_'], ['\%', '\_'], $q) . '%';
    $where .= ' AND (u.full_name LIKE ? OR u.phone LIKE ?)';
    $params = [$like, $like];
}

$stmt = $db->prepare(
    "SELECT u.id, u.full_name, u.phone,
            COUNT(DISTINCT e.id) AS enrolments,
            COALESCE(SUM(pay.paid), 0) AS paid,
            COALESCE(SUM(e.fee_agreed * e.fee_months), 0) AS owed
       FROM users u
       LEFT JOIN enrollments e ON e.user_id = u.id
       LEFT JOIN (SELECT enrollment_id, SUM(amount) AS paid FROM payments GROUP BY enrollment_id) pay
              ON pay.enrollment_id = e.id
      WHERE $where
      GROUP BY u.id
      ORDER BY u.full_name
      LIMIT 200"
);
$stmt->execute($params);
$students = $stmt->fetchAll();

// Students sharing a phone number. Exact match only, and that is a deliberate
// limit: it is the signal with almost no false alarms. Two people really can
// share a handset — siblings, a parent's phone — so this asks rather than
// asserts. Fuzzy name matching was considered and left out; on Somali names an
// English-tuned SOUNDEX produces enough noise that the office would learn to
// ignore the whole section, which is worse than not having it.
$dupes = $db->query(
    "SELECT phone, COUNT(*) AS n,
            GROUP_CONCAT(CONCAT(id, ':', full_name) ORDER BY full_name SEPARATOR '|') AS people
       FROM users
      WHERE role = 'student' AND phone IS NOT NULL AND phone <> ''
      GROUP BY phone HAVING n > 1"
)->fetchAll();

mpc_page_head('Students', $user);
?>

<?php if ($dupes): ?>
  <div class="card" style="border-color:<?= MPC_RED ?>">
    <h2 style="font-size:1.05rem;color:<?= MPC_RED ?>">Possibly the same person twice</h2>
    <p style="margin-top:0">
      These students share a phone number. Sometimes that is real &mdash; a
      brother and sister, or a parent&rsquo;s handset. Sometimes it is one
      student typed in twice, which splits their payments across two records and
      makes both balances wrong.
    </p>
    <table>
      <thead><tr><th>Phone</th><th>Records</th></tr></thead>
      <tbody>
        <?php foreach ($dupes as $d): ?>
          <tr>
            <td><?= e($d['phone']) ?></td>
            <td>
              <?php foreach (explode('|', (string) $d['people']) as $person):
                  [$pid, $pname] = explode(':', $person, 2); ?>
                <a href="./students.php?id=<?= (int) $pid ?>"><?= e($pname) ?></a>
                <span class="muted">&nbsp;</span>
              <?php endforeach; ?>
            </td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
    <p class="muted" style="margin-bottom:0">
      There is no merge button, and there will not be one. Payments cannot be
      moved between students &mdash; a recorded payment is never edited. If two
      records really are one person, reverse the payments on the wrong one and
      record them again under the right one. Both entries stay visible, which is
      the honest history of what happened.
    </p>
  </div>
<?php endif; ?>

<div class="card">
  <form method="get" style="display:flex;gap:12px;flex-wrap:wrap;align-items:flex-end">
    <div style="flex:1;min-width:240px">
      <label for="q">Search students</label>
      <input type="search" id="q" name="q" value="<?= e($q) ?>" placeholder="Name or phone number">
    </div>
    <button type="submit">Search</button>
    <?php if ($q !== ''): ?>
      <a href="./students.php" style="font-weight:600;padding-bottom:12px">Clear</a>
    <?php endif; ?>
  </form>
</div>

<div class="card">
  <h2 style="font-size:1.05rem">
    <?= $q !== '' ? 'Matching students' : 'All students' ?>
    <span class="muted">(<?= count($students) ?><?= count($students) === 200 ? '+, showing first 200' : '' ?>)</span>
  </h2>

  <?php if (! $students): ?>
    <p class="muted" style="margin:0">
      <?= $q !== ''
          ? 'Nobody matches &ldquo;' . e($q) . '&rdquo;.'
          : 'No students yet. A student is added by recording their first payment.' ?>
    </p>
  <?php else: ?>
    <table>
      <thead><tr>
        <th>Name</th><th>Phone</th><th class="num">Classes</th>
        <th class="num">Paid</th><th class="num">Remaining</th>
      </tr></thead>
      <tbody>
        <?php foreach ($students as $s):
            $remaining = (float) $s['owed'] - (float) $s['paid']; ?>
          <tr>
            <td><a href="./students.php?id=<?= (int) $s['id'] ?>"
                   style="font-weight:600"><?= e($s['full_name']) ?></a></td>
            <td><?= $s['phone'] ? e($s['phone']) : '<span class="muted">&mdash;</span>' ?></td>
            <td class="num"><?= (int) $s['enrolments'] ?></td>
            <td class="num"><?= e(mpc_money($s['paid'])) ?></td>
            <td class="num">
              <?php if ((int) $s['enrolments'] === 0): ?>
                <span class="muted">&mdash;</span>
              <?php elseif ($remaining <= 0): ?>
                <span style="color:<?= MPC_GREEN ?>;font-weight:600">Paid in full</span>
              <?php else: ?>
                <?= e(mpc_money($remaining)) ?>
              <?php endif; ?>
            </td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  <?php endif; ?>
</div>

<?php
mpc_page_foot();
