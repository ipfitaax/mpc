<?php
/**
 * Write a quiz paper: its settings, its questions, and whether it is published.
 *
 * ONE SCREEN, NOT FOUR. Editing a paper means moving between the questions
 * constantly — fixing the wording of question 3 because of what you wrote in
 * question 7 — and a wizard that hides the rest of the paper while you do it
 * makes that impossible. Everything is on one page and every question is its
 * own small form.
 *
 * THE PUBLISH GUARD IS THE POINT OF THIS FILE
 * A paper can be saved in any state at all: half-written, no options, nothing
 * marked correct. That is what a draft is for. It can only be PUBLISHED when
 * every question is answerable, and the check names the question that fails
 * rather than refusing in general. The one thing this must never do is let a
 * class sit a paper containing a question with no correct answer — that scores
 * every student zero on it and looks exactly like the students getting it
 * wrong. lib/quiz.php excludes such a question from the total as a last
 * defence, but by then the grade has already been recorded and questioned.
 *
 * WHY DELETING A QUESTION IS ALLOWED AFTER SITTINGS EXIST, AND WHAT IT COSTS
 * quiz_answers cascades from quiz_questions, so deleting a question deletes the
 * answers people gave to it — but NOT their scores, which are stored on the
 * attempt as numbers. A student's recorded 8/10 stays 8/10 while the review
 * screen can no longer show one of the questions. That is the honest trade: the
 * grade is the record, the review is a courtesy. The screen says so before it
 * lets anyone do it.
 */

declare(strict_types=1);

require_once dirname(__DIR__) . '/lib/auth.php';
require_once dirname(__DIR__) . '/lib/page.php';
require_once dirname(__DIR__) . '/lib/quiz.php';

$user = mpc_require_teaching_staff();
$db   = mpc_db();

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

$quizId = (int) ($_GET['id'] ?? $_POST['quiz_id'] ?? 0);
$quiz   = $quizId ? mpc_quiz($quizId) : null;

// Both halves matter. A missing quiz and a quiz on someone else's course get
// the SAME redirect, deliberately: telling an instructor "that quiz exists but
// is not yours" tells them what other classes are running and how many papers
// they have.
if (! $quiz || ! mpc_quiz_may_author($user, (int) $quiz['course_id'])) {
    header('Location: ./quizzes.php');
    exit;
}

$error  = null;
$notice = null;

if (isset($_GET['created'])) {
    $notice = 'Draft created. Add the questions, then publish it.';
}
if (isset($_GET['saved'])) {
    $notice = 'Saved.';
}

/**
 * Writes the options for one question from the posted form.
 *
 * Replaces the lot rather than reconciling row by row. Reconciling means
 * matching posted rows to existing ids, and the failure mode of getting that
 * subtly wrong is an option whose text changed but whose is_correct did not —
 * a question that marks the wrong answer right, silently, on a paper that
 * looks correct.
 *
 * The cost of replacing is that option ids change, so quiz_answers rows
 * referencing the old options are removed by the cascade. That is why the
 * screen warns before editing a question that has been sat.
 */
function mpc_save_options(PDO $db, int $questionId, string $type, array $texts, array $correct): int
{
    $db->prepare('DELETE FROM quiz_options WHERE question_id = ?')->execute([$questionId]);

    $insert = $db->prepare(
        'INSERT INTO quiz_options (question_id, text, is_correct, position) VALUES (?, ?, ?, ?)'
    );

    $saved = 0;

    foreach (array_values($texts) as $i => $text) {
        $text = trim((string) $text);

        // A blank option row is not an empty option, it is a row the author did
        // not use. Skipping them is what lets one form offer six slots for a
        // question that needs three.
        if ($text === '') {
            continue;
        }

        // truefalse allows exactly one right answer however many boxes were
        // ticked. The radio group in the form already enforces that; this is
        // the server saying the same thing, because the form is not the rule.
        $isCorrect = in_array((string) $i, array_map('strval', $correct), true) ? 1 : 0;

        $insert->execute([$questionId, $text, $isCorrect, $i]);
        $saved++;
    }

    if ($type === 'single' || $type === 'truefalse') {
        // One correct option, whatever arrived. Two correct options on a
        // single-answer question is unmarkable: every student is wrong, because
        // marking is all-or-nothing on the exact set.
        $stmt = $db->prepare(
            'SELECT id FROM quiz_options WHERE question_id = ? AND is_correct = 1 ORDER BY position'
        );
        $stmt->execute([$questionId]);
        $ids = $stmt->fetchAll(PDO::FETCH_COLUMN);

        if (count($ids) > 1) {
            $keep = array_shift($ids);
            $db->prepare(
                'UPDATE quiz_options SET is_correct = 0 WHERE question_id = ? AND id <> ?'
            )->execute([$questionId, $keep]);
        }
    }

    return $saved;
}

/**
 * Why this paper cannot be published yet, or null if it can.
 *
 * Returns the FIRST problem, named by question number, because a list of six
 * complaints is read as "this is broken" while one is read as "fix this".
 */
function mpc_publish_blocker(PDO $db, int $quizId): ?string
{
    $stmt = $db->prepare(
        'SELECT q.id, q.position,
                COUNT(o.id) AS options,
                COALESCE(SUM(o.is_correct), 0) AS correct
           FROM quiz_questions q
           LEFT JOIN quiz_options o ON o.question_id = q.id
          WHERE q.quiz_id = ?
          GROUP BY q.id
          ORDER BY q.position, q.id'
    );
    $stmt->execute([$quizId]);
    $questions = $stmt->fetchAll();

    if (! $questions) {
        return 'This quiz has no questions yet, so there is nothing to sit.';
    }

    foreach ($questions as $n => $q) {
        $number = $n + 1;

        if ((int) $q['options'] < 2) {
            return "Question {$number} has fewer than two options. A question with one option is not a question.";
        }
        if ((int) $q['correct'] < 1) {
            return "Question {$number} has no correct answer marked, so every student would score zero on it. "
                 . 'Tick the right option and publish again.';
        }
        if ((int) $q['correct'] === (int) $q['options']) {
            return "Question {$number} has every option marked correct, so it measures nothing.";
        }
    }

    return null;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    mpc_csrf_check();

    $action = (string) ($_POST['action'] ?? '');

    try {
        if ($action === 'settings') {
            $title = trim((string) ($_POST['title'] ?? ''));
            $pass  = (int) ($_POST['pass_mark_percent'] ?? 50);
            $tries = (int) ($_POST['max_attempts'] ?? 1);
            $limit = trim((string) ($_POST['time_limit_minutes'] ?? ''));

            if ($title === '') {
                $error = 'The quiz needs a title.';
            } elseif ($pass < 1 || $pass > 100) {
                $error = 'The pass mark is a percentage between 1 and 100.';
            } elseif ($tries < 1 || $tries > 20) {
                $error = 'Attempts must be between 1 and 20.';
            } elseif ($limit !== '' && (! ctype_digit($limit) || (int) $limit < 1)) {
                $error = 'The time limit is a whole number of minutes, or blank for untimed.';
            } else {
                $stmt = $db->prepare(
                    'UPDATE quizzes
                        SET title = ?, instructions = ?, pass_mark_percent = ?,
                            max_attempts = ?, time_limit_minutes = ?, shuffle_questions = ?
                      WHERE id = ?'
                );
                $stmt->execute([
                    $title,
                    trim((string) ($_POST['instructions'] ?? '')) ?: null,
                    $pass,
                    $tries,
                    $limit !== '' ? (int) $limit : null,
                    isset($_POST['shuffle_questions']) ? 1 : 0,
                    $quizId,
                ]);

                header('Location: ./quiz-edit.php?id=' . $quizId . '&saved=1');
                exit;
            }
        } elseif ($action === 'add_question' || $action === 'update_question') {
            $text   = trim((string) ($_POST['text'] ?? ''));
            $type   = (string) ($_POST['type'] ?? 'single');
            $points = (int) ($_POST['points'] ?? 1);

            if ($text === '') {
                $error = 'A question needs its text.';
            } elseif (! in_array($type, ['single', 'multiple', 'truefalse'], true)) {
                $error = 'Unknown question type.';
            } elseif ($points < 1 || $points > 100) {
                $error = 'Marks must be between 1 and 100.';
            } else {
                $explanation = trim((string) ($_POST['explanation'] ?? '')) ?: null;

                // True/false writes its own options. Asking an author to type
                // "True" and "False" every time is how one of them ends up
                // spelled "Ture" on question 9.
                if ($type === 'truefalse') {
                    $texts   = ['True', 'False'];
                    $correct = [(string) (int) ($_POST['truefalse_correct'] ?? 0)];
                } else {
                    $texts   = (array) ($_POST['options'] ?? []);
                    $correct = (array) ($_POST['correct'] ?? []);
                }

                $db->beginTransaction();

                if ($action === 'add_question') {
                    $stmt = $db->prepare('SELECT COALESCE(MAX(position), 0) + 1 FROM quiz_questions WHERE quiz_id = ?');
                    $stmt->execute([$quizId]);
                    $position = (int) $stmt->fetchColumn();

                    $stmt = $db->prepare(
                        'INSERT INTO quiz_questions (quiz_id, type, text, points, position, explanation)
                         VALUES (?, ?, ?, ?, ?, ?)'
                    );
                    $stmt->execute([$quizId, $type, $text, $points, $position, $explanation]);
                    $questionId = (int) $db->lastInsertId();
                } else {
                    $questionId = (int) ($_POST['question_id'] ?? 0);

                    // The question must belong to THIS quiz. Without this, a
                    // posted question_id from another course's paper is edited
                    // by someone who cannot even see it.
                    $stmt = $db->prepare('SELECT id FROM quiz_questions WHERE id = ? AND quiz_id = ?');
                    $stmt->execute([$questionId, $quizId]);

                    if (! $stmt->fetchColumn()) {
                        $db->rollBack();
                        header('Location: ./quiz-edit.php?id=' . $quizId);
                        exit;
                    }

                    $stmt = $db->prepare(
                        'UPDATE quiz_questions SET type = ?, text = ?, points = ?, explanation = ? WHERE id = ?'
                    );
                    $stmt->execute([$type, $text, $points, $explanation, $questionId]);
                }

                mpc_save_options($db, $questionId, $type, $texts, $correct);
                $db->commit();

                header('Location: ./quiz-edit.php?id=' . $quizId . '&saved=1#q' . $questionId);
                exit;
            }
        } elseif ($action === 'delete_question') {
            $stmt = $db->prepare('DELETE FROM quiz_questions WHERE id = ? AND quiz_id = ?');
            $stmt->execute([(int) ($_POST['question_id'] ?? 0), $quizId]);

            header('Location: ./quiz-edit.php?id=' . $quizId . '&saved=1');
            exit;
        } elseif ($action === 'publish') {
            $blocker = mpc_publish_blocker($db, $quizId);

            if ($blocker !== null) {
                $error = $blocker;
            } else {
                $db->prepare('UPDATE quizzes SET is_published = 1 WHERE id = ?')->execute([$quizId]);
                header('Location: ./quiz-edit.php?id=' . $quizId . '&saved=1');
                exit;
            }
        } elseif ($action === 'unpublish') {
            // Unpublishing does NOT delete anything anyone has already done. The
            // sittings and their grades stay; the paper simply stops being
            // offered. Withdrawing a paper is not the same as cancelling an exam.
            $db->prepare('UPDATE quizzes SET is_published = 0 WHERE id = ?')->execute([$quizId]);
            header('Location: ./quiz-edit.php?id=' . $quizId . '&saved=1');
            exit;
        } elseif ($action === 'delete_quiz') {
            $stmt = $db->prepare(
                'SELECT COUNT(*) FROM quiz_attempts WHERE quiz_id = ? AND submitted_at IS NOT NULL'
            );
            $stmt->execute([$quizId]);

            // A paper somebody has sat is a record, not a draft. Deleting it
            // cascades to the attempts and takes real grades with it, so the
            // screen refuses and offers unpublishing instead — which achieves
            // the thing the person actually wanted.
            if ((int) $stmt->fetchColumn() > 0) {
                $error = 'Students have already sat this quiz, so it cannot be deleted — '
                       . 'that would erase their grades. Unpublish it instead.';
            } else {
                $db->prepare('DELETE FROM quizzes WHERE id = ?')->execute([$quizId]);
                header('Location: ./quizzes.php?deleted=' . urlencode((string) $quiz['title']));
                exit;
            }
        }
    } catch (PDOException $e) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        $error = 'Could not save that: ' . $e->getMessage();
    }

    // Re-read: a failed action above may still have changed part of the row.
    $quiz = mpc_quiz($quizId);
}

// The author's view of the paper — WITH the answer key, which is the difference
// between this page and the one a student sits. mpc_quiz_paper() cannot show
// it; this query is deliberately separate rather than a flag on that function,
// so there is no argument that turns the student's paper into the answer sheet.
$stmt = $db->prepare(
    'SELECT id, type, text, points, position, explanation
       FROM quiz_questions WHERE quiz_id = ? ORDER BY position, id'
);
$stmt->execute([$quizId]);
$questions = $stmt->fetchAll();

$options = [];
if ($questions) {
    $ids  = array_column($questions, 'id');
    $stmt = $db->prepare(
        'SELECT id, question_id, text, is_correct, position FROM quiz_options
          WHERE question_id IN (' . implode(',', array_fill(0, count($ids), '?')) . ')
          ORDER BY question_id, position, id'
    );
    $stmt->execute($ids);

    foreach ($stmt->fetchAll() as $o) {
        $options[(int) $o['question_id']][] = $o;
    }
}

$stmt = $db->prepare('SELECT COUNT(*) FROM quiz_attempts WHERE quiz_id = ? AND submitted_at IS NOT NULL');
$stmt->execute([$quizId]);
$sittings = (int) $stmt->fetchColumn();

$totalPoints = array_sum(array_map(static fn($q) => (int) $q['points'], $questions));
$blocker     = mpc_publish_blocker($db, $quizId);

$types = ['single' => 'One correct answer', 'multiple' => 'Several correct answers', 'truefalse' => 'True or false'];

mpc_page_head($quiz['title'], $user);
mpc_message($notice, true);
mpc_message($error, false);
?>

<p style="margin-top:-10px">
  <a href="./quizzes.php">&larr; All quizzes</a>
  &nbsp;&middot;&nbsp; <?= e($quiz['course_title']) ?>
  <?php if ($sittings > 0): ?>
    &nbsp;&middot;&nbsp; <a href="./quiz-grades.php?id=<?= $quizId ?>">Grades (<?= $sittings ?>)</a>
  <?php endif; ?>
</p>

<div class="card" style="border-color:<?= (int) $quiz['is_published'] === 1 ? MPC_GREEN : MPC_BORDER ?>">
  <div style="display:flex;gap:18px;align-items:flex-start;justify-content:space-between;flex-wrap:wrap">
    <div>
      <h2 style="font-size:1.05rem;margin-bottom:4px">
        <?= (int) $quiz['is_published'] === 1 ? 'Published' : 'Draft' ?>
      </h2>
      <p class="muted" style="margin:0">
        <?= count($questions) ?> question<?= count($questions) === 1 ? '' : 's' ?>,
        <?= $totalPoints ?> mark<?= $totalPoints === 1 ? '' : 's' ?>,
        pass at <?= (int) $quiz['pass_mark_percent'] ?>%.
        <?php if ((int) $quiz['is_published'] === 1): ?>
          Students on <?= e($quiz['course_title']) ?> can sit this now.
        <?php else: ?>
          No student can see this.
        <?php endif; ?>
      </p>
    </div>

    <form method="post" style="margin:0;display:flex;gap:10px;flex-wrap:wrap">
      <?php mpc_csrf_field(); ?>
      <input type="hidden" name="quiz_id" value="<?= $quizId ?>">
      <?php if ((int) $quiz['is_published'] === 1): ?>
        <button type="submit" name="action" value="unpublish"
                style="background:#fff;color:<?= MPC_TEXT ?>;border-color:<?= MPC_BORDER ?>">
          Unpublish
        </button>
      <?php else: ?>
        <button type="submit" name="action" value="publish" <?= $blocker !== null ? 'disabled' : '' ?>>
          Publish
        </button>
      <?php endif; ?>
    </form>
  </div>

  <?php if ($blocker !== null && (int) $quiz['is_published'] !== 1): ?>
    <p class="muted" style="margin:14px 0 0"><strong>Not ready to publish.</strong> <?= e($blocker) ?></p>
  <?php endif; ?>
</div>

<div class="card">
  <h2 style="font-size:1.05rem">Settings</h2>
  <form method="post">
    <?php mpc_csrf_field(); ?>
    <input type="hidden" name="quiz_id" value="<?= $quizId ?>">

    <div style="margin-bottom:16px">
      <label for="title">Title</label>
      <input type="text" id="title" name="title" required maxlength="160" value="<?= e($quiz['title']) ?>">
    </div>

    <div style="margin-bottom:16px">
      <label for="instructions">Instructions for the student</label>
      <textarea id="instructions" name="instructions" rows="3"
                placeholder="Optional. Shown above the questions."><?= e($quiz['instructions'] ?? '') ?></textarea>
    </div>

    <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(190px,1fr));gap:16px;margin-bottom:18px">
      <div>
        <label for="pass_mark_percent">Pass mark</label>
        <input type="number" id="pass_mark_percent" name="pass_mark_percent" min="1" max="100"
               value="<?= (int) $quiz['pass_mark_percent'] ?>">
      </div>
      <div>
        <label for="max_attempts">Attempts allowed</label>
        <input type="number" id="max_attempts" name="max_attempts" min="1" max="20"
               value="<?= (int) $quiz['max_attempts'] ?>">
        <p class="muted" style="margin:6px 0 0">Opening the paper uses one.</p>
      </div>
      <div>
        <label for="time_limit_minutes">Time limit</label>
        <input type="number" id="time_limit_minutes" name="time_limit_minutes" min="1"
               placeholder="Blank &mdash; untimed"
               value="<?= $quiz['time_limit_minutes'] !== null ? (int) $quiz['time_limit_minutes'] : '' ?>">
        <p class="muted" style="margin:6px 0 0">Minutes.</p>
      </div>
    </div>

    <label style="display:flex;gap:10px;align-items:center;font-weight:600;margin-bottom:20px">
      <input type="checkbox" name="shuffle_questions" value="1" style="width:auto"
             <?= (int) $quiz['shuffle_questions'] === 1 ? 'checked' : '' ?>>
      Shuffle the questions for each student
    </label>

    <button type="submit" name="action" value="settings">Save settings</button>
  </form>
</div>

<div class="card">
  <h2 style="font-size:1.05rem">Questions</h2>

  <?php if ($sittings > 0): ?>
    <p class="muted" style="margin-top:0">
      <strong><?= $sittings ?></strong> student<?= $sittings === 1 ? ' has' : 's have' ?>
      already sat this paper. Editing a question rewrites its options, so their
      recorded answers to it are dropped &mdash; their marks do not change, but
      the review screen will no longer show what they picked on that question.
    </p>
  <?php endif; ?>

  <?php if (! $questions): ?>
    <p class="muted">No questions yet. Add the first one below.</p>
  <?php endif; ?>

  <?php foreach ($questions as $n => $q):
      $qid  = (int) $q['id'];
      $opts = $options[$qid] ?? [];
      $tfCorrect = 0;
      foreach ($opts as $i => $o) {
          if ((int) $o['is_correct'] === 1) { $tfCorrect = $i; }
      }
  ?>
    <div id="q<?= $qid ?>" style="border-top:1px solid <?= MPC_BORDER ?>;padding-top:22px;margin-top:22px">
      <form method="post">
        <?php mpc_csrf_field(); ?>
        <input type="hidden" name="quiz_id" value="<?= $quizId ?>">
        <input type="hidden" name="question_id" value="<?= $qid ?>">

        <div style="display:flex;gap:12px;align-items:baseline;margin-bottom:10px">
          <strong style="color:<?= MPC_HEADING ?>">Question <?= $n + 1 ?></strong>
          <span class="muted"><?= e($types[$q['type']] ?? $q['type']) ?></span>
        </div>

        <div style="margin-bottom:14px">
          <textarea name="text" rows="2" required><?= e($q['text']) ?></textarea>
        </div>

        <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(190px,1fr));gap:16px;margin-bottom:14px">
          <div>
            <label for="type<?= $qid ?>">Type</label>
            <select id="type<?= $qid ?>" name="type">
              <?php foreach ($types as $v => $label): ?>
                <option value="<?= e($v) ?>" <?= $q['type'] === $v ? 'selected' : '' ?>><?= e($label) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div>
            <label for="points<?= $qid ?>">Marks</label>
            <input type="number" id="points<?= $qid ?>" name="points" min="1" max="100" value="<?= (int) $q['points'] ?>">
          </div>
        </div>

        <?php if ($q['type'] === 'truefalse'): ?>
          <label>The correct answer</label>
          <div style="display:flex;gap:20px;margin-bottom:14px">
            <label style="display:flex;gap:8px;align-items:center;font-weight:600">
              <input type="radio" name="truefalse_correct" value="0" style="width:auto"
                     <?= $tfCorrect === 0 ? 'checked' : '' ?>> True
            </label>
            <label style="display:flex;gap:8px;align-items:center;font-weight:600">
              <input type="radio" name="truefalse_correct" value="1" style="width:auto"
                     <?= $tfCorrect === 1 ? 'checked' : '' ?>> False
            </label>
          </div>
        <?php else: ?>
          <label>Options &mdash; tick every correct one, leave unused rows blank</label>
          <?php for ($i = 0; $i < 6; $i++):
              $o = $opts[$i] ?? null;
          ?>
            <div style="display:flex;gap:10px;align-items:center;margin-bottom:8px">
              <input type="checkbox" name="correct[]" value="<?= $i ?>" style="width:auto;flex:none"
                     <?= $o && (int) $o['is_correct'] === 1 ? 'checked' : '' ?>>
              <input type="text" name="options[]" maxlength="500"
                     placeholder="Option <?= $i + 1 ?>"
                     value="<?= e($o['text'] ?? '') ?>">
            </div>
          <?php endfor; ?>
        <?php endif; ?>

        <div style="margin:14px 0">
          <label for="explanation<?= $qid ?>">Explanation, shown after they submit</label>
          <textarea id="explanation<?= $qid ?>" name="explanation" rows="2"
                    placeholder="Optional. This is where the teaching happens."><?= e($q['explanation'] ?? '') ?></textarea>
        </div>

        <div style="display:flex;gap:10px;flex-wrap:wrap">
          <button type="submit" name="action" value="update_question">Save question <?= $n + 1 ?></button>
          <button type="submit" name="action" value="delete_question"
                  style="background:#fff;color:<?= MPC_RED ?>;border-color:<?= MPC_BORDER ?>"
                  onclick="return confirm('Delete question <?= $n + 1 ?>? Answers students gave to it are deleted with it. Their marks do not change.')">
            Delete
          </button>
        </div>
      </form>
    </div>
  <?php endforeach; ?>
</div>

<div class="card">
  <h2 style="font-size:1.05rem">Add a question</h2>
  <form method="post">
    <?php mpc_csrf_field(); ?>
    <input type="hidden" name="quiz_id" value="<?= $quizId ?>">

    <div style="margin-bottom:14px">
      <label for="newtext">Question</label>
      <textarea id="newtext" name="text" rows="2" required
                placeholder="What does a subnet mask do?"></textarea>
    </div>

    <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(190px,1fr));gap:16px;margin-bottom:14px">
      <div>
        <label for="newtype">Type</label>
        <select id="newtype" name="type">
          <?php foreach ($types as $v => $label): ?>
            <option value="<?= e($v) ?>"><?= e($label) ?></option>
          <?php endforeach; ?>
        </select>
        <p class="muted" style="margin:6px 0 0">
          True/false writes its own options &mdash; pick the answer below and
          leave the option rows blank.
        </p>
      </div>
      <div>
        <label for="newpoints">Marks</label>
        <input type="number" id="newpoints" name="points" min="1" max="100" value="1">
      </div>
    </div>

    <label>If true/false, the correct answer</label>
    <div style="display:flex;gap:20px;margin-bottom:16px">
      <label style="display:flex;gap:8px;align-items:center;font-weight:600">
        <input type="radio" name="truefalse_correct" value="0" style="width:auto" checked> True
      </label>
      <label style="display:flex;gap:8px;align-items:center;font-weight:600">
        <input type="radio" name="truefalse_correct" value="1" style="width:auto"> False
      </label>
    </div>

    <label>Otherwise, the options &mdash; tick every correct one</label>
    <?php for ($i = 0; $i < 6; $i++): ?>
      <div style="display:flex;gap:10px;align-items:center;margin-bottom:8px">
        <input type="checkbox" name="correct[]" value="<?= $i ?>" style="width:auto;flex:none">
        <input type="text" name="options[]" maxlength="500" placeholder="Option <?= $i + 1 ?>">
      </div>
    <?php endfor; ?>

    <div style="margin:14px 0 18px">
      <label for="newexplanation">Explanation, shown after they submit</label>
      <textarea id="newexplanation" name="explanation" rows="2"></textarea>
    </div>

    <button type="submit" name="action" value="add_question">Add question</button>
  </form>
</div>

<?php if ($sittings === 0): ?>
  <div class="card">
    <h2 style="font-size:1.05rem">Delete this quiz</h2>
    <p class="muted" style="margin-top:0">
      Nobody has sat it, so there are no grades to lose. Once a student has sat
      it this option disappears and unpublishing is the way to withdraw it.
    </p>
    <form method="post">
      <?php mpc_csrf_field(); ?>
      <input type="hidden" name="quiz_id" value="<?= $quizId ?>">
      <button type="submit" name="action" value="delete_quiz"
              style="background:#fff;color:<?= MPC_RED ?>;border-color:<?= MPC_BORDER ?>"
              onclick="return confirm('Delete this quiz and all its questions?')">
        Delete quiz
      </button>
    </form>
  </div>
<?php endif; ?>

<?php
mpc_page_foot();
