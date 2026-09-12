<?php
/**
 * The quiz rules.
 *
 * Every assertion here is a rule that would be invisible if it broke. A leaked
 * answer key does not throw; a payment gate that admits everyone renders
 * identically to one that works; a mis-marked multi-select produces a number
 * that looks exactly like a real grade. None of these fail loudly in a browser,
 * which is why they are tested rather than clicked through.
 *
 * The suite runs as the RESTRICTED application user, with the owner used only
 * for fixtures and for asserting on things the application deliberately cannot
 * do. A test that passes as owner and fails as the app means the grants are
 * wrong, and that is a finding rather than a nuisance.
 */

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class QuizTest extends TestCase
{
    private int $courseId;
    private int $otherCourseId;
    private int $intakeId;
    private int $studentId;
    private int $enrollmentId;
    private int $quizId;

    /** question id => [correct option ids], filled by makeQuiz(). */
    private array $key = [];

    protected function setUp(): void
    {
        test_reset();

        $db = test_owner();

        $this->courseId = (int) $db->query(
            "SELECT id FROM courses WHERE slug = 'six-month-intensive'"
        )->fetchColumn();
        $this->otherCourseId = (int) $db->query(
            "SELECT id FROM courses WHERE slug = 'one-year-professional'"
        )->fetchColumn();

        // An intake that starts TODAY, so exactly one monthly instalment is due
        // and the single payment below makes this student paid up. Every test
        // that needs a student who is BEHIND moves the start date back with
        // startedMonthsAgo() rather than deleting the payment — payments is
        // append-only and its trigger refuses the delete even to the owner,
        // which is the ledger correctly defending itself against its own tests.
        $db->prepare(
            "INSERT INTO intakes (course_id, name, starts_on, status)
             VALUES (?, 'Test intake', ?, 'running')"
        )->execute([$this->courseId, date('Y-m-d')]);
        $this->intakeId = (int) $db->lastInsertId();

        $db->exec("INSERT INTO users (full_name, email, role) VALUES ('Aamina Cabdi', 'aamina@example.com', 'student')");
        $this->studentId = (int) $db->lastInsertId();

        $db->prepare(
            "INSERT INTO enrollments (user_id, intake_id, status, fee_agreed, fee_months)
             VALUES (?, ?, 'active', 100.00, 6)"
        )->execute([$this->studentId, $this->intakeId]);
        $this->enrollmentId = (int) $db->lastInsertId();

        $this->quizId = $this->makeQuiz();
        $this->payMonths(1);
    }

    /**
     * A published three-question paper: one single-answer worth 2, one
     * multi-select worth 3, one true/false worth 1. Six marks in total.
     */
    private function makeQuiz(int $courseId = 0, bool $published = true): int
    {
        $db = test_owner();

        $db->prepare(
            'INSERT INTO quizzes (course_id, title, pass_mark_percent, max_attempts, shuffle_questions, is_published)
             VALUES (?, ?, 50, 1, 0, ?)'
        )->execute([$courseId ?: $this->courseId, 'Week 4 test', $published ? 1 : 0]);

        $quizId = (int) $db->lastInsertId();

        $questions = [
            ['single',    'What does a subnet mask do?', 2, [['Splits a network', 1], ['Encrypts traffic', 0], ['Assigns MAC addresses', 0]]],
            ['multiple',  'Which are private ranges?',   3, [['10.0.0.0/8', 1], ['172.16.0.0/12', 1], ['8.8.8.0/24', 0], ['1.1.1.0/24', 0]]],
            ['truefalse', 'TCP guarantees delivery.',    1, [['True', 1], ['False', 0]]],
        ];

        foreach ($questions as $position => [$type, $text, $points, $options]) {
            $db->prepare(
                'INSERT INTO quiz_questions (quiz_id, type, text, points, position)
                 VALUES (?, ?, ?, ?, ?)'
            )->execute([$quizId, $type, $text, $points, $position]);

            $questionId = (int) $db->lastInsertId();
            $this->key[$questionId] = [];

            foreach ($options as $i => [$optionText, $correct]) {
                $db->prepare(
                    'INSERT INTO quiz_options (question_id, text, is_correct, position)
                     VALUES (?, ?, ?, ?)'
                )->execute([$questionId, $optionText, $correct, $i]);

                if ($correct) {
                    $this->key[$questionId][] = (int) $db->lastInsertId();
                }
            }
        }

        return $quizId;
    }

    /** Question ids of this quiz, in order. */
    private function questionIds(int $quizId = 0): array
    {
        $stmt = test_owner()->prepare(
            'SELECT id FROM quiz_questions WHERE quiz_id = ? ORDER BY position, id'
        );
        $stmt->execute([$quizId ?: $this->quizId]);

        return array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
    }

    /** Every wrong option for a question. */
    private function wrongOptions(int $questionId): array
    {
        $stmt = test_owner()->prepare(
            'SELECT id FROM quiz_options WHERE question_id = ? AND is_correct = 0 ORDER BY position'
        );
        $stmt->execute([$questionId]);

        return array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
    }

    /** Records N months of fees against the enrolment, as the owner. */
    private function payMonths(int $months): void
    {
        if ($months < 1) {
            return;
        }

        // Written as the owner rather than through mpc_record_payment(), which
        // would create its own student and enrolment. This test needs the money
        // attached to the enrolment the fixture already built.
        test_owner()->prepare(
            "INSERT INTO payments (enrollment_id, amount, currency, method, paid_on, verify_code)
             VALUES (?, ?, 'USD', 'cash', CURDATE(), ?)"
        )->execute([
            $this->enrollmentId,
            number_format($months * 100, 2, '.', ''),
            mpc_verify_code(),
        ]);
    }

    /**
     * Moves the intake's start date back, so more instalments have fallen due.
     *
     * This is how a test makes a student behind on fees. The alternative —
     * deleting their payment — is refused by the append-only trigger, and
     * dropping that trigger to get around it would make every later assertion
     * in the suite pass for the wrong reason.
     */
    private function startedMonthsAgo(int $months): void
    {
        test_owner()->prepare('UPDATE intakes SET starts_on = ? WHERE id = ?')
                    ->execute([date('Y-m-d', strtotime("-{$months} months")), $this->intakeId]);
    }

    /** Sits the paper with a given answer map and returns the marked attempt. */
    private function sit(array $selected, int $quizId = 0): array
    {
        $quizId  = $quizId ?: $this->quizId;
        $attempt = mpc_quiz_open_attempt($quizId, $this->studentId, $this->enrollmentId);

        return mpc_quiz_mark_attempt((int) $attempt['id'], $selected);
    }

    /** The answer map that scores full marks. */
    private function allCorrect(): array
    {
        $out = [];
        foreach ($this->questionIds() as $qid) {
            $out[$qid] = $this->key[$qid];
        }

        return $out;
    }

    // -----------------------------------------------------------------------
    // The answer key must not leak
    // -----------------------------------------------------------------------

    /**
     * THE most important test in this file.
     *
     * mpc_quiz_paper() is what renders the paper a student is sitting. If
     * `is_correct` appears anywhere in what it returns, the answers are in the
     * page source and every grade the system has ever recorded means nothing.
     *
     * It asserts on the whole serialised structure rather than on named keys,
     * because the leak this guards against is a careless `SELECT *` — which
     * would not add the key under a name anyone predicted.
     */
    public function testThePaperNeverCarriesTheAnswerKey(): void
    {
        $paper = mpc_quiz_paper($this->quizId);

        $this->assertNotEmpty($paper, 'the fixture paper should have questions');

        $serialised = json_encode($paper);

        $this->assertStringNotContainsString('is_correct', (string) $serialised);
        $this->assertStringNotContainsString('explanation', (string) $serialised);

        foreach ($paper as $question) {
            $this->assertNotEmpty($question['options']);
            foreach ($question['options'] as $option) {
                $this->assertArrayNotHasKey('is_correct', $option);
            }
        }
    }

    /** The review screen DOES show the key — but only after submission. */
    public function testReviewShowsTheKeyOnlyForASubmittedAttempt(): void
    {
        $open = mpc_quiz_open_attempt($this->quizId, $this->studentId, $this->enrollmentId);

        $this->assertSame([], mpc_quiz_review((int) $open['id']),
            'an unsubmitted attempt must review as nothing at all');

        mpc_quiz_mark_attempt((int) $open['id'], $this->allCorrect());

        $review = mpc_quiz_review((int) $open['id']);
        $this->assertCount(3, $review);
        $this->assertStringContainsString('is_correct', (string) json_encode($review));
    }

    // -----------------------------------------------------------------------
    // Marking
    // -----------------------------------------------------------------------

    public function testAFullyCorrectPaperScoresEverything(): void
    {
        $attempt = $this->sit($this->allCorrect());

        $this->assertSame(6, (int) $attempt['score_points']);
        $this->assertSame(6, (int) $attempt['total_points']);
        $this->assertSame('100.00', $attempt['score_percent']);
        $this->assertSame(1, (int) $attempt['passed']);
    }

    public function testAnEmptyPaperScoresZeroAndDoesNotPass(): void
    {
        $attempt = $this->sit([]);

        $this->assertSame(0, (int) $attempt['score_points']);
        $this->assertSame(6, (int) $attempt['total_points']);
        $this->assertSame(0, (int) $attempt['passed']);
    }

    /**
     * Multi-select is all-or-nothing, and this is the assertion that says so.
     *
     * Two of the three correct options scores ZERO, not two-thirds. If someone
     * introduces partial credit, this test fails and they have to decide the
     * scheme deliberately rather than discover it in a student's grade.
     */
    public function testPartOfAMultiSelectAnswerScoresNothing(): void
    {
        [$single, $multi, $tf] = $this->questionIds();

        $attempt = $this->sit([
            $single => $this->key[$single],
            $multi  => [$this->key[$multi][0]],   // one of the two correct
            $tf     => $this->key[$tf],
        ]);

        // 2 for the single + 1 for the true/false, and nothing for the multi.
        $this->assertSame(3, (int) $attempt['score_points']);
    }

    /** A correct option plus a wrong one is wrong, not partly right. */
    public function testAddingAWrongOptionLosesTheMultiSelect(): void
    {
        [$single, $multi, $tf] = $this->questionIds();

        $attempt = $this->sit([
            $single => $this->key[$single],
            $multi  => array_merge($this->key[$multi], [$this->wrongOptions($multi)[0]]),
            $tf     => $this->key[$tf],
        ]);

        $this->assertSame(3, (int) $attempt['score_points']);
    }

    /**
     * An option id from a DIFFERENT question is dropped rather than credited.
     *
     * This is the hand-edited-form case. Without the intersection in
     * mpc_quiz_mark_attempt(), a student could post the id of any option marked
     * correct anywhere in the database and have it count.
     */
    public function testOptionIdsFromAnotherQuestionAreIgnored(): void
    {
        [$single, $multi, $tf] = $this->questionIds();

        $attempt = $this->sit([
            $single => $this->key[$multi],       // right answers, wrong question
            $multi  => $this->key[$multi],
            $tf     => $this->key[$tf],
        ]);

        // The single-answer question scores nothing: nothing valid was chosen.
        $this->assertSame(4, (int) $attempt['score_points']);
    }

    /** The points for a question land once, not once per selected option. */
    public function testMultiSelectPointsAreNotCountedPerOption(): void
    {
        $attempt = $this->sit($this->allCorrect());

        $stmt = test_owner()->prepare(
            'SELECT COALESCE(SUM(points_awarded), 0) FROM quiz_answers WHERE attempt_id = ?'
        );
        $stmt->execute([(int) $attempt['id']]);

        $this->assertSame(6, (int) $stmt->fetchColumn(),
            'the answer rows must sum to the attempt score, not a multiple of it');
    }

    /**
     * A question with no correct option is worth nothing to anybody.
     *
     * It is excluded from the total rather than scored zero, so one broken
     * question cannot drag an entire class below the pass mark. The publish
     * guard in admin/quiz-edit.php is meant to stop such a question ever
     * reaching students; this is the second line, for a question broken after
     * publication.
     */
    public function testAQuestionWithNoCorrectAnswerIsExcludedFromTheTotal(): void
    {
        [$single] = $this->questionIds();

        test_owner()->prepare('UPDATE quiz_options SET is_correct = 0 WHERE question_id = ?')
                    ->execute([$single]);

        $attempt = $this->sit($this->allCorrect());

        // The broken question was worth 2 of the 6 marks. Both the score and
        // the total drop by 2, so the student still scores 100%.
        $this->assertSame(4, (int) $attempt['total_points']);
        $this->assertSame(4, (int) $attempt['score_points']);
        $this->assertSame(1, (int) $attempt['passed']);
    }

    /** Nothing the browser posts becomes a score. */
    public function testThePassMarkIsAppliedFromTheQuizNotTheSubmission(): void
    {
        test_owner()->prepare('UPDATE quizzes SET pass_mark_percent = 90 WHERE id = ?')
                    ->execute([$this->quizId]);

        [$single, $multi, $tf] = $this->questionIds();

        // 3 of 6 — 50%, which passed at the default and does not pass at 90%.
        $attempt = $this->sit([$single => $this->key[$single], $tf => $this->key[$tf]]);

        $this->assertSame('50.00', $attempt['score_percent']);
        $this->assertSame(0, (int) $attempt['passed']);
    }

    /** Re-submitting an attempt neither re-marks it nor duplicates its answers. */
    public function testSubmittingTwiceIsIgnored(): void
    {
        $attempt = mpc_quiz_open_attempt($this->quizId, $this->studentId, $this->enrollmentId);
        $first   = mpc_quiz_mark_attempt((int) $attempt['id'], $this->allCorrect());
        $second  = mpc_quiz_mark_attempt((int) $attempt['id'], []);

        $this->assertSame($first['submitted_at'], $second['submitted_at']);
        $this->assertSame(6, (int) $second['score_points'],
            'a second submission must not overwrite a recorded grade with an empty one');

        $stmt = test_owner()->prepare('SELECT COUNT(*) FROM quiz_answers WHERE attempt_id = ?');
        $stmt->execute([(int) $attempt['id']]);
        $this->assertSame(4, (int) $stmt->fetchColumn(), 'answer rows were duplicated');
    }

    // -----------------------------------------------------------------------
    // Attempts
    // -----------------------------------------------------------------------

    /** Reopening an unsubmitted attempt returns the same row, not a new one. */
    public function testReloadingThePaperDoesNotSpendASecondAttempt(): void
    {
        $first  = mpc_quiz_open_attempt($this->quizId, $this->studentId, $this->enrollmentId);
        $second = mpc_quiz_open_attempt($this->quizId, $this->studentId, $this->enrollmentId);

        $this->assertSame((int) $first['id'], (int) $second['id']);
        $this->assertSame(1, mpc_quiz_attempts_used($this->quizId, $this->studentId));
    }

    /**
     * An ABANDONED attempt still counts.
     *
     * This is the rule that stops a student opening the paper, reading it,
     * closing the tab and coming back with the questions known. Counting only
     * submissions would make max_attempts mean nothing.
     */
    public function testAnAbandonedAttemptStillCountsAgainstTheLimit(): void
    {
        // Opened and never submitted. That IS the abandonment — nothing else is
        // done to the row on purpose. An earlier version of this test set
        // submitted_at to simulate abandoning, which is the opposite of
        // abandoning, and the test passed against a deliberately broken
        // implementation as a result.
        mpc_quiz_open_attempt($this->quizId, $this->studentId, $this->enrollmentId);

        $stmt = test_owner()->prepare(
            'SELECT submitted_at FROM quiz_attempts WHERE user_id = ? ORDER BY id DESC LIMIT 1'
        );
        $stmt->execute([$this->studentId]);
        $this->assertNull($stmt->fetchColumn(), 'the fixture must leave the attempt unsubmitted');

        $this->assertSame(1, mpc_quiz_attempts_used($this->quizId, $this->studentId));

        $quiz   = mpc_quiz($this->quizId);
        $access = mpc_student_quiz_access($this->studentId, $quiz);

        $this->assertFalse($access['ok'], 'the one allowed attempt has been used');
        $this->assertStringContainsString('attempt', $access['reason']);
    }

    public function testASecondAttemptIsAllowedWhenTheQuizAllowsOne(): void
    {
        test_owner()->prepare('UPDATE quizzes SET max_attempts = 2 WHERE id = ?')
                    ->execute([$this->quizId]);

        $this->sit([]);

        $quiz = mpc_quiz($this->quizId);
        $this->assertTrue(mpc_student_quiz_access($this->studentId, $quiz)['ok']);

        $second = $this->sit($this->allCorrect());
        $this->assertSame(2, (int) $second['attempt_no']);
        $this->assertSame(6, (int) $second['score_points']);
    }

    // -----------------------------------------------------------------------
    // Who may sit
    // -----------------------------------------------------------------------

    public function testAnUnpublishedQuizCannotBeSat(): void
    {
        test_owner()->prepare('UPDATE quizzes SET is_published = 0 WHERE id = ?')
                    ->execute([$this->quizId]);

        $access = mpc_student_quiz_access($this->studentId, mpc_quiz($this->quizId));

        $this->assertFalse($access['ok']);
        $this->assertStringContainsString('not open', $access['reason']);
    }

    public function testAStudentNotEnrolledOnTheCourseCannotSit(): void
    {
        $otherQuiz = $this->makeQuiz($this->otherCourseId);
        $access    = mpc_student_quiz_access($this->studentId, mpc_quiz($otherQuiz));

        $this->assertFalse($access['ok']);
        $this->assertStringContainsString('not enrolled', $access['reason']);
    }

    public function testAWithdrawnEnrolmentCannotSit(): void
    {
        test_owner()->prepare("UPDATE enrollments SET status = 'withdrawn' WHERE id = ?")
                    ->execute([$this->enrollmentId]);

        $access = mpc_student_quiz_access($this->studentId, mpc_quiz($this->quizId));

        $this->assertFalse($access['ok']);
        $this->assertStringContainsString('withdrawn', $access['reason']);
    }

    /**
     * The payment gate, which MPC asked for and which has a cost worth keeping
     * visible: an unrecorded payment closes an exam the student has paid for.
     * The refusal must therefore name the figures, so the office can act on what
     * the student reads out over the phone.
     */
    public function testAStudentBehindOnFeesCannotSit(): void
    {
        // Five months in, still only one month paid.
        $this->startedMonthsAgo(5);

        $access = mpc_student_quiz_access($this->studentId, mpc_quiz($this->quizId));

        $this->assertFalse($access['ok']);
        $this->assertStringContainsString('month', $access['reason']);
        $this->assertStringContainsString('770 51 90 98', $access['reason'],
            'a locked-out student must be given the number to ring');
    }

    /** Paid up for the month that is due: in. */
    public function testAStudentPaidToDateCanSit(): void
    {
        $this->assertTrue(mpc_student_quiz_access($this->studentId, mpc_quiz($this->quizId))['ok']);
    }

    /**
     * Paying the WHOLE course is not required, and this is the assertion that
     * pins it. Fees are monthly; comparing against the total would lock out
     * every student who is not paying six months up front, which is all of them.
     */
    public function testOneMonthIsEnoughInTheFirstMonth(): void
    {
        $paid = mpc_enrollment_is_paid_up($this->enrollmentId);

        $this->assertTrue($paid['ok']);
        $this->assertSame(1, $paid['months_paid']);
        $this->assertSame(1, $paid['months_due'],
            'a class that starts today is in its first month');
    }

    /**
     * The boundary, pinned deliberately because it is the one people argue
     * about: the second instalment falls due when the second month BEGINS, not
     * when it ends. A student who paid once and is now a month in is behind.
     *
     * This is the harsh reading, and it is the one MPC asked for. If the office
     * would rather allow a grace period, this is the test to change and
     * mpc_months_due() is the one line to change with it — not something to
     * work around in a page.
     */
    public function testOneMonthIsNotEnoughOnceTheSecondMonthBegins(): void
    {
        $this->startedMonthsAgo(1);

        $paid = mpc_enrollment_is_paid_up($this->enrollmentId);

        $this->assertFalse($paid['ok']);
        $this->assertSame(1, $paid['months_paid']);
        $this->assertSame(2, $paid['months_due']);
    }

    /** Nothing is due before the class starts. */
    public function testNothingIsDueBeforeTheIntakeStarts(): void
    {
        $this->assertSame(0, mpc_months_due(date('Y-m-d', strtotime('+2 months')), 6));
    }

    /** The months due never exceed the length of the course. */
    public function testMonthsDueIsCappedAtTheCourseLength(): void
    {
        $this->assertSame(6, mpc_months_due(date('Y-m-d', strtotime('-3 years')), 6));
    }

    /**
     * Missing office paperwork does not lock a student out.
     *
     * An enrolment with no agreed fee is an incomplete record, not evidence of
     * non-payment, and refusing the exam would punish the student for it.
     */
    public function testAnEnrolmentWithNoAgreedFeeIsNotTreatedAsUnpaid(): void
    {
        // Far enough in that an enrolment WITH a fee would be locked out.
        $this->startedMonthsAgo(5);

        test_owner()->prepare('UPDATE enrollments SET fee_agreed = NULL WHERE id = ?')
                    ->execute([$this->enrollmentId]);

        $this->assertTrue(mpc_enrollment_is_paid_up($this->enrollmentId)['ok']);
    }

    // -----------------------------------------------------------------------
    // Who may author
    // -----------------------------------------------------------------------

    private function makeInstructor(?int $intakeId = null): array
    {
        $db = test_owner();
        $db->exec("INSERT INTO users (full_name, email, role) VALUES ('Cabdi Xasan', 'cabdi@mpc.so', 'instructor')");
        $id = (int) $db->lastInsertId();

        if ($intakeId !== null) {
            $db->prepare('INSERT INTO intake_instructors (intake_id, user_id) VALUES (?, ?)')
               ->execute([$intakeId, $id]);
        }

        return ['id' => $id, 'role' => 'instructor', 'full_name' => 'Cabdi Xasan'];
    }

    public function testStaffMayAuthorForEveryCourse(): void
    {
        $staff = ['id' => 1, 'role' => 'staff'];

        $this->assertNull(mpc_quiz_author_courses($staff),
            'null means every course, and is not the same as an empty list');
        $this->assertTrue(mpc_quiz_may_author($staff, $this->courseId));
        $this->assertTrue(mpc_quiz_may_author($staff, $this->otherCourseId));
    }

    public function testAnInstructorMayAuthorOnlyForCoursesTheyTeach(): void
    {
        $instructor = $this->makeInstructor($this->intakeId);

        $this->assertTrue(mpc_quiz_may_author($instructor, $this->courseId));
        $this->assertFalse(mpc_quiz_may_author($instructor, $this->otherCourseId));
    }

    /** Assigned to nothing means allowed nothing — the safe direction. */
    public function testAnUnassignedInstructorMayAuthorNothing(): void
    {
        $instructor = $this->makeInstructor();

        $this->assertSame([], mpc_quiz_author_courses($instructor));
        $this->assertFalse(mpc_quiz_may_author($instructor, $this->courseId));
        $this->assertSame([], mpc_quiz_list_for_author($instructor));
    }

    /** A student is never an author, even if a row somehow assigns them. */
    public function testAStudentMayNeverAuthor(): void
    {
        test_owner()->prepare('INSERT INTO intake_instructors (intake_id, user_id) VALUES (?, ?)')
                    ->execute([$this->intakeId, $this->studentId]);

        $student = ['id' => $this->studentId, 'role' => 'student'];

        $this->assertSame([], mpc_quiz_author_courses($student));
        $this->assertFalse(mpc_quiz_may_author($student, $this->courseId));
    }

    public function testTheAuthorListIsScopedToTheInstructorsCourses(): void
    {
        $this->makeQuiz($this->otherCourseId);
        $instructor = $this->makeInstructor($this->intakeId);

        $titles = array_column(mpc_quiz_list_for_author($instructor), 'course_title');

        $this->assertNotEmpty($titles);
        foreach ($titles as $title) {
            $this->assertStringContainsString('Six-Month', $title);
        }
    }

    // -----------------------------------------------------------------------
    // The gradebook
    // -----------------------------------------------------------------------

    /** Students who have not sat appear, with nulls. That is the useful column. */
    public function testTheGradebookIncludesStudentsWhoHaveNotSat(): void
    {
        $db = test_owner();
        $db->exec("INSERT INTO users (full_name, role) VALUES ('Xaawo Nuur', 'student')");
        $otherId = (int) $db->lastInsertId();
        $db->prepare("INSERT INTO enrollments (user_id, intake_id, status, fee_agreed, fee_months)
                      VALUES (?, ?, 'active', 100.00, 6)")->execute([$otherId, $this->intakeId]);

        $this->sit($this->allCorrect());

        $book = mpc_quiz_gradebook($this->quizId);
        $this->assertCount(2, $book);

        $byName = array_column($book, null, 'full_name');
        $this->assertSame(6, (int) $byName['Aamina Cabdi']['score_points']);
        $this->assertNull($byName['Xaawo Nuur']['submitted_at']);
    }

    /** The best attempt is the one reported, not the most recent. */
    public function testTheGradebookReportsTheBestAttempt(): void
    {
        test_owner()->prepare('UPDATE quizzes SET max_attempts = 3 WHERE id = ?')
                    ->execute([$this->quizId]);

        $this->sit($this->allCorrect());   // 100%
        $this->sit([]);                    // 0%, and more recent

        $book = mpc_quiz_gradebook($this->quizId);

        $this->assertCount(1, $book);
        $this->assertSame('100.00', $book[0]['score_percent']);
    }

    // -----------------------------------------------------------------------
    // The grants, which are half the enforcement
    // -----------------------------------------------------------------------

    /**
     * The application cannot delete a record that a student sat an exam.
     *
     * Same argument as `payments`, one size down. If this starts passing a
     * DELETE, check tests/bootstrap.php for a grant someone widened to make
     * another test go green.
     */
    public function testTheApplicationCannotDeleteAnAttempt(): void
    {
        $attempt = $this->sit($this->allCorrect());

        $this->expectException(PDOException::class);
        mpc_db()->prepare('DELETE FROM quiz_attempts WHERE id = ?')->execute([(int) $attempt['id']]);
    }

    /** Nor can it rewrite what a student answered. */
    public function testTheApplicationCannotAlterAnAnswer(): void
    {
        $attempt = $this->sit($this->allCorrect());

        $this->expectException(PDOException::class);
        mpc_db()->prepare('UPDATE quiz_answers SET points_awarded = 99 WHERE attempt_id = ?')
                ->execute([(int) $attempt['id']]);
    }

    /** But it CAN edit a paper, which is a document and gets corrected. */
    public function testTheApplicationCanEditAQuiz(): void
    {
        mpc_db()->prepare('UPDATE quizzes SET title = ? WHERE id = ?')
                ->execute(['Week 4 test (corrected)', $this->quizId]);

        $this->assertSame('Week 4 test (corrected)', mpc_quiz($this->quizId)['title']);
    }
}
