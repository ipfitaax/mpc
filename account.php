<?php
/**
 * Where a signed-in student lands: who they are, what they are enrolled on, and
 * the way out.
 *
 * WHAT THIS PAGE USED TO SAY, AND WHY IT NO LONGER SAYS IT
 * Until the quiz module existed this page told the student plainly that there
 * was no portal behind the login — because there wasn't, and a page dressed up
 * as a dashboard with nothing in it teaches a student that the portal is broken.
 * That was the right thing to say then and it is the wrong thing to say now:
 * there IS something behind the login, and a page still insisting otherwise
 * would send students away from a quiz they are supposed to sit.
 *
 * The rule underneath both versions is the same one, and it is worth keeping
 * where the next person to change this file will read it: this page says what is
 * actually here. Not what is planned, not "coming soon" tiles. Recordings and
 * fee statements are still not built, and this page does not imply they are.
 *
 * It also still serves its original second purpose. The public pages are static
 * .html rendered in the browser and cannot show a signed-in state, so this is
 * the only proof a student has that signing in worked at all.
 *
 * It is the sign-out endpoint for every student screen — lib/student-page.php
 * posts here from the header — so the POST handler runs before any output.
 */

declare(strict_types=1);

require_once __DIR__ . '/lib/auth.php';
require_once __DIR__ . '/lib/quiz.php';
require_once __DIR__ . '/lib/student-page.php';

mpc_session_start();

// Sign out. POST with a CSRF token, never a GET — a GET logout can be fired by
// any image tag on any page the student happens to open. That was harmless when
// this was the only signed-in page; it is not harmless now that signing out
// mid-quiz abandons an attempt that has already been spent.
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' && isset($_POST['signout'])) {
    mpc_csrf_check();
    mpc_logout();

    header('Location: ./index.html', true, 302);
    exit;
}

$user = mpc_require_student();

// What they are actually enrolled on. Shown because a student who cannot open a
// quiz is usually a student whose enrolment is not what they think it is, and
// this is the screen that answers that without a phone call.
$stmt = mpc_db()->prepare(
    'SELECT i.name AS intake_name, c.title AS course_title, e.status, i.starts_on
       FROM enrollments e
       JOIN intakes i ON i.id = e.intake_id
       JOIN courses c ON c.id = i.course_id
      WHERE e.user_id = ?
      ORDER BY i.starts_on DESC'
);
$stmt->execute([(int) $user['id']]);
$enrollments = $stmt->fetchAll();

// Empty rather than fatal when the migration has not been run. This page is the
// student's proof that signing in worked at all (see the note at the top), so it
// is the last page that should be the one to break.
$quizzes = mpc_quiz_tables_present() ? mpc_student_quiz_list((int) $user['id']) : [];

mpc_student_page_head('Your account', $user, 'account.php');
?>

<div class="card">
  <h2>You are signed in</h2>
  <p class="muted" style="margin:0">
    <?= e($user['full_name']) ?><?php if ($user['email']): ?><br><?= e($user['email']) ?><?php endif; ?>
  </p>
</div>

<div class="card">
  <h2>Your enrolment</h2>

  <?php if (! $enrollments): ?>
    <p style="margin-bottom:0">
      Your account is not linked to a class yet. Signing in confirms who you are;
      it does not enrol you. To enrol, use the
      <a href="./index.html#apply-form">enrollment form</a> or call
      <strong>+252 770 51 90 98</strong>.
    </p>
  <?php else: ?>
    <table>
      <thead><tr><th>Program</th><th>Intake</th><th>Starts</th><th>Status</th></tr></thead>
      <tbody>
        <?php foreach ($enrollments as $en): ?>
          <tr>
            <td><strong><?= e($en['course_title']) ?></strong></td>
            <td><?= e($en['intake_name']) ?></td>
            <td><?= e($en['starts_on'] ?? '') ?></td>
            <td><?= e($en['status']) ?></td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
    <p class="muted" style="margin-bottom:0">
      Fees, receipts and attendance are not shown here &mdash; ask at the office
      for those.
    </p>
  <?php endif; ?>
</div>

<div class="card">
  <h2>Quizzes</h2>
  <?php if ($quizzes): ?>
    <p style="margin-bottom:0">
      You have <strong><?= count($quizzes) ?></strong>
      quiz<?= count($quizzes) === 1 ? '' : 'zes' ?> available.
      <a href="./quizzes.php">Go to your quizzes</a>.
    </p>
  <?php else: ?>
    <p style="margin-bottom:0">
      Nothing to sit at the moment. When an instructor publishes a quiz for a
      course you are enrolled on, it appears under
      <a href="./quizzes.php">Quizzes</a>.
    </p>
  <?php endif; ?>
</div>

<div class="card">
  <h2>What is not here</h2>
  <p style="margin-bottom:0">
    Course materials, class recordings and fee statements are not built. This
    page is not hiding them from you &mdash; they do not exist yet. For anything
    about fees or enrolment, call <strong>+252 770 51 90 98</strong>.
  </p>
</div>

<?php
mpc_student_page_foot();
