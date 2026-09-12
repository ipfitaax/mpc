<?php
/**
 * Sit one quiz, submit it, and see what it scored.
 *
 * Three states in one file, because they are three views of one thing and
 * splitting them across three URLs means three places to forget the access
 * check:
 *
 *   BRIEFING  no attempt open. Shows what the paper is and what starting costs.
 *   PAPER     an attempt is open. The questions, with no answer key anywhere.
 *   REVIEW    the attempt is submitted. The score, and what was right.
 *
 * WHY STARTING IS A DELIBERATE, SEPARATE STEP
 * Opening the paper spends an attempt — it has to, or a student reads every
 * question, closes the tab, and comes back to a fresh attempt knowing what is
 * on it. On a one-attempt paper that makes a mis-click expensive, so the
 * briefing screen says exactly what will happen and the student presses a
 * button that says so. Nothing spends an attempt on a GET.
 *
 * STARTING RE-CHECKS ACCESS. SUBMITTING DELIBERATELY DOES NOT.
 * The briefing is rendered once and the POST arrives minutes later, so `start`
 * checks again rather than trusting the check that rendered the button — an
 * instructor can unpublish a paper in between, and a check made on a fact that
 * has since changed is the general shape of most access bugs.
 *
 * Submitting is the opposite case and gets the opposite rule. A student who is
 * halfway through a paper can always hand it in: unpublishing mid-exam, or a
 * fee falling due while they write, must not throw away work already done. What
 * `submit` checks instead is ownership — the attempt must be this student's and
 * still open — which is the part that actually protects anyone. Marking an
 * abandoned sitting is harmless; marking somebody else's is not.
 */

declare(strict_types=1);

require_once __DIR__ . '/lib/auth.php';
require_once __DIR__ . '/lib/quiz.php';
require_once __DIR__ . '/lib/student-page.php';

$user   = mpc_require_student();
$userId = (int) $user['id'];

// Deployed without its migration — see quizzes.php, which explains it to the
// student. Here there is nothing to explain on: bounce to the list, which says
// so properly.
if (! mpc_quiz_tables_present()) {
    header('Location: ./quizzes.php', true, 302);
    exit;
}

$quizId = (int) ($_GET['id'] ?? $_POST['quiz_id'] ?? 0);
$quiz   = $quizId ? mpc_quiz($quizId) : null;

if (! $quiz) {
    header('Location: ./quizzes.php', true, 302);
    exit;
}

$error = null;

// The sittings already finished. Read before anything else, because every one
// of the three states shows them and the review state is reached FROM them.
$past = mpc_quiz_student_attempts($quizId, $userId);

// An attempt left open by a closed tab or a lost connection. The student comes
// back to the same paper rather than to a refusal or a second attempt.
$stmt = mpc_db()->prepare(
    'SELECT * FROM quiz_attempts
      WHERE quiz_id = ? AND user_id = ? AND submitted_at IS NULL
      ORDER BY id DESC LIMIT 1'
);
$stmt->execute([$quizId, $userId]);
$open = $stmt->fetch() ?: null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    mpc_csrf_check();

    $action = (string) ($_POST['action'] ?? '');

    if ($action === 'start') {
        $access = mpc_student_quiz_access($userId, $quiz);

        if (! $access['ok']) {
            $error = $access['reason'];
        } else {
            $open = mpc_quiz_open_attempt($quizId, $userId, $access['enrollment_id']);

            // Redirect after the POST so a refresh re-renders the paper instead
            // of trying to start a second attempt.
            header('Location: ./quiz.php?id=' . $quizId, true, 302);
            exit;
        }
    } elseif ($action === 'submit') {
        $attemptId = (int) ($_POST['attempt_id'] ?? 0);

        // The attempt must be THIS student's and still open. Without the
        // user_id in the condition, a posted attempt id belonging to a
        // classmate is marked and scored under their name.
        if (! $open || (int) $open['id'] !== $attemptId) {
            header('Location: ./quiz.php?id=' . $quizId, true, 302);
            exit;
        }

        // Only option ids, keyed by question. Everything else in the POST is
        // ignored — see the note at the top of lib/quiz.php about why a field
        // named `score` would achieve nothing.
        $selected = [];
        foreach ((array) ($_POST['answer'] ?? []) as $questionId => $chosen) {
            $selected[(int) $questionId] = array_map('intval', (array) $chosen);
        }

        try {
            $marked = mpc_quiz_mark_attempt($attemptId, $selected);

            header('Location: ./quiz.php?id=' . $quizId . '&attempt=' . (int) $marked['id'], true, 302);
            exit;
        } catch (Throwable $e) {
            // A student who has just spent thirty minutes on a paper is told
            // plainly that it did not save, and their answers stay on the
            // screen behind this message. Saying "saved" here would be the
            // same lie api-form.js exists to prevent on the enquiry form.
            $error = 'Your answers could not be saved, and nothing has been recorded. '
                   . 'Do not close this page — try Submit again, and if it fails '
                   . 'again call the office on +252 770 51 90 98.';
        }
    }
}

// Which attempt the review is showing: the one just submitted, or the newest.
$reviewing = null;
if (isset($_GET['attempt'])) {
    foreach ($past as $attempt) {
        if ((int) $attempt['id'] === (int) $_GET['attempt']) {
            $reviewing = $attempt;
        }
    }
}

$access = mpc_student_quiz_access($userId, $quiz);

mpc_student_page_head($quiz['title'], $user, 'quizzes.php');

if ($error !== null) {
    echo '<p class="bad" style="margin-top:-8px">' . e($error) . '</p>';
}
?>

<p style="margin-top:-6px"><a href="./quizzes.php">&larr; All quizzes</a></p>

<?php
// =========================================================================
// PAPER — an attempt is open, so this is the sitting.
// =========================================================================
if ($open):
    $questions = mpc_quiz_paper($quizId, (int) $quiz['shuffle_questions'] === 1);
    $deadline  = $quiz['time_limit_minutes'] !== null
        ? strtotime((string) $open['started_at']) + ((int) $quiz['time_limit_minutes'] * 60)
        : null;
?>
  <div class="card">
    <h2>Attempt <?= (int) $open['attempt_no'] ?> of <?= (int) $quiz['max_attempts'] ?></h2>
    <?php if ($quiz['instructions']): ?>
      <p style="margin-bottom:0"><?= nl2br(e($quiz['instructions'])) ?></p>
    <?php endif; ?>
    <?php if ($deadline !== null): ?>
      <p class="muted" style="margin:12px 0 0">
        You started at <?= e(date('H:i', strtotime((string) $open['started_at']))) ?>
        and this paper allows <?= (int) $quiz['time_limit_minutes'] ?> minutes,
        so it is due by <strong><?= e(date('H:i', $deadline)) ?></strong>.
        <?php if (time() > $deadline): ?>
          <br><strong>That time has passed.</strong> You can still submit and it
          will still be marked, but your instructor will see that it was late.
        <?php endif; ?>
      </p>
    <?php endif; ?>
  </div>

  <form method="post">
    <input type="hidden" name="csrf" value="<?= e(mpc_csrf_token()) ?>">
    <input type="hidden" name="quiz_id" value="<?= $quizId ?>">
    <input type="hidden" name="attempt_id" value="<?= (int) $open['id'] ?>">

    <?php foreach ($questions as $n => $q): ?>
      <div class="card">
        <div style="display:flex;justify-content:space-between;gap:14px">
          <h2 style="margin-bottom:14px">Question <?= $n + 1 ?></h2>
          <span class="muted"><?= (int) $q['points'] ?> mark<?= (int) $q['points'] === 1 ? '' : 's' ?></span>
        </div>

        <p style="margin:0 0 16px;color:<?= MPC_HEADING ?>;font-weight:600"><?= nl2br(e($q['text'])) ?></p>

        <?php if ($q['type'] === 'multiple'): ?>
          <p class="muted" style="margin:0 0 12px">Choose every correct answer.</p>
        <?php endif; ?>

        <?php foreach ($q['options'] as $o): ?>
          <label style="display:flex;gap:12px;align-items:flex-start;font-weight:400;
                        color:<?= MPC_TEXT ?>;margin-bottom:12px;cursor:pointer">
            <?php if ($q['type'] === 'multiple'): ?>
              <input type="checkbox" name="answer[<?= (int) $q['id'] ?>][]"
                     value="<?= (int) $o['id'] ?>" style="width:auto;margin-top:5px;flex:none">
            <?php else: ?>
              <input type="radio" name="answer[<?= (int) $q['id'] ?>][]"
                     value="<?= (int) $o['id'] ?>" style="width:auto;margin-top:5px;flex:none">
            <?php endif; ?>
            <span><?= e($o['text']) ?></span>
          </label>
        <?php endforeach; ?>
      </div>
    <?php endforeach; ?>

    <div class="card">
      <p style="margin:0 0 18px">
        Once you submit, this attempt is marked and cannot be changed.
        <?php if ((int) $quiz['max_attempts'] - (int) $open['attempt_no'] > 0): ?>
          You would have
          <?= (int) $quiz['max_attempts'] - (int) $open['attempt_no'] ?> attempt<?=
            (int) $quiz['max_attempts'] - (int) $open['attempt_no'] === 1 ? '' : 's' ?> left after it.
        <?php else: ?>
          This is your last attempt.
        <?php endif; ?>
      </p>
      <button type="submit" name="action" value="submit">Submit my answers</button>
    </div>
  </form>

<?php
// =========================================================================
// REVIEW — a submitted attempt, question by question.
// =========================================================================
elseif ($reviewing):
    $review = mpc_quiz_review((int) $reviewing['id']);
    $passed = (int) $reviewing['passed'] === 1;
?>
  <div class="card" style="border-color:<?= $passed ? MPC_GREEN : MPC_BORDER ?>">
    <div style="display:flex;justify-content:space-between;gap:18px;flex-wrap:wrap;align-items:center">
      <div>
        <h2 style="margin-bottom:4px"><?= $passed ? 'You passed' : 'Not passed this time' ?></h2>
        <p class="muted" style="margin:0">
          <?= (int) $reviewing['score_points'] ?> of <?= (int) $reviewing['total_points'] ?> marks,
          attempt <?= (int) $reviewing['attempt_no'] ?>,
          submitted <?= e(substr((string) $reviewing['submitted_at'], 0, 16)) ?>.
          The pass mark is <?= (int) $quiz['pass_mark_percent'] ?>%.
        </p>
      </div>
      <div style="font-size:2.2rem;font-weight:800;color:<?= $passed ? MPC_GREEN : MPC_RED ?>">
        <?= e(number_format((float) $reviewing['score_percent'], 0)) ?>%
      </div>
    </div>
  </div>

  <?php foreach ($review as $n => $q): ?>
    <div class="card">
      <div style="display:flex;justify-content:space-between;gap:14px;align-items:baseline">
        <h2 style="margin-bottom:14px">Question <?= $n + 1 ?></h2>
        <span class="<?= $q['correct'] ? 'ok' : 'bad' ?>">
          <?= $q['correct'] ? 'Correct' : 'Wrong' ?>
        </span>
      </div>

      <p style="margin:0 0 16px;color:<?= MPC_HEADING ?>;font-weight:600"><?= nl2br(e($q['text'])) ?></p>

      <?php foreach ($q['options'] as $o):
          // Four states, and they are all worth distinguishing: a student needs
          // to see the right answer they missed as clearly as the wrong one
          // they picked.
          $colour = $o['is_correct'] ? MPC_GREEN : ($o['chosen'] ? MPC_RED : MPC_TEXT);
          $note   = $o['is_correct'] && $o['chosen'] ? ' &mdash; your answer, correct'
                  : ($o['is_correct'] ? ' &mdash; the correct answer'
                  : ($o['chosen'] ? ' &mdash; your answer' : ''));
      ?>
        <p style="margin:0 0 10px;color:<?= $colour ?>;
                  font-weight:<?= $o['is_correct'] || $o['chosen'] ? '700' : '400' ?>">
          <?= e($o['text']) ?><span style="font-weight:400"><?= $note ?></span>
        </p>
      <?php endforeach; ?>

      <?php if ($q['explanation']): ?>
        <p style="margin:16px 0 0;padding-top:14px;border-top:1px solid <?= MPC_BORDER ?>">
          <?= nl2br(e($q['explanation'])) ?>
        </p>
      <?php endif; ?>
    </div>
  <?php endforeach; ?>

  <div class="card">
    <?php if ($access['ok']): ?>
      <p style="margin:0 0 18px">You have attempts left on this quiz.</p>
      <form method="post" style="margin:0">
        <input type="hidden" name="csrf" value="<?= e(mpc_csrf_token()) ?>">
        <input type="hidden" name="quiz_id" value="<?= $quizId ?>">
        <button type="submit" name="action" value="start">Sit it again</button>
      </form>
    <?php else: ?>
      <p class="muted" style="margin:0"><?= e($access['reason']) ?></p>
    <?php endif; ?>
  </div>

<?php
// =========================================================================
// BRIEFING — nothing open, nothing being reviewed.
// =========================================================================
else:
    $stmt = mpc_db()->prepare('SELECT COUNT(*) FROM quiz_questions WHERE quiz_id = ?');
    $stmt->execute([$quizId]);
    $questionCount = (int) $stmt->fetchColumn();
?>
  <div class="card">
    <h2><?= e($quiz['course_title']) ?></h2>

    <?php if ($quiz['instructions']): ?>
      <p><?= nl2br(e($quiz['instructions'])) ?></p>
    <?php endif; ?>

    <p style="margin-bottom:0">
      <?= $questionCount ?> question<?= $questionCount === 1 ? '' : 's' ?>.
      You need <?= (int) $quiz['pass_mark_percent'] ?>% to pass.
      <?php if ($quiz['time_limit_minutes'] !== null): ?>
        Once you start you have <?= (int) $quiz['time_limit_minutes'] ?> minutes.
      <?php endif; ?>
    </p>
  </div>

  <?php if ($past): ?>
    <div class="card">
      <h2>What you have scored so far</h2>
      <table>
        <thead><tr><th>Attempt</th><th>Submitted</th><th class="num">Marks</th><th class="num">Score</th><th></th></tr></thead>
        <tbody>
          <?php foreach ($past as $a): ?>
            <tr>
              <td><?= (int) $a['attempt_no'] ?></td>
              <td><?= e(substr((string) $a['submitted_at'], 0, 16)) ?></td>
              <td class="num"><?= (int) $a['score_points'] ?> / <?= (int) $a['total_points'] ?></td>
              <td class="num <?= (int) $a['passed'] === 1 ? 'ok' : 'bad' ?>">
                <?= e(number_format((float) $a['score_percent'], 0)) ?>%
              </td>
              <td><a href="./quiz.php?id=<?= $quizId ?>&attempt=<?= (int) $a['id'] ?>">See answers</a></td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  <?php endif; ?>

  <div class="card">
    <?php if ($access['ok']): ?>
      <p style="margin:0 0 18px">
        <strong>Starting uses one of your attempts</strong>, whether or not you
        finish it &mdash; so start when you are ready to sit the whole paper.
        You have <?= (int) $quiz['max_attempts'] - mpc_quiz_attempts_used($quizId, $userId) ?>
        of <?= (int) $quiz['max_attempts'] ?> left.
      </p>
      <form method="post" style="margin:0">
        <input type="hidden" name="csrf" value="<?= e(mpc_csrf_token()) ?>">
        <input type="hidden" name="quiz_id" value="<?= $quizId ?>">
        <button type="submit" name="action" value="start">Start this quiz</button>
      </form>
    <?php else: ?>
      <p style="margin:0"><?= e($access['reason']) ?></p>
    <?php endif; ?>
  </div>
<?php endif; ?>

<?php
mpc_student_page_foot();
