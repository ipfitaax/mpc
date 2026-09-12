<?php
/**
 * A student's quizzes: what is open to them, and what they scored.
 *
 * This screen tells a student the truth about every paper, including the ones
 * they cannot open. A quiz that is closed because of unpaid fees is SHOWN, with
 * the reason, rather than hidden — hiding it means the student cannot tell the
 * difference between "there is no exam" and "there is an exam you are locked
 * out of", and the first thing they do is ring the office to ask which. The
 * reason text comes from lib/quiz.php so the office hears the same words the
 * student is reading.
 *
 * The one thing deliberately NOT shown is an unpublished paper. A draft does
 * not exist to a student, and announcing "your instructor is writing a quiz"
 * invites a question nobody at the desk can answer.
 */

declare(strict_types=1);

require_once __DIR__ . '/lib/auth.php';
require_once __DIR__ . '/lib/quiz.php';
require_once __DIR__ . '/lib/student-page.php';

$user = mpc_require_student();

// Deployed without its migration. A STUDENT is not the audience for the name of
// a SQL file, so this says the same thing the Google endpoint says when its
// config key is absent: not switched on yet. The office screens name the
// migration, because the person who can act on it is the one reading those.
if (! mpc_quiz_tables_present()) {
    mpc_student_page_head('Quizzes', $user, 'quizzes.php');
    echo '<div class="card"><h2>Quizzes are not switched on yet</h2>'
       . '<p style="margin-bottom:0">This part of the site is not running on this '
       . 'server yet. Nothing is wrong with your account, and there is nothing '
       . 'you need to do.</p></div>';
    mpc_student_page_foot();
    exit;
}

$rows = mpc_student_quiz_list((int) $user['id']);

// The access check per paper, so the list can say WHY something is closed. It
// is a handful of extra queries on a page that lists a handful of quizzes; the
// alternative is a student staring at a row they cannot click.
$quizzes = [];
foreach ($rows as $row) {
    $quiz = mpc_quiz((int) $row['id']);

    if (! $quiz) {
        continue;
    }

    $quizzes[] = $row + ['access' => mpc_student_quiz_access((int) $user['id'], $quiz)];
}

mpc_student_page_head('Quizzes', $user, 'quizzes.php');
?>

<?php if (! $quizzes): ?>
  <div class="card">
    <h2>Nothing to sit yet</h2>
    <p style="margin-bottom:0">
      When your instructor publishes a quiz for a course you are enrolled on, it
      appears here. If you think you should be seeing one, call the office.
    </p>
  </div>
<?php else: ?>
  <?php foreach ($quizzes as $q):
      $access   = $q['access'];
      $sat      = $q['best_percent'] !== null;
      $passed   = (int) $q['ever_passed'] === 1;
      $used     = (int) $q['attempts_used'];
      $max      = (int) $q['max_attempts'];
      $left     = max(0, $max - $used);
  ?>
    <div class="card">
      <div style="display:flex;justify-content:space-between;gap:18px;flex-wrap:wrap;align-items:flex-start">
        <div>
          <h2 style="margin-bottom:4px"><?= e($q['title']) ?></h2>
          <p class="muted" style="margin:0">
            <?= e($q['course_title']) ?>
            &middot; <?= (int) $q['question_count'] ?> question<?= (int) $q['question_count'] === 1 ? '' : 's' ?>
            &middot; pass at <?= (int) $q['pass_mark_percent'] ?>%
            <?php if ($q['time_limit_minutes'] !== null): ?>
              &middot; <?= (int) $q['time_limit_minutes'] ?> minutes
            <?php endif; ?>
          </p>
        </div>

        <?php if ($sat): ?>
          <div style="text-align:right">
            <div style="font-size:1.6rem;font-weight:800;color:<?= $passed ? MPC_GREEN : MPC_RED ?>">
              <?= e(number_format((float) $q['best_percent'], 0)) ?>%
            </div>
            <div class="<?= $passed ? 'ok' : 'bad' ?>" style="font-size:.85rem">
              <?= $passed ? 'Passed' : 'Not passed' ?>
            </div>
          </div>
        <?php endif; ?>
      </div>

      <p style="margin:16px 0 0">
        <?php if ($access['ok']): ?>
          <a href="./quiz.php?id=<?= (int) $q['id'] ?>" style="font-weight:700">
            <?= $sat ? 'Sit it again' : 'Start this quiz' ?>
          </a>
          <span class="muted">
            &mdash; <?= $left ?> of <?= $max ?> attempt<?= $max === 1 ? '' : 's' ?> left
          </span>
        <?php else: ?>
          <?php if ($sat): ?>
            <a href="./quiz.php?id=<?= (int) $q['id'] ?>" style="font-weight:700">See your answers</a><br>
          <?php endif; ?>
          <span class="muted"><?= e($access['reason']) ?></span>
        <?php endif; ?>
      </p>
    </div>
  <?php endforeach; ?>
<?php endif; ?>

<?php
mpc_student_page_foot();
