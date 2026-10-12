<?php
/**
 * The Courses and Instructors screens, and the office's move to its own address.
 *
 * Three groups, each guarding a different kind of mistake:
 *   courses      a fee change must never reach a student already enrolled
 *   instructors  a screen that hands out passwords must not reach staff or admin
 *   address      the move must never eat a form that was already open
 */

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class OfficeTest extends TestCase
{
    private const PASSWORD = 'a-long-enough-password';

    private int $adminId;

    protected function setUp(): void
    {
        test_reset();

        test_owner()->prepare(
            "INSERT INTO users (full_name, email, password_hash, role) VALUES ('Office Admin', 'boss@mpc.so', ?, 'admin')"
        )->execute([password_hash(self::PASSWORD, PASSWORD_DEFAULT)]);
        $this->adminId = (int) test_owner()->lastInsertId();
    }

    private function course(array $over = []): array
    {
        [$c, $error] = mpc_course_clean($over + [
            'slug' => 'test-web-design', 'title' => 'Web Design', 'summary' => '',
            'level' => 'beginner', 'pathway' => 'standalone',
            'duration_months' => '3', 'duration_weeks' => '', 'fee_amount' => '40',
            'display_order' => '5',
        ], true);
        $this->assertNull($error);

        return $c;
    }

    private function courseRow(int $id): array
    {
        $stmt = test_owner()->prepare('SELECT * FROM courses WHERE id = ?');
        $stmt->execute([$id]);

        return $stmt->fetch();
    }

    // -----------------------------------------------------------------------
    // Courses
    // -----------------------------------------------------------------------

    public function testACourseCanBeAddedAndChanged(): void
    {
        $id = mpc_course_create(mpc_db(), $this->course());
        $this->assertIsInt($id);

        [$c] = mpc_course_clean(['title' => 'Web Design II', 'level' => 'intermediate',
            'pathway' => 'standalone', 'duration_months' => '4', 'fee_amount' => '45.50',
            'display_order' => '1'], false);
        mpc_course_update(mpc_db(), $id, $c);

        $row = $this->courseRow($id);
        $this->assertSame('Web Design II', $row['title']);
        $this->assertSame('45.50', $row['fee_amount']);
        $this->assertSame(4, (int) $row['duration_months']);
    }

    /** The short name matches the page address; editing must not be able to move it. */
    public function testTheShortNameCannotBeChangedByAnEdit(): void
    {
        $id = mpc_course_create(mpc_db(), $this->course());

        [$c] = mpc_course_clean(['slug' => 'test-hijacked', 'title' => 'Web Design',
            'level' => 'beginner', 'pathway' => 'standalone', 'display_order' => '0'], false);
        $this->assertArrayNotHasKey('slug', $c);
        mpc_course_update(mpc_db(), $id, $c);

        $this->assertSame('test-web-design', $this->courseRow($id)['slug']);
    }

    public function testADuplicateShortNameIsExplainedNotThrown(): void
    {
        mpc_course_create(mpc_db(), $this->course());
        $again = mpc_course_create(mpc_db(), $this->course());

        $this->assertIsString($again);
        $this->assertStringContainsString('already exists', $again);
    }

    /**
     * THE rule of this screen. Enrolments copy the fee when they are made, so a
     * new price reaches new students only. If this fails, the Courses screen
     * has become a way to put a whole class into arrears with one click.
     */
    public function testRaisingAFeeDoesNotReachStudentsAlreadyEnrolled(): void
    {
        $courseId = mpc_course_create(mpc_db(), $this->course(['fee_amount' => '40']));
        test_owner()->prepare("INSERT INTO intakes (course_id, name, starts_on, status) VALUES (?, 'Jan', '2027-01-10', 'open')")
            ->execute([$courseId]);
        $intakeId = (int) test_owner()->lastInsertId();

        $paymentId = mpc_record_payment([
            'student_id' => null, 'name' => 'Aamina Cabdi', 'phone' => '', 'intake_id' => $intakeId,
            'amount' => '40', 'method' => 'cash', 'paid_on' => date('Y-m-d'),
            'reference' => '', 'note' => '', 'recorded_by' => $this->adminId,
        ]);

        [$c] = mpc_course_clean(['title' => 'Web Design', 'level' => 'beginner', 'pathway' => 'standalone',
            'duration_months' => '3', 'fee_amount' => '90', 'display_order' => '0'], false);
        mpc_course_update(mpc_db(), $courseId, $c);

        $agreed = test_owner()->query(
            "SELECT e.fee_agreed FROM enrollments e JOIN payments p ON p.enrollment_id = e.id WHERE p.id = $paymentId"
        )->fetchColumn();
        $this->assertSame('40.00', $agreed);
    }

    /** Zero months makes every student paid up the day they enrol. */
    public function testZeroMonthsAndThreeDecimalFeesAreRefused(): void
    {
        $base = ['title' => 'X', 'level' => 'beginner', 'pathway' => 'standalone', 'display_order' => '0'];

        $this->assertNotNull(mpc_course_clean($base + ['duration_months' => '0'], false)[1]);
        $this->assertNotNull(mpc_course_clean($base + ['fee_amount' => '50.005'], false)[1]);
        $this->assertNotNull(mpc_course_clean($base + ['fee_amount' => '-5'], false)[1]);
        $this->assertNotNull(mpc_course_clean($base + ['slug' => 'Web Design!'], true)[1]);
        $this->assertNull(mpc_course_clean($base + ['fee_amount' => '', 'duration_months' => ''], false)[1]);
    }

    /** Migration 005 grants UPDATE and deliberately not DELETE. */
    public function testTheApplicationCannotDeleteACourse(): void
    {
        $id = mpc_course_create(mpc_db(), $this->course());

        try {
            mpc_db()->exec("DELETE FROM courses WHERE id = $id");
            $this->fail('The application user was able to delete a course.');
        } catch (PDOException $e) {
            $this->assertStringContainsString('denied', $e->getMessage());
        }
    }

    // -----------------------------------------------------------------------
    // Instructors
    // -----------------------------------------------------------------------

    public function testAnInstructorIsCreatedAndCanSignInWithTheGeneratedPassword(): void
    {
        [$id, $password, $error] = mpc_instructor_create(mpc_db(), 'Cabdi Xasan', ' Teacher@MPC.so ', '');

        $this->assertNull($error);
        $this->assertMatchesRegularExpression('/^[2-9A-Z]{4}(-[2-9A-Z]{4}){3}$/', $password);

        $row = test_owner()->query("SELECT role, email, password_hash FROM users WHERE id = $id")->fetch();
        $this->assertSame('instructor', $row['role']);
        $this->assertSame('teacher@mpc.so', $row['email']);
        $this->assertStringNotContainsString($password, $row['password_hash']);

        $this->assertNull(mpc_attempt_login('teacher@mpc.so', $password));
        $this->assertSame('instructor', mpc_current_user()['role']);
    }

    /** Promoting a student would hand them the grade book and take away their Google sign-in. */
    public function testAStudentsAddressCannotBecomeAnInstructor(): void
    {
        test_owner()->exec("INSERT INTO users (full_name, email, role) VALUES ('Student', 'student@gmail.com', 'student')");

        [$id, , $error] = mpc_instructor_create(mpc_db(), 'Student', 'student@gmail.com', '');

        $this->assertNull($id);
        $this->assertStringContainsString('student', $error);
        $this->assertSame('student',
            test_owner()->query("SELECT role FROM users WHERE email = 'student@gmail.com'")->fetchColumn());
    }

    /**
     * The narrowing that makes a password-handing screen safe to put on the web:
     * an administrator's id matches no row, so it cannot be reset or suspended.
     */
    public function testTheScreenCannotTouchAnAdministrator(): void
    {
        $before = test_owner()->query("SELECT password_hash FROM users WHERE id = {$this->adminId}")->fetchColumn();

        $this->assertNull(mpc_instructor_reset_password(mpc_db(), $this->adminId));
        $this->assertFalse(mpc_instructor_set_status(mpc_db(), $this->adminId, 'suspended'));

        $row = test_owner()->query("SELECT password_hash, status FROM users WHERE id = {$this->adminId}")->fetch();
        $this->assertSame($before, $row['password_hash']);
        $this->assertSame('active', $row['status']);
    }

    public function testAResetReplacesTheOldPassword(): void
    {
        [$id, $old] = mpc_instructor_create(mpc_db(), 'Cabdi Xasan', 'teacher@mpc.so', '');
        $new = mpc_instructor_reset_password(mpc_db(), $id);

        $this->assertNotSame($old, $new);
        $this->assertNotNull(mpc_attempt_login('teacher@mpc.so', $old));
        $this->assertNull(mpc_attempt_login('teacher@mpc.so', $new));
    }

    /** A double click on Suspend must not report "no such instructor". */
    public function testSuspendingIsRepeatableAndLocksThemOut(): void
    {
        [$id, $password] = mpc_instructor_create(mpc_db(), 'Cabdi Xasan', 'teacher@mpc.so', '');

        $this->assertTrue(mpc_instructor_set_status(mpc_db(), $id, 'suspended'));
        $this->assertTrue(mpc_instructor_set_status(mpc_db(), $id, 'suspended'));
        $this->assertNotNull(mpc_attempt_login('teacher@mpc.so', $password));

        $this->assertTrue(mpc_instructor_set_status(mpc_db(), $id, 'active'));
        $this->assertNull(mpc_attempt_login('teacher@mpc.so', $password));
    }

    public function testStaffAreNotOfferedTheAdministratorScreens(): void
    {
        $staff = mpc_nav_for(['role' => 'staff']);
        $admin = mpc_nav_for(['role' => 'admin']);

        $this->assertArrayNotHasKey('courses.php', $staff);
        $this->assertArrayNotHasKey('instructors.php', $staff);
        $this->assertArrayHasKey('courses.php', $admin);
        $this->assertArrayHasKey('instructors.php', $admin);
    }

    // -----------------------------------------------------------------------
    // The office's own address
    // -----------------------------------------------------------------------

    private function target(string $method, string $host, string $uri): ?string
    {
        return mpc_office_redirect_target('https://office.mpc.so', [
            'REQUEST_METHOD' => $method, 'HTTP_HOST' => $host, 'REQUEST_URI' => $uri,
        ]);
    }

    public function testNothingMovesUntilAnAddressIsConfigured(): void
    {
        $this->assertNull(mpc_office_redirect_target(null, [
            'REQUEST_METHOD' => 'GET', 'HTTP_HOST' => 'mpc.so', 'REQUEST_URI' => '/office/admin/payments.php',
        ]));
    }

    public function testAPageOnTheOldAddressMovesWithItsQuery(): void
    {
        $this->assertSame('https://office.mpc.so/students.php?id=12',
            $this->target('GET', 'mpc.so', '/office/admin/students.php?id=12'));
        $this->assertSame('https://office.mpc.so/payments.php',
            $this->target('GET', 'www.mpc.so', '/admin/payments.php'));
    }

    /**
     * A payment form open in a tab when the move happened must still save.
     * Redirecting a POST loses it — as a GET, or to a host with no session.
     */
    public function testAFormAlreadyOpenIsNeverRedirected(): void
    {
        $this->assertNull($this->target('POST', 'mpc.so', '/office/admin/payments.php'));
    }

    /** Without this the redirect would loop forever on the office host itself. */
    public function testTheOfficeAddressDoesNotRedirectToItself(): void
    {
        $this->assertNull($this->target('GET', 'office.mpc.so', '/payments.php'));
        $this->assertNull($this->target('GET', 'OFFICE.MPC.SO', '/payments.php'));
    }

    public function testLoginsNextPathIsNotCarriedToAHostWhereItIsA404(): void
    {
        $this->assertSame('https://office.mpc.so/login.php',
            $this->target('GET', 'mpc.so', '/office/admin/login.php?next=%2Foffice%2Fadmin%2Fpayments.php'));
        $this->assertSame('https://office.mpc.so/login.php',
            $this->target('GET', 'mpc.so', '/office/admin/'));
    }
}
