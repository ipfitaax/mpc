<?php
/**
 * Grades for one quiz: every enrolled student, and what they scored.
 *
 * THE COLUMN THIS SCREEN EXISTS FOR IS THE EMPTY ONE. A list of results answers
 * "how did the class do"; a list of everyone enrolled, with blanks where nobody
 * sat, answers "who still has to do this" — which is the question an instructor
 * actually acts on. mpc_quiz_gradebook() returns the students with no attempt
 * for exactly that reason, and they are shown first here rather than sorted to
 * the bottom where they are scrolled past.
 *
 * WHY THERE IS NO EDIT
 * A grade on this screen is the arithmetic of what the student answered. There
 * is no box to type a different number into, and that is deliberate: the moment
 * a mark can be typed, the mark stops being evidence of anything and the
 * question "did she actually pass" becomes unanswerable. A student who deserves
 * another go is given another attempt, which leaves both sittings on the record.
 * The database enforces the same thing from below — the application has no
 * DELETE on quiz_attempts. See migrations/004.
 */

declare(strict_types=1);

require_once dirname(__DIR__) . '/lib/auth.php';
require_once dirname(__DIR__) . '/lib/page.php';
require_once dirname(__DIR__) . '/lib/quiz.php';

$user = mpc_require_teaching_staff();

// Deployed without its migration — see admin/quizzes.php and the comment on
// mpc_quiz_tables_present(). Checked before mpc_quiz() below, which queries a
// table that would not exist.
if (! mpc_quiz_tables_present()) {
    mpc_page_head('Quizzes', $user);
    echo '<div class="card"><h2 style="font-size:1.05rem">Not installed yet</h2><p style="margin-bottom:0">'
       . e(mpc_quiz_missing_tables_message()) . '</p></div>';
    mpc_page_foot();
    exit;
}

$quizId = (int) ($_GET['id'] ?? 0);
$quiz   = $quizId ? mpc_quiz($quizId) : null;

if (! $quiz || ! mpc_quiz_may_author($user, (int) $quiz['course_id'])) {
    header('Location: ./quizzes.php');
    exit;
}

$rows = mpc_quiz_gradebook($quizId);

// Sat and not-sat, counted before the table so the summary line is not a sum of
// what the reader can see on screen.
$sat    = array_values(array_filter($rows, static fn($r) => $r['submitted_at'] !== null));
$missing = array_values(array_filter($rows, static fn($r) => $r['submitted_at'] === null));
$passed = array_values(array_filter($sat, static fn($r) => (int) $r['passed'] === 1));

// The class average is over students who SAT, not over everyone enrolled.
// Counting an absent student as zero produces a number that drops when a class
// grows, which is not a measure of anything.
$average = $sat
    ? round(array_sum(array_map(static fn($r) => (float) $r['score_percent'], $sat)) / count($sat), 1)
    : null;

mpc_page_head('Grades — ' . $quiz['title'], $user);
?>

<p style="margin-top:-10px">
  <a href="./quizzes.php">&larr; All quizzes</a>
  &nbsp;&middot;&nbsp; <a href="./quiz-edit.php?id=<?= $quizId ?>">Edit this quiz</a>
  &nbsp;&middot;&nbsp; <?= e($quiz['course_title']) ?>
</p>

<div class="card">
  <h2 style="font-size:1.05rem">The class</h2>
  <p class="muted" style="margin-top:0">
    <?= count($sat) ?> of <?= count($rows) ?>
    enrolled student<?= count($rows) === 1 ? '' : 's' ?>
    <?= count($sat) === 1 ? 'has' : 'have' ?> sat this,
    <?= count($passed) ?> passed at <?= (int) $quiz['pass_mark_percent'] ?>%<?php
      if ($average !== null): ?>, average <?= $average ?>%<?php endif; ?>.
    <?php if ((int) $quiz['is_published'] !== 1): ?>
      <br>This quiz is currently unpublished, so nobody else can sit it.
    <?php endif; ?>
  </p>

  <?php if (! $rows): ?>
    <p class="muted" style="margin-bottom:0">
      Nobody is actively enrolled on <?= e($quiz['course_title']) ?>, so there is
      no class to grade yet.
    </p>
  <?php else: ?>
    <table>
      <thead>
        <tr>
          <th>Student</th><th>Intake</th><th>Sat</th>
          <th class="num">Mark</th><th class="num">Score</th><th>Result</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach (array_merge($missing, $sat) as $r): ?>
          <tr>
            <td>
              <strong><?= e($r['full_name']) ?></strong>
              <?php if ($r['phone']): ?><div class="muted"><?= e($r['phone']) ?></div><?php endif; ?>
            </td>
            <td><?= e($r['intake_name'] ?? '') ?></td>

            <?php if ($r['submitted_at'] === null): ?>
              <td colspan="4" class="muted">Not sat yet</td>
            <?php else: ?>
              <td>
                <?= e(substr((string) $r['submitted_at'], 0, 16)) ?>
                <?php if ((int) $r['attempt_no'] > 1): ?>
                  <div class="muted">attempt <?= (int) $r['attempt_no'] ?></div>
                <?php endif; ?>
              </td>
              <td class="num"><?= (int) $r['score_points'] ?> / <?= (int) $r['total_points'] ?></td>
              <td class="num"><?= e(number_format((float) $r['score_percent'], 1)) ?>%</td>
              <td>
                <?php if ((int) $r['passed'] === 1): ?>
                  <span style="color:<?= MPC_GREEN ?>;font-weight:700">Passed</span>
                <?php else: ?>
                  <span style="color:<?= MPC_RED ?>;font-weight:700">Not yet</span>
                <?php endif; ?>
              </td>
            <?php endif; ?>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>

    <p class="muted" style="margin-bottom:0">
      Where a student has sat this more than once, the best attempt is shown.
      Marks are not editable here &mdash; a student who needs another chance is
      given another attempt on the quiz settings, which keeps both sittings on
      the record.
    </p>
  <?php endif; ?>
</div>

<?php
mpc_page_foot();
