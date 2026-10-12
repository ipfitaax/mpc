<?php
/**
 * Courses — add a course, or change one's length and monthly fee.
 *
 * WHY THIS SCREEN NOW EXISTS
 * The intakes screen was written saying courses were not editable, because
 * there were two of them and a third would be "a conversation and then a
 * migration". That held until the office needed one: the public site already
 * advertises eight subjects, and every one the desk could not enrol a student
 * in was a developer running SQL. The conversation still happens; this screen
 * is where its outcome is typed in, by an administrator and nobody else.
 *
 * All the rules — what a valid fee is, why the short name is frozen, why a fee
 * change does not touch current students — are in lib/catalogue.php. This page
 * renders and never decides.
 */

declare(strict_types=1);

require_once dirname(__DIR__) . '/lib/auth.php';
require_once dirname(__DIR__) . '/lib/page.php';
require_once dirname(__DIR__) . '/lib/catalogue.php';

$user = mpc_require_admin();
$db   = mpc_db();

$editId = (int) ($_GET['edit'] ?? 0);
$error  = null;
$notice = null;

if (isset($_GET['saved'])) {
    $notice = 'Saved "' . (string) $_GET['saved'] . '".';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    mpc_csrf_check();

    $courseId = (int) ($_POST['course_id'] ?? 0);
    [$clean, $error] = mpc_course_clean($_POST, $courseId === 0);

    if ($error === null) {
        try {
            if ($courseId === 0) {
                $result = mpc_course_create($db, $clean);
                if (is_string($result)) {
                    $error = $result;
                }
            } else {
                mpc_course_update($db, $courseId, $clean);
            }
        } catch (PDOException $e) {
            // Shown in full. The likeliest cause on a database that predates this
            // screen is the missing UPDATE grant, and "could not save" alone would
            // send someone hunting through this file for a bug that is not in it.
            $error = 'Could not save that course: ' . $e->getMessage()
                   . ($courseId ? ' (If this is a privileges error, run database/migrations/005-office-management.sql.)' : '');
        }
    }

    // Post-Redirect-Get, so a refresh re-renders rather than re-submits.
    if ($error === null) {
        header('Location: ./courses.php?saved=' . urlencode($clean['title']));
        exit;
    }

    $editId = $courseId;
}

// What each course is carrying, so an administrator changing a fee can see who
// the change does NOT reach: everybody already enrolled keeps their agreed rate.
$courses = $db->query(
    "SELECT c.*,
            (SELECT COUNT(*) FROM intakes i WHERE i.course_id = c.id) AS intakes,
            (SELECT COUNT(*) FROM enrollments e JOIN intakes i2 ON i2.id = e.intake_id
              WHERE i2.course_id = c.id AND e.status = 'active') AS active_students
       FROM courses c
      ORDER BY c.display_order, c.title"
)->fetchAll();

$editing = null;
foreach ($courses as $c) {
    if ((int) $c['id'] === $editId) {
        $editing = $c;
    }
}

// The form shows, in order of preference: what was just posted (so a rejected
// form keeps the typing), the course being edited, or empty.
$f = $_SERVER['REQUEST_METHOD'] === 'POST' ? $_POST : ($editing ?? []);
$v = static fn(string $k, string $default = ''): string => e((string) ($f[$k] ?? $default));

mpc_page_head('Courses', $user);
mpc_message($notice, true);
?>

<div class="card" id="form">
  <h2 style="font-size:1.05rem">
    <?= $editing ? 'Change &ldquo;' . e($editing['title']) . '&rdquo;' : 'Add a course' ?>
  </h2>
  <p class="muted" style="margin-top:0">
    This is what the payment desk charges. It does <strong>not</strong> change the
    public website &mdash; the course pages on mpc.so are edited separately.
    <?php if ($editing && (int) $editing['active_students'] > 0): ?>
      <br>A new fee or length applies to new enrolments only. The
      <?= (int) $editing['active_students'] ?> student(s) already enrolled keep the
      rate they agreed.
    <?php endif; ?>
  </p>

  <form method="post" action="./courses.php">
    <?php mpc_csrf_field(); ?>
    <input type="hidden" name="course_id" value="<?= $editing ? (int) $editing['id'] : 0 ?>">

    <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(240px,1fr));gap:16px;margin-bottom:16px">
      <div>
        <label for="title">Title</label>
        <input type="text" id="title" name="title" required maxlength="160"
               placeholder="Web Design" value="<?= $v('title') ?>">
      </div>
      <div>
        <label for="slug">Short name</label>
        <?php if ($editing): ?>
          <input type="text" id="slug" value="<?= e($editing['slug']) ?>" disabled>
          <p class="muted" style="margin:6px 0 0">Fixed once created &mdash; it matches the page address.</p>
        <?php else: ?>
          <input type="text" id="slug" name="slug" required maxlength="120"
                 pattern="[a-z0-9]+(-[a-z0-9]+)*" placeholder="web-design" value="<?= $v('slug') ?>">
          <p class="muted" style="margin:6px 0 0">Lowercase and hyphens. Cannot be changed later.</p>
        <?php endif; ?>
      </div>
    </div>

    <div style="margin-bottom:16px">
      <label for="summary">One-line summary</label>
      <input type="text" id="summary" name="summary" maxlength="400" value="<?= $v('summary') ?>">
    </div>

    <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(200px,1fr));gap:16px;margin-bottom:16px">
      <div>
        <label for="fee_amount">Fee per month (USD)</label>
        <input type="text" id="fee_amount" name="fee_amount" inputmode="decimal"
               placeholder="Blank means ask the office" value="<?= $v('fee_amount') ?>">
      </div>
      <div>
        <label for="duration_months">Length in months</label>
        <input type="number" id="duration_months" name="duration_months" min="1" max="60" step="1"
               value="<?= $v('duration_months') ?>">
      </div>
      <div>
        <label for="duration_weeks">Length in weeks</label>
        <input type="number" id="duration_weeks" name="duration_weeks" min="1" step="1"
               placeholder="As advertised" value="<?= $v('duration_weeks') ?>">
      </div>
    </div>

    <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(200px,1fr));gap:16px;margin-bottom:20px">
      <div>
        <label for="level">Level</label>
        <select id="level" name="level">
          <?php foreach (MPC_COURSE_LEVELS as $k => $label): ?>
            <option value="<?= e($k) ?>" <?= ($f['level'] ?? 'beginner') === $k ? 'selected' : '' ?>><?= e($label) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div>
        <label for="pathway">Pathway</label>
        <select id="pathway" name="pathway">
          <?php foreach (MPC_COURSE_PATHWAYS as $k => $label): ?>
            <option value="<?= e($k) ?>" <?= ($f['pathway'] ?? 'standalone') === $k ? 'selected' : '' ?>><?= e($label) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div>
        <label for="display_order">Display order</label>
        <input type="number" id="display_order" name="display_order" step="1"
               value="<?= $v('display_order', '0') ?>">
      </div>
    </div>

    <button type="submit"><?= $editing ? 'Save changes' : 'Add course' ?></button>
    <?php if ($editing): ?>
      <a href="./courses.php" style="margin-left:14px;font-weight:600">Cancel</a>
    <?php endif; ?>
    <?php mpc_message($error, false); ?>
  </form>
</div>

<div class="card">
  <h2 style="font-size:1.05rem">Courses</h2>
  <?php if (! $courses): ?>
    <p class="muted">None yet. Add one above; then start an intake for it on the Intakes screen.</p>
  <?php else: ?>
    <table>
      <thead>
        <tr>
          <th>Course</th><th>Pathway</th><th class="num">Fee / month</th>
          <th class="num">Months</th><th class="num">Intakes</th><th class="num">Enrolled</th><th></th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($courses as $c): ?>
          <tr>
            <td>
              <strong><?= e($c['title']) ?></strong>
              <div class="muted"><?= e($c['slug']) ?></div>
            </td>
            <td><?= e(MPC_COURSE_PATHWAYS[$c['pathway']] ?? $c['pathway']) ?></td>
            <td class="num"><?= $c['fee_amount'] !== null ? e(mpc_money($c['fee_amount'])) : '<span class="muted">ask</span>' ?></td>
            <td class="num"><?= $c['duration_months'] !== null ? (int) $c['duration_months'] : '<span class="muted">&mdash;</span>' ?></td>
            <td class="num"><?= (int) $c['intakes'] ?></td>
            <td class="num"><?= (int) $c['active_students'] ?></td>
            <td><a href="./courses.php?edit=<?= (int) $c['id'] ?>#form" style="font-weight:600">Change</a></td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  <?php endif; ?>
</div>

<?php
mpc_page_foot();
