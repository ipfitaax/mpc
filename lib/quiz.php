<?php
/**
 * Quizzes: who may write one, who may sit one, and what a sitting scored.
 *
 * Every rule the quiz screens enforce lives here rather than in the pages, for
 * the same reason lib/ledger.php exists: a rule written in a page is a rule
 * that holds on the pages someone remembered to write it on. There are four
 * routes into this feature already (author, publish, sit, review) and each one
 * is a chance to forget the payment gate or the answer key.
 *
 * THE ONE RULE THAT MATTERS MOST
 * `quiz_options.is_correct` never reaches a browser that is sitting a paper.
 * mpc_quiz_paper() names its columns and does not select it — it CANNOT leak it
 * even if a page hands its whole return value to the template — and
 * mpc_quiz_mark_attempt() is the only function that reads it. If you add a
 * function here that returns questions, decide which of those two it is.
 *
 * THE SECOND RULE
 * Nothing the browser posts becomes a score. The submission carries option ids
 * and nothing else; the points, the percentage and the pass flag are computed
 * here from the option rows and written by the server. A form field named
 * `score` would be marked and ignored, because there is no code path that reads
 * one.
 */

declare(strict_types=1);

require_once __DIR__ . '/auth.php';   // pulls in db.php

// The roles that may author and mark are MPC_OFFICE_ROLES from lib/auth.php —
// deliberately not a second list here. See the comment on that constant: "who
// works here", "who holds a password" and "who Google may not sign in" are one
// set seen from three sides, and a private copy in this file is how they stop
// agreeing.


// ===========================================================================
// IS THIS FEATURE INSTALLED AT ALL
// ===========================================================================

/**
 * Are the assessment tables actually on this server?
 *
 * WHY THIS EXISTS, AND IT IS NOT DEFENSIVE PROGRAMMING FOR ITS OWN SAKE.
 * The code and the database deploy by completely separate routes. The files
 * arrive when somebody presses "Deploy HEAD Commit" in cPanel; the tables
 * arrive only when somebody runs migrations/004-assessment.sql by hand against
 * the production database. There is nothing whatsoever tying those two events
 * together, so the window where the first has happened and the second has not
 * is not an edge case — it is the DEFAULT state of the first deploy, and it
 * lasts until a human notices.
 *
 * Without this check, that window looks like an uncaught PDOException on a
 * shared host: a white page with a stack trace, shown to whoever opened the
 * screen. With it, the screen says which migration to run. The same argument
 * the Google endpoint already makes by answering "not switched on yet" instead
 * of throwing when its config key is absent — a missing dependency is a
 * supported state that explains itself, not a crash.
 *
 * Cached for the request. It is one cheap query and it is asked by several
 * screens, but the real reason to cache is that a page which asks twice and
 * gets two different answers is worse than either answer.
 */
function mpc_quiz_tables_present(): bool
{
    static $present = null;

    if ($present !== null) {
        return $present;
    }

    try {
        // SHOW TABLES rather than information_schema: the restricted
        // application user can always see its own tables this way, and
        // information_schema visibility depends on privileges the app
        // deliberately does not hold — the same trap documented on
        // mpc_assert_append_only() in lib/db.php, where an empty result was
        // indistinguishable from "the thing is gone".
        $stmt = mpc_db()->query("SHOW TABLES LIKE 'quiz_attempts'");

        return $present = ($stmt !== false && $stmt->fetchColumn() !== false);
    } catch (Throwable $e) {
        return $present = false;
    }
}

/** The sentence an office screen shows when the tables are not there. One
 *  place, so the three screens cannot drift into naming different migrations. */
function mpc_quiz_missing_tables_message(): string
{
    return 'The quiz tables are not installed on this server. The code for this '
         . 'feature has deployed but its database migration has not been run: '
         . 'run database/migrations/004-assessment.sql against this site\'s '
         . 'database, including its GRANT section, and reload this page.';
}


// ===========================================================================
// WHO MAY AUTHOR
// ===========================================================================

/**
 * The courses this user may write and mark quizzes on.
 *
 * Returns NULL for "every course" — staff and admin — and an array of course
 * ids for an instructor. NULL is not the same as an empty array and the
 * difference is load-bearing: an empty array means "this instructor is assigned
 * to no class and may touch nothing", and if a caller confuses the two it
 * either shows an instructor every course or shows staff none. Callers go
 * through mpc_quiz_may_author() instead of testing the shape themselves.
 *
 * The path is instructor -> intake_instructors -> intakes -> course. An
 * instructor who teaches ANY intake of a course may write quizzes on that
 * course; see the comment on `quizzes.course_id` in ledger.sql for what that
 * deliberately does not scope.
 */
function mpc_quiz_author_courses(array $user): ?array
{
    if (in_array($user['role'], ['staff', 'admin'], true)) {
        return null;
    }

    if ($user['role'] !== 'instructor') {
        return [];
    }

    $stmt = mpc_db()->prepare(
        'SELECT DISTINCT i.course_id
           FROM intake_instructors ii
           JOIN intakes i ON i.id = ii.intake_id
          WHERE ii.user_id = ?'
    );
    $stmt->execute([(int) $user['id']]);

    return array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
}

/** May this user write or mark quizzes on this course? */
function mpc_quiz_may_author(array $user, int $courseId): bool
{
    $courses = mpc_quiz_author_courses($user);

    return $courses === null || in_array($courseId, $courses, true);
}

/**
 * Sends anyone who may not author quizzes to the office login page.
 *
 * SEPARATE from mpc_require_login(), which admits only staff and admin, and
 * that separation is the point. Instructors belong on the quiz screens and have
 * no business on `payments.php`. One shared gate would mean either instructors
 * can reach the money or staff cannot reach the quizzes; two gates means each
 * screen states which one it needs on its first line.
 */
function mpc_require_teaching_staff(): array
{
    $user = mpc_current_user();

    if (! $user || ! in_array($user['role'], MPC_OFFICE_ROLES, true)) {
        $target = $_SERVER['REQUEST_URI'] ?? '/mpc/admin/';
        header('Location: ./login.php?next=' . urlencode($target));
        exit;
    }

    return $user;
}

/** The quiz, or null. Includes the course title, because every screen that
 *  shows a quiz shows which course it is on. */
function mpc_quiz(int $quizId): ?array
{
    $stmt = mpc_db()->prepare(
        'SELECT q.*, c.title AS course_title
           FROM quizzes q
           JOIN courses c ON c.id = q.course_id
          WHERE q.id = ?'
    );
    $stmt->execute([$quizId]);

    return $stmt->fetch() ?: null;
}

/**
 * Quizzes this user may see on the office side, newest first.
 *
 * Drafts are included — this is the authoring list, and a draft that is
 * invisible to its author is a draft that gets written twice.
 */
function mpc_quiz_list_for_author(array $user): array
{
    $courses = mpc_quiz_author_courses($user);

    $sql = 'SELECT q.id, q.title, q.is_published, q.pass_mark_percent, q.max_attempts,
                   q.created_at, c.title AS course_title,
                   (SELECT COUNT(*) FROM quiz_questions qq WHERE qq.quiz_id = q.id) AS question_count,
                   (SELECT COALESCE(SUM(qq.points), 0) FROM quiz_questions qq WHERE qq.quiz_id = q.id) AS total_points,
                   (SELECT COUNT(*) FROM quiz_attempts a
                     WHERE a.quiz_id = q.id AND a.submitted_at IS NOT NULL) AS sitting_count
              FROM quizzes q
              JOIN courses c ON c.id = q.course_id';

    $params = [];

    if ($courses !== null) {
        if ($courses === []) {
            return [];
        }
        $sql .= ' WHERE q.course_id IN (' . implode(',', array_fill(0, count($courses), '?')) . ')';
        $params = $courses;
    }

    $sql .= ' ORDER BY q.created_at DESC, q.id DESC';

    $stmt = mpc_db()->prepare($sql);
    $stmt->execute($params);

    return $stmt->fetchAll();
}


// ===========================================================================
// WHO MAY SIT
// ===========================================================================

/**
 * Whether a student may sit this paper right now, and if not, why.
 *
 * Returns ['ok' => bool, 'reason' => string, 'enrollment_id' => ?int]. The
 * reason is written to be READ BY THE STUDENT and is deliberately specific —
 * "your fees are paid to month 2 of 6" rather than "access denied". A student
 * blocked from an exam will ring the office, and the person answering the phone
 * needs the student to be able to say what the screen said. A generic refusal
 * turns every one of those calls into a support investigation.
 *
 * THE FOUR GATES, in the order a student hits them:
 *   1. The paper is published. An unpublished draft does not exist to students.
 *   2. They are enrolled, with status 'active', on an intake of that course.
 *   3. Their fees are paid up to the current month — see mpc_enrollment_is_paid_up().
 *   4. They have attempts left.
 *
 * Gate 3 is MPC's decision and it has a cost worth restating where the code
 * enforces it: a payment taken at the desk but not yet keyed in locks a student
 * out of an exam they have paid for. That failure looks to the student like a
 * broken website, which is why the reason string names the figures and the
 * office phone number rather than saying no.
 */
function mpc_student_quiz_access(int $userId, array $quiz): array
{
    $deny = static fn(string $why): array => ['ok' => false, 'reason' => $why, 'enrollment_id' => null];

    if ((int) $quiz['is_published'] !== 1) {
        return $deny('This quiz is not open yet. Your instructor publishes it when the class is ready to sit it.');
    }

    // The enrolment this sitting happens under. A student could in principle
    // hold two enrolments on two intakes of the same course — a repeat — so
    // this takes the most recent, which is the one they are actually studying.
    $stmt = mpc_db()->prepare(
        'SELECT e.id, e.status, i.name AS intake_name
           FROM enrollments e
           JOIN intakes i ON i.id = e.intake_id
          WHERE e.user_id = ? AND i.course_id = ?
          ORDER BY i.starts_on DESC, e.id DESC
          LIMIT 1'
    );
    $stmt->execute([$userId, (int) $quiz['course_id']]);
    $enrollment = $stmt->fetch();

    if (! $enrollment) {
        return $deny('You are not enrolled on ' . ($quiz['course_title'] ?? 'this course')
                   . '. If you believe you are, call the office on +252 770 51 90 98.');
    }

    if ($enrollment['status'] !== 'active') {
        return $deny('Your enrolment on ' . ($quiz['course_title'] ?? 'this course') . ' is '
                   . $enrollment['status'] . ', so you cannot sit quizzes on it. '
                   . 'Call the office on +252 770 51 90 98.');
    }

    $paid = mpc_enrollment_is_paid_up((int) $enrollment['id']);

    if (! $paid['ok']) {
        return $deny($paid['reason']);
    }

    $used = mpc_quiz_attempts_used((int) $quiz['id'], $userId);
    $max  = (int) $quiz['max_attempts'];

    if ($max > 0 && $used >= $max) {
        return $deny($max === 1
            ? 'You have used your one attempt at this quiz. Your result is below.'
            : 'You have used all ' . $max . ' of your attempts at this quiz. Your result is below.');
    }

    return ['ok' => true, 'reason' => '', 'enrollment_id' => (int) $enrollment['id']];
}

/**
 * Is this enrolment paid up to the current month?
 *
 * MPC's fees are MONTHLY — see migrations/002. So "paid up" is not "the balance
 * is zero": a student three months into a six-month course who has paid three
 * months is fully paid up and owes $300. Comparing against the total would lock
 * out every student who is not paying a year in advance, which is all of them.
 *
 * months_due counts the month the class is IN, so a class that started this
 * month owes one month. It is capped at fee_months — the course cannot come to
 * owe more months than it has — and floored at zero for an intake that has not
 * started, where the honest answer is that nothing is due yet.
 *
 * WHERE THIS DELIBERATELY FAILS OPEN: if the intake has no start date, or the
 * enrolment has no agreed fee, this returns ok. The alternative is refusing a
 * student an exam because an office record is incomplete, which punishes the
 * student for the institute's paperwork. Missing data is not evidence of
 * non-payment.
 */
function mpc_enrollment_is_paid_up(int $enrollmentId): array
{
    require_once __DIR__ . '/ledger.php';

    $stmt = mpc_db()->prepare(
        'SELECT e.fee_agreed, e.fee_months, i.starts_on
           FROM enrollments e
           JOIN intakes i ON i.id = e.intake_id
          WHERE e.id = ?'
    );
    $stmt->execute([$enrollmentId]);
    $row = $stmt->fetch();

    if (! $row || $row['starts_on'] === null
        || $row['fee_agreed'] === null || (float) $row['fee_agreed'] <= 0) {
        return ['ok' => true, 'reason' => '', 'months_due' => 0, 'months_paid' => 0];
    }

    $balance    = mpc_enrollment_balance($enrollmentId);
    $monthsPaid = (int) $balance['months_paid'];
    $monthsDue  = mpc_months_due((string) $row['starts_on'], (int) $row['fee_months']);

    if ($monthsPaid >= $monthsDue) {
        return ['ok' => true, 'reason' => '', 'months_due' => $monthsDue, 'months_paid' => $monthsPaid];
    }

    return [
        'ok'          => false,
        'months_due'  => $monthsDue,
        'months_paid' => $monthsPaid,
        'reason'      =>
            'Your fees are paid up to month ' . $monthsPaid . ' of ' . (int) $row['fee_months']
            . ', and month ' . $monthsDue . ' is now due, so this quiz is closed to you. '
            . 'If you have already paid, the office may not have recorded it yet — '
            . 'bring your receipt or call +252 770 51 90 98 and it will be opened today.',
    ];
}

/**
 * How many monthly instalments are due by today, given a start date.
 *
 * Its own function because it is the one piece of arithmetic here that is easy
 * to get quietly wrong and impossible to spot in a page. A student who started
 * on the 30th of a month and a month with 28 days is exactly the case that
 * makes a naive day-count answer differ from what a human at the desk would
 * say, so this counts CALENDAR months elapsed and adds one for the month in
 * progress, which is what "your third month" means to the person paying.
 */
function mpc_months_due(string $startsOn, int $feeMonths): int
{
    $start = date_create($startsOn);
    $today = date_create('today');

    if (! $start || ! $today || $start > $today) {
        return 0;   // the class has not started; nothing is due
    }

    $diff     = $start->diff($today);
    $elapsed  = ($diff->y * 12) + $diff->m;
    $due      = $elapsed + 1;

    if ($feeMonths > 0 && $due > $feeMonths) {
        $due = $feeMonths;
    }

    return $due;
}

/**
 * Attempts this student has STARTED on this paper.
 *
 * Started, not submitted, and that is the whole point — see the comment on
 * `quiz_attempts` in ledger.sql. Counting submissions lets a student open the
 * paper, read every question, abandon it, and return with the questions known
 * and no attempt spent.
 */
function mpc_quiz_attempts_used(int $quizId, int $userId): int
{
    $stmt = mpc_db()->prepare(
        'SELECT COUNT(*) FROM quiz_attempts WHERE quiz_id = ? AND user_id = ?'
    );
    $stmt->execute([$quizId, $userId]);

    return (int) $stmt->fetchColumn();
}


// ===========================================================================
// THE PAPER
// ===========================================================================

/**
 * The questions and options for a paper, WITHOUT the answer key.
 *
 * `is_correct` is not in the SELECT list. Not filtered out afterwards, not
 * unset by the caller — never fetched. That is the difference between a rule
 * and a habit: a page can hand this entire array to a template, dump it, or log
 * it, and the answers are still not in it.
 *
 * `explanation` is left out for the same reason. It is written to be read after
 * marking and it routinely gives the answer away.
 *
 * $shuffle is passed in rather than read from the quiz row because the results
 * screen re-renders the same paper and must NOT shuffle: a student comparing
 * their answers against a reordered paper is being shown nonsense.
 */
function mpc_quiz_paper(int $quizId, bool $shuffle = false): array
{
    $db = mpc_db();

    $stmt = $db->prepare(
        'SELECT id, type, text, points, position
           FROM quiz_questions
          WHERE quiz_id = ?
          ORDER BY position, id'
    );
    $stmt->execute([$quizId]);
    $questions = $stmt->fetchAll();

    if (! $questions) {
        return [];
    }

    $ids  = array_column($questions, 'id');
    $in   = implode(',', array_fill(0, count($ids), '?'));

    // NOTE the column list. is_correct is absent on purpose. See above.
    $stmt = $db->prepare(
        "SELECT id, question_id, text, position
           FROM quiz_options
          WHERE question_id IN ($in)
          ORDER BY question_id, position, id"
    );
    $stmt->execute($ids);

    $byQuestion = [];
    foreach ($stmt->fetchAll() as $option) {
        $byQuestion[(int) $option['question_id']][] = $option;
    }

    foreach ($questions as &$question) {
        $question['options'] = $byQuestion[(int) $question['id']] ?? [];
    }
    unset($question);

    if ($shuffle) {
        shuffle($questions);
    }

    return $questions;
}

/**
 * Opens a sitting, or returns the one already open.
 *
 * Returning an existing unsubmitted attempt is what makes a reloaded page
 * harmless. Without it, a student who refreshes burns an attempt, and on a
 * one-attempt paper a stray F5 costs them the exam.
 *
 * The attempt number is computed from the count rather than passed in, and the
 * UNIQUE key on (quiz_id, user_id, attempt_no) is what makes that safe: two
 * requests racing produce one row and one duplicate-key error, not two attempts
 * numbered 2.
 */
function mpc_quiz_open_attempt(int $quizId, int $userId, ?int $enrollmentId): array
{
    $db = mpc_db();

    $stmt = $db->prepare(
        'SELECT * FROM quiz_attempts
          WHERE quiz_id = ? AND user_id = ? AND submitted_at IS NULL
          ORDER BY id DESC LIMIT 1'
    );
    $stmt->execute([$quizId, $userId]);

    if ($open = $stmt->fetch()) {
        return $open;
    }

    $next = mpc_quiz_attempts_used($quizId, $userId) + 1;

    $stmt = $db->prepare(
        'INSERT INTO quiz_attempts (quiz_id, user_id, enrollment_id, attempt_no)
         VALUES (?, ?, ?, ?)'
    );
    $stmt->execute([$quizId, $userId, $enrollmentId, $next]);

    $stmt = $db->prepare('SELECT * FROM quiz_attempts WHERE id = ?');
    $stmt->execute([(int) $db->lastInsertId()]);

    return $stmt->fetch();
}


// ===========================================================================
// MARKING
// ===========================================================================

/**
 * Marks a sitting and writes the score. The only function that reads the
 * answer key, and the only one that writes a grade.
 *
 * $selected maps question id => array of option ids the student chose. The
 * caller extracts that from the POST; nothing else from the POST is used.
 *
 * MARKING IS ALL-OR-NOTHING ON MULTI-SELECT. A question with three correct
 * options scores full marks for exactly those three and zero for anything else,
 * including two-of-three. Partial credit is defensible — it is also four
 * different schemes that each produce a different grade for the same paper, and
 * choosing between them silently in code means no student can be told how their
 * mark was reached. If MPC wants partial credit, it is a decision to make out
 * loud and record here, not a default to drift into.
 *
 * Everything happens in one transaction: the answer rows and the score are one
 * fact. A crash between them would leave an attempt that looks unsubmitted but
 * has answers recorded, which on a one-attempt paper is a student who can never
 * sit it and never get a mark.
 *
 * IDEMPOTENT BY REFUSAL. An already-submitted attempt is returned unchanged
 * rather than re-marked, so the back button and a double-clicked Submit cannot
 * produce a second set of answer rows.
 */
function mpc_quiz_mark_attempt(int $attemptId, array $selected): array
{
    $db = mpc_db();

    $stmt = $db->prepare('SELECT * FROM quiz_attempts WHERE id = ?');
    $stmt->execute([$attemptId]);
    $attempt = $stmt->fetch();

    if (! $attempt) {
        throw new RuntimeException('No such attempt.');
    }

    if ($attempt['submitted_at'] !== null) {
        return $attempt;
    }

    $quiz = mpc_quiz((int) $attempt['quiz_id']);
    if (! $quiz) {
        throw new RuntimeException('The quiz this attempt belongs to is gone.');
    }

    // The key, read here and nowhere else.
    $stmt = $db->prepare(
        'SELECT q.id AS question_id, q.type, q.points, o.id AS option_id, o.is_correct
           FROM quiz_questions q
           LEFT JOIN quiz_options o ON o.question_id = q.id
          WHERE q.quiz_id = ?
          ORDER BY q.position, q.id'
    );
    $stmt->execute([(int) $attempt['quiz_id']]);

    $questions = [];
    foreach ($stmt->fetchAll() as $row) {
        $qid = (int) $row['question_id'];

        $questions[$qid] ??= ['points' => (int) $row['points'], 'correct' => [], 'options' => []];

        if ($row['option_id'] !== null) {
            $questions[$qid]['options'][] = (int) $row['option_id'];
            if ((int) $row['is_correct'] === 1) {
                $questions[$qid]['correct'][] = (int) $row['option_id'];
            }
        }
    }

    $scored = 0;
    $total  = 0;
    $rows   = [];

    foreach ($questions as $qid => $question) {
        $total += $question['points'];

        // Only options that belong to THIS question count. A posted option id
        // from another question — or another quiz — is dropped rather than
        // matched, so a hand-edited form cannot smuggle in a correct option
        // borrowed from somewhere else.
        $chosen = array_values(array_intersect(
            array_map('intval', $selected[$qid] ?? []),
            $question['options']
        ));

        sort($chosen);
        $key = $question['correct'];
        sort($key);

        // A question with no correct option marked is unanswerable, and scoring
        // every student zero on it would be blaming them for the paper. It is
        // worth nothing to everyone: excluded from the total, so a broken
        // question cannot drag a class below the pass mark.
        if ($key === []) {
            $total -= $question['points'];
            $awarded = 0;
            $right   = null;
        } else {
            $right   = $chosen === $key;
            $awarded = $right ? $question['points'] : 0;
        }

        $scored += $awarded;

        if ($chosen === []) {
            // Nothing selected: no answer rows. Absence IS the record of a
            // blank, and it scores the same zero as a wrong answer.
            continue;
        }

        foreach ($chosen as $optionId) {
            $rows[] = [
                $attemptId,
                $qid,
                $optionId,
                $right === null ? null : (int) $right,
                // The points sit on the FIRST answer row of a question, not on
                // each, or a three-option answer worth 2 points would sum to 6.
                $optionId === $chosen[0] ? $awarded : 0,
            ];
        }
    }

    $percent = $total > 0 ? round(($scored / $total) * 100, 2) : 0.00;
    $passed  = $percent >= (float) $quiz['pass_mark_percent'] ? 1 : 0;

    $db->beginTransaction();

    try {
        if ($rows) {
            $insert = $db->prepare(
                'INSERT INTO quiz_answers (attempt_id, question_id, option_id, is_correct, points_awarded)
                 VALUES (?, ?, ?, ?, ?)'
            );
            foreach ($rows as $row) {
                $insert->execute($row);
            }
        }

        // submitted_at from the database clock, never from PHP and never from
        // the form. The connection sets Africa/Mogadishu (see lib/db.php), so
        // this is the same clock every other timestamp in the system uses.
        $stmt = $db->prepare(
            'UPDATE quiz_attempts
                SET submitted_at = NOW(), score_points = ?, total_points = ?,
                    score_percent = ?, passed = ?
              WHERE id = ? AND submitted_at IS NULL'
        );
        $stmt->execute([$scored, $total, $percent, $passed, $attemptId]);

        $db->commit();
    } catch (Throwable $e) {
        $db->rollBack();
        throw $e;
    }

    $stmt = $db->prepare('SELECT * FROM quiz_attempts WHERE id = ?');
    $stmt->execute([$attemptId]);

    return $stmt->fetch();
}

/**
 * A marked sitting, question by question, for the review screen.
 *
 * THIS one selects `is_correct`, and it is safe because it is only ever called
 * for an attempt that has been submitted — the caller checks, and so does this.
 * Showing a student which options were right is the entire teaching value of a
 * quiz; showing them before they answer is the entire failure of one.
 */
function mpc_quiz_review(int $attemptId): array
{
    $db = mpc_db();

    $stmt = $db->prepare('SELECT * FROM quiz_attempts WHERE id = ? AND submitted_at IS NOT NULL');
    $stmt->execute([$attemptId]);

    if (! $attempt = $stmt->fetch()) {
        return [];
    }

    $stmt = $db->prepare(
        'SELECT q.id AS question_id, q.text, q.points, q.explanation, q.position,
                o.id AS option_id, o.text AS option_text, o.is_correct,
                (a.id IS NOT NULL) AS chosen
           FROM quiz_questions q
           LEFT JOIN quiz_options o ON o.question_id = q.id
           LEFT JOIN quiz_answers a ON a.option_id = o.id AND a.attempt_id = ?
          WHERE q.quiz_id = ?
          ORDER BY q.position, q.id, o.position, o.id'
    );
    $stmt->execute([$attemptId, (int) $attempt['quiz_id']]);

    $questions = [];

    foreach ($stmt->fetchAll() as $row) {
        $qid = (int) $row['question_id'];

        $questions[$qid] ??= [
            'id'          => $qid,
            'text'        => $row['text'],
            'points'      => (int) $row['points'],
            'explanation' => $row['explanation'],
            'options'     => [],
            'correct'     => true,
        ];

        if ($row['option_id'] === null) {
            continue;
        }

        $isCorrect = (int) $row['is_correct'] === 1;
        $chosen    = (int) $row['chosen'] === 1;

        $questions[$qid]['options'][] = [
            'id'         => (int) $row['option_id'],
            'text'       => $row['option_text'],
            'is_correct' => $isCorrect,
            'chosen'     => $chosen,
        ];

        // Right only if every correct option was picked and no wrong one was.
        if ($isCorrect !== $chosen) {
            $questions[$qid]['correct'] = false;
        }
    }

    return array_values($questions);
}

/** This student's submitted sittings of one paper, newest first. */
function mpc_quiz_student_attempts(int $quizId, int $userId): array
{
    $stmt = mpc_db()->prepare(
        'SELECT * FROM quiz_attempts
          WHERE quiz_id = ? AND user_id = ? AND submitted_at IS NOT NULL
          ORDER BY attempt_no DESC'
    );
    $stmt->execute([$quizId, $userId]);

    return $stmt->fetchAll();
}

/**
 * Every published paper on the courses this student is enrolled on, with their
 * best result on each.
 *
 * Best, not latest: on a paper that allows three attempts, the grade that
 * counts is the one the student earned, and showing the most recent one turns a
 * practice re-sit into an apparent drop in performance.
 */
function mpc_student_quiz_list(int $userId): array
{
    $stmt = mpc_db()->prepare(
        'SELECT q.id, q.title, q.pass_mark_percent, q.max_attempts, q.time_limit_minutes,
                c.title AS course_title,
                (SELECT COUNT(*) FROM quiz_questions qq WHERE qq.quiz_id = q.id) AS question_count,
                (SELECT COUNT(*) FROM quiz_attempts a
                  WHERE a.quiz_id = q.id AND a.user_id = ?) AS attempts_used,
                (SELECT MAX(a.score_percent) FROM quiz_attempts a
                  WHERE a.quiz_id = q.id AND a.user_id = ? AND a.submitted_at IS NOT NULL) AS best_percent,
                (SELECT MAX(a.passed) FROM quiz_attempts a
                  WHERE a.quiz_id = q.id AND a.user_id = ? AND a.submitted_at IS NOT NULL) AS ever_passed
           FROM quizzes q
           JOIN courses c     ON c.id = q.course_id
           JOIN intakes i     ON i.course_id = c.id
           JOIN enrollments e ON e.intake_id = i.id AND e.user_id = ?
          WHERE q.is_published = 1 AND e.status = "active"
          GROUP BY q.id
          ORDER BY c.title, q.created_at DESC'
    );
    $stmt->execute([$userId, $userId, $userId, $userId]);

    return $stmt->fetchAll();
}

/**
 * The gradebook for one paper: every student who sat it, best attempt first
 * by name.
 *
 * Students who have NOT sat it are included, with nulls. That is the column the
 * office actually reads — a list of who has done it is answerable by looking at
 * a list of who has not, and a report that silently omits them makes a class of
 * thirty look like a class of nine.
 */
function mpc_quiz_gradebook(int $quizId): array
{
    $stmt = mpc_db()->prepare(
        'SELECT u.id AS user_id, u.full_name, u.phone, i.name AS intake_name,
                a.id AS attempt_id, a.attempt_no, a.submitted_at,
                a.score_points, a.total_points, a.score_percent, a.passed
           FROM quizzes q
           JOIN intakes i     ON i.course_id = q.course_id
           JOIN enrollments e ON e.intake_id = i.id AND e.status = "active"
           JOIN users u       ON u.id = e.user_id
           LEFT JOIN quiz_attempts a
                  ON a.user_id = u.id AND a.quiz_id = q.id
                 AND a.submitted_at IS NOT NULL
                 AND a.score_percent = (
                       SELECT MAX(b.score_percent) FROM quiz_attempts b
                        WHERE b.user_id = u.id AND b.quiz_id = q.id
                          AND b.submitted_at IS NOT NULL)
          WHERE q.id = ?
          GROUP BY u.id, i.name
          ORDER BY u.full_name'
    );
    $stmt->execute([$quizId]);

    return $stmt->fetchAll();
}
