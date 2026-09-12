<?php
/**
 * Quizzes — the papers, and who has sat them.
 *
 * WHY THIS SCREEN IS GATED DIFFERENTLY FROM THE REST OF admin/
 * Every other screen in here calls mpc_require_login(), which admits staff and
 * admin only, because every other screen in here is about money. This one calls
 * mpc_require_teaching_staff(), which also admits instructors. An instructor
 * teaches; they do not work the payment desk. Two gates, and each screen says on
 * its first line which one it needs.
 *
 * WHAT AN INSTRUCTOR SEES
 * Only the courses they are assigned to teach, via intake_instructors. Staff and
 * admin see everything. That distinction lives entirely in lib/quiz.php — this
 * page never tests a role itself, it asks. A page that computes its own
 * visibility rule is a page that will compute it differently from the next one.
 *
 * CREATING A QUIZ DOES NOT PUBLISH IT. A new paper is a draft with no questions,
 * and a draft is invisible to students. That ordering is the whole safety
 * property: there is no window in which a half-written paper is sittable.
 */

declare(strict_types=1);

require_once dirname(__DIR__) . '/lib/auth.php';
require_once dirname(__DIR__) . '/lib/page.php';
require_once dirname(__DIR__) . '/lib/quiz.php';

$user = mpc_require_teaching_staff();
$db   = mpc_db();

// Deployed without its migration. Say which migration, and stop — every query
// below this line is against a table that is not there. See the comment on
// mpc_quiz_tables_present() for why this is the first deploy's normal state
// rather than a defensive flourish.
if (! mpc_quiz_tables_present()) {
    mpc_page_head('Quizzes', $user);
    echo '<div class="card"><h2 style="font-size:1.05rem">Not installed yet</h2><p style="margin-bottom:0">'
       . e(mpc_quiz_missing_tables_message()) . '</p></div>';
    mpc_page_foot();
    exit;
}

$error  = null;
$notice = null;

if (isset($_GET['created'])) {
    $notice = 'Created "' . (string) $_GET['created'] . '". Add its questions below, then publish it.';
}
if (isset($_GET['deleted'])) {
    $notice = 'Deleted "' . (string) $_GET['deleted'] . '".';
}

// The courses this person may write for. Staff and admin get all of them;
// mpc_quiz_author_courses() returns null for that, which is why this asks for
// the list rather than filtering one itself.
$allowed = mpc_quiz_author_courses($user);

$sql    = 'SELECT id, title FROM courses ORDER BY display_order';
$params = [];

if ($allowed !== null && $allowed !== []) {
    $sql = 'SELECT id, title FROM courses WHERE id IN ('
         . implode(',', array_fill(0, count($allowed), '?'))
         . ') ORDER BY display_order';
    $params = $allowed;
}

$courses = $allowed === [] ? [] : (function () use ($db, $sql, $params) {
    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll();
})();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    mpc_csrf_check();

    $courseId = (int) ($_POST['course_id'] ?? 0);
    $title    = trim((string) ($_POST['title'] ?? ''));

    // The permission check is on the COURSE, and it happens before anything is
    // written. Reading the course id from the form and trusting it is how an
    // instructor writes a paper for a class they do not teach — the select box
    // only lists their courses, and a select box is not a permission.
    if (! $title) {
        $error = 'Give the quiz a title — what the class would call it.';
    } elseif (! $courseId || ! mpc_quiz_may_author($user, $courseId)) {
        $error = 'Choose a program you teach.';
    } else {
        $stmt = $db->prepare(
            'INSERT INTO quizzes (course_id, title, created_by) VALUES (?, ?, ?)'
        );
        $stmt->execute([$courseId, $title, (int) $user['id']]);

        // Straight into the editor: a quiz with no questions is not finished,
        // and dropping the author back on a list invites them to think it is.
        header('Location: ./quiz-edit.php?id=' . (int) $db->lastInsertId() . '&created=1');
        exit;
    }
}

$quizzes = mpc_quiz_list_for_author($user);

mpc_page_head('Quizzes', $user);
mpc_message($notice, true);
?>

<?php if ($user['role'] === 'instructor' && ! $courses): ?>
  <div class="card">
    <h2 style="font-size:1.05rem">You are not assigned to any class yet</h2>
    <p style="margin-bottom:0">
      Quizzes belong to a program, and you can only write them for programs you
      teach. An administrator assigns you to an intake on the
      <strong>Intakes</strong> screen; once they have, the programs you teach
      appear here.
    </p>
  </div>
<?php else: ?>
  <div class="card">
    <h2 style="font-size:1.05rem">New quiz</h2>
    <p class="muted" style="margin-top:0">
      Creates a draft. Students cannot see it until you add questions and
      publish it.
    </p>

    <form method="post">
      <?php mpc_csrf_field(); ?>
      <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(240px,1fr));gap:16px;margin-bottom:18px">
        <div>
          <label for="course_id">Program</label>
          <select id="course_id" name="course_id" required>
            <option value="">Select</option>
            <?php foreach ($courses as $c): ?>
              <option value="<?= (int) $c['id'] ?>"
                <?= (int) ($_POST['course_id'] ?? 0) === (int) $c['id'] ? 'selected' : '' ?>>
                <?= e($c['title']) ?>
              </option>
            <?php endforeach; ?>
          </select>
        </div>
        <div>
          <label for="title">Quiz title</label>
          <input type="text" id="title" name="title" required maxlength="160"
                 placeholder="Week 4 &mdash; IP addressing"
                 value="<?= e($_POST['title'] ?? '') ?>">
        </div>
      </div>
      <button type="submit">Create draft</button>
      <?php mpc_message($error, false); ?>
    </form>
  </div>
<?php endif; ?>

<div class="card">
  <h2 style="font-size:1.05rem">All quizzes</h2>

  <?php if (! $quizzes): ?>
    <p class="muted" style="margin-bottom:0">
      None yet. A quiz is a paper for one program; every intake of that program
      sits the same one.
    </p>
  <?php else: ?>
    <table>
      <thead>
        <tr>
          <th>Quiz</th><th>Program</th><th class="num">Questions</th>
          <th class="num">Marks</th><th class="num">Pass</th>
          <th class="num">Sittings</th><th>Status</th><th></th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($quizzes as $q): ?>
          <tr>
            <td><strong><?= e($q['title']) ?></strong></td>
            <td><?= e($q['course_title']) ?></td>
            <td class="num"><?= (int) $q['question_count'] ?></td>
            <td class="num"><?= (int) $q['total_points'] ?></td>
            <td class="num"><?= (int) $q['pass_mark_percent'] ?>%</td>
            <td class="num"><?= (int) $q['sitting_count'] ?></td>
            <td>
              <?php if ((int) $q['is_published'] === 1): ?>
                <span style="color:<?= MPC_GREEN ?>;font-weight:700">Published</span>
              <?php else: ?>
                <span class="muted">Draft</span>
              <?php endif; ?>
            </td>
            <td>
              <a href="./quiz-edit.php?id=<?= (int) $q['id'] ?>" style="font-weight:600">Edit</a>
              <?php if ((int) $q['sitting_count'] > 0): ?>
                &nbsp;
                <a href="./quiz-grades.php?id=<?= (int) $q['id'] ?>" style="font-weight:600">Grades</a>
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
