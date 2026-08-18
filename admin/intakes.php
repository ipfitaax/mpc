<?php
/**
 * Intakes — the classes that actually run.
 *
 * WHY THIS SCREEN EXISTS
 * An intake is one running of a course: a start date, a class, a teacher. The
 * schema comment above the table puts it plainly — it is "the table people
 * forget, and its absence is what forces 'which students were in the January
 * class?' to be answered from memory".
 *
 * Nothing else in the tool creates one. The payment screen creates a student
 * and an enrolment inline, but it cannot invent the class they are enrolling
 * in, because an intake is a real date only the office knows. Without this
 * screen somebody runs SQL every time a class starts, which means either the
 * office cannot start a class or a developer is on call to do it for them.
 *
 * Courses are NOT editable here. There are two of them, they are seeded in
 * ledger.sql, and their fees are a management decision rather than a desk one.
 * If MPC adds a third program, that is a conversation and then a migration.
 */

declare(strict_types=1);

require_once dirname(__DIR__) . '/lib/auth.php';
require_once dirname(__DIR__) . '/lib/page.php';

$user = mpc_require_login();
$db   = mpc_db();

$error  = null;
$notice = null;

// Post-Redirect-Get: a created intake comes back as ?created=NAME so a refresh
// re-renders the list instead of re-submitting the form. The payment screen
// will follow the same rule, for the much more serious reason that a
// double-submitted payment cannot be deleted.
if (isset($_GET['created'])) {
    $notice = 'Created intake "' . (string) $_GET['created'] . '".';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    mpc_csrf_check();

    $courseId = (int) ($_POST['course_id'] ?? 0);
    $name     = trim((string) ($_POST['name'] ?? ''));
    $startsOn = trim((string) ($_POST['starts_on'] ?? ''));
    $endsOn   = trim((string) ($_POST['ends_on'] ?? ''));
    $schedule = trim((string) ($_POST['schedule_note'] ?? ''));
    $capacity = trim((string) ($_POST['capacity'] ?? ''));
    $status   = (string) ($_POST['status'] ?? 'planned');

    $course = null;
    if ($courseId > 0) {
        $stmt = $db->prepare('SELECT id, title, duration_months FROM courses WHERE id = ?');
        $stmt->execute([$courseId]);
        $course = $stmt->fetch() ?: null;
    }

    // Validated in the order a person reads the form, so the first thing they
    // are told about is the first thing they see.
    if (! $course) {
        $error = 'Choose a program.';
    } elseif ($name === '') {
        $error = 'Give the intake a name, something the office would say out loud.';
    } elseif (! preg_match('/^\d{4}-\d{2}-\d{2}$/', $startsOn) || ! strtotime($startsOn)) {
        // An intake with no start date is exactly the "which January class?"
        // problem this table exists to solve, so it is required here even
        // though the column allows NULL.
        $error = 'A start date is required, as YYYY-MM-DD.';
    } elseif ($endsOn !== '' && (! preg_match('/^\d{4}-\d{2}-\d{2}$/', $endsOn) || $endsOn < $startsOn)) {
        $error = 'The end date must be on or after the start date.';
    } elseif ($capacity !== '' && (! ctype_digit($capacity) || (int) $capacity < 1)) {
        $error = 'Capacity must be a whole number, or left blank.';
    } elseif (! in_array($status, ['planned', 'open', 'running', 'finished', 'cancelled'], true)) {
        $error = 'Unknown status.';
    } else {
        // Offer an end date rather than demanding one. The course knows how many
        // months it runs, and the office should not do calendar arithmetic at
        // the desk with a student waiting. They can still override it.
        if ($endsOn === '' && $course['duration_months']) {
            $endsOn = date('Y-m-d', (int) strtotime($startsOn . ' +' . (int) $course['duration_months'] . ' months'));
        }

        try {
            $stmt = $db->prepare(
                'INSERT INTO intakes (course_id, name, starts_on, ends_on, schedule_note, capacity, status)
                 VALUES (?, ?, ?, ?, ?, ?, ?)'
            );
            $stmt->execute([
                $course['id'], $name, $startsOn,
                $endsOn !== '' ? $endsOn : null,
                $schedule !== '' ? $schedule : null,
                $capacity !== '' ? (int) $capacity : null,
                $status,
            ]);

            header('Location: ./intakes.php?created=' . urlencode($name));
            exit;
        } catch (PDOException $e) {
            $error = 'Could not save that intake: ' . $e->getMessage();
        }
    }
}

$courses = $db->query(
    'SELECT id, title, duration_months, fee_amount FROM courses ORDER BY display_order'
)->fetchAll();

// Enrolment and payment counts alongside each intake, because "is this class
// real yet" is answered by whether anyone has enrolled and paid.
$intakes = $db->query(
    "SELECT i.*, c.title AS course_title,
            (SELECT COUNT(*) FROM enrollments e WHERE e.intake_id = i.id) AS enrolled,
            (SELECT COALESCE(SUM(p.amount), 0)
               FROM payments p
               JOIN enrollments e2 ON e2.id = p.enrollment_id
              WHERE e2.intake_id = i.id) AS collected
       FROM intakes i
       JOIN courses c ON c.id = i.course_id
      ORDER BY i.starts_on DESC, i.id DESC"
)->fetchAll();

$statuses = [
    'planned'   => 'Planned',
    'open'      => 'Open for enrolment',
    'running'   => 'Running',
    'finished'  => 'Finished',
    'cancelled' => 'Cancelled',
];

mpc_page_head('Intakes', $user);
mpc_message($notice, true);
?>

<div class="card">
  <h2 style="font-size:1.05rem">Start a new intake</h2>
  <p class="muted" style="margin-top:0">
    One running of a course. Name it the way the office says it out loud.
  </p>

  <form method="post">
    <?php mpc_csrf_field(); ?>

    <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(240px,1fr));gap:16px;margin-bottom:16px">
      <div>
        <label for="course_id">Program</label>
        <select id="course_id" name="course_id" required>
          <option value="">Select</option>
          <?php foreach ($courses as $c): ?>
            <option value="<?= (int) $c['id'] ?>"
              <?= (int) ($_POST['course_id'] ?? 0) === (int) $c['id'] ? 'selected' : '' ?>>
              <?= e($c['title']) ?>
              (<?= e(mpc_money($c['fee_amount'])) ?>/month<?= $c['duration_months'] ? ' &times; ' . (int) $c['duration_months'] : '' ?>)
            </option>
          <?php endforeach; ?>
        </select>
      </div>
      <div>
        <label for="name">Intake name</label>
        <input type="text" id="name" name="name" required maxlength="120"
               placeholder="January 2027 &mdash; morning"
               value="<?= e($_POST['name'] ?? '') ?>">
      </div>
    </div>

    <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(240px,1fr));gap:16px;margin-bottom:16px">
      <div>
        <label for="starts_on">Starts on</label>
        <input type="date" id="starts_on" name="starts_on" required
               value="<?= e($_POST['starts_on'] ?? '') ?>">
      </div>
      <div>
        <label for="ends_on">Ends on</label>
        <input type="date" id="ends_on" name="ends_on"
               value="<?= e($_POST['ends_on'] ?? '') ?>">
        <p class="muted" style="margin:6px 0 0">Left blank, this is worked out from the program length.</p>
      </div>
    </div>

    <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:16px;margin-bottom:20px">
      <div>
        <label for="schedule_note">Class times</label>
        <input type="text" id="schedule_note" name="schedule_note" maxlength="255"
               placeholder="Sat&ndash;Wed, 08:00&ndash;10:00"
               value="<?= e($_POST['schedule_note'] ?? '') ?>">
      </div>
      <div>
        <label for="capacity">Capacity</label>
        <input type="number" id="capacity" name="capacity" min="1" step="1"
               placeholder="Blank if there is no limit"
               value="<?= e($_POST['capacity'] ?? '') ?>">
      </div>
      <div>
        <label for="status">Status</label>
        <select id="status" name="status">
          <?php foreach ($statuses as $v => $label): ?>
            <option value="<?= e($v) ?>" <?= ($_POST['status'] ?? 'planned') === $v ? 'selected' : '' ?>>
              <?= e($label) ?>
            </option>
          <?php endforeach; ?>
        </select>
      </div>
    </div>

    <button type="submit">Create intake</button>
    <?php mpc_message($error, false); ?>
  </form>
</div>

<div class="card">
  <h2 style="font-size:1.05rem">Intakes</h2>
  <?php if (! $intakes): ?>
    <p class="muted">
      None yet. Until an intake exists no payment can be recorded against
      anything &mdash; a student enrols in a class, not in a course.
    </p>
  <?php else: ?>
    <table>
      <thead>
        <tr>
          <th>Intake</th><th>Program</th><th>Starts</th><th>Ends</th>
          <th>Status</th><th class="num">Enrolled</th><th class="num">Collected</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($intakes as $i): ?>
          <tr>
            <td>
              <strong><?= e($i['name']) ?></strong>
              <?php if ($i['schedule_note']): ?>
                <div class="muted"><?= e($i['schedule_note']) ?></div>
              <?php endif; ?>
            </td>
            <td><?= e($i['course_title']) ?></td>
            <td><?= e($i['starts_on'] ?? '') ?></td>
            <td><?= e($i['ends_on'] ?? '') ?></td>
            <td><?= e($statuses[$i['status']] ?? $i['status']) ?></td>
            <td class="num"><?= (int) $i['enrolled'] ?><?= $i['capacity'] ? ' / ' . (int) $i['capacity'] : '' ?></td>
            <td class="num"><?= e(mpc_money($i['collected'])) ?></td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  <?php endif; ?>
</div>

<?php
mpc_page_foot();
