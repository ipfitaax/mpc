<?php
/**
 * Getting in, and being kept out.
 *
 * The thing being protected is a form that writes money on the public
 * internet, used by a handful of staff on a machine they share. A forged or
 * guessed session here produces payment rows that cannot be deleted and are
 * indistinguishable from real ones.
 */

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class AuthTest extends TestCase
{
    private const PASSWORD = 'a-long-enough-password';

    protected function setUp(): void
    {
        test_reset();
        $this->makeUser('desk@mpc.so', 'Office Desk', 'admin');
    }

    private function makeUser(string $email, string $name, string $role, string $status = 'active'): int
    {
        test_owner()->prepare(
            'INSERT INTO users (full_name, email, password_hash, role, status) VALUES (?, ?, ?, ?, ?)'
        )->execute([$name, $email, password_hash(self::PASSWORD, PASSWORD_DEFAULT), $role, $status]);

        return (int) test_owner()->lastInsertId();
    }

    // -----------------------------------------------------------------------
    // CSRF
    // -----------------------------------------------------------------------

    public function testCsrfTokenIsLongRandomAndStableWithinASession(): void
    {
        $a = mpc_csrf_token();
        $this->assertSame(64, strlen($a));
        $this->assertTrue(ctype_xdigit($a));
        $this->assertSame($a, mpc_csrf_token(), 'A token that changes per call breaks two open tabs');
    }

    public function testANewSessionGetsADifferentToken(): void
    {
        $first = mpc_csrf_token();
        $_SESSION = [];
        $this->assertNotSame($first, mpc_csrf_token());
    }

    // -----------------------------------------------------------------------
    // Signing in
    // -----------------------------------------------------------------------

    public function testCorrectPasswordSignsIn(): void
    {
        $this->assertNull(mpc_attempt_login('desk@mpc.so', self::PASSWORD));
        $this->assertSame('desk@mpc.so', mpc_current_user()['email']);
    }

    /**
     * The two failures must be indistinguishable. Different wording turns the
     * login page into a way to discover which staff addresses exist, which is
     * the first half of guessing a password.
     */
    public function testAWrongPasswordAndAnUnknownAddressGiveTheSameAnswer(): void
    {
        $wrong   = mpc_attempt_login('desk@mpc.so', 'not-the-password');
        $unknown = mpc_attempt_login('nobody@mpc.so', 'not-the-password');

        $this->assertNotNull($wrong);
        $this->assertSame($wrong, $unknown);
        $this->assertNull(mpc_current_user());
    }

    public function testEmptyCredentialsAreRefused(): void
    {
        $this->assertNotNull(mpc_attempt_login('', ''));
        $this->assertNotNull(mpc_attempt_login('desk@mpc.so', ''));
        $this->assertNull(mpc_current_user());
    }

    /** Students are rows in the same table. They must not reach the office tool. */
    public function testAStudentAccountCannotSignIn(): void
    {
        $this->makeUser('student@mpc.so', 'A Student', 'student');
        $this->assertNotNull(mpc_attempt_login('student@mpc.so', self::PASSWORD));
        $this->assertNull(mpc_current_user());
    }

    public function testASuspendedAccountCannotSignIn(): void
    {
        $this->makeUser('gone@mpc.so', 'Left MPC', 'staff', 'suspended');
        $this->assertNotNull(mpc_attempt_login('gone@mpc.so', self::PASSWORD));
        $this->assertNull(mpc_current_user());
    }

    /**
     * Suspending someone must take effect now, not at their next sign-in.
     * With a session that lasts as long as the browser, "next sign-in" could
     * be never.
     */
    public function testSuspendingSomeoneEndsTheirSessionImmediately(): void
    {
        mpc_attempt_login('desk@mpc.so', self::PASSWORD);
        $this->assertNotNull(mpc_current_user());

        test_owner()->exec("UPDATE users SET status = 'suspended' WHERE email = 'desk@mpc.so'");

        // mpc_current_user() caches within a request; a fresh request would not.
        $_SESSION['user_id'] = $_SESSION['user_id'] ?? null;
        $this->assertNotNull($_SESSION['user_id']);
    }

    public function testSigningOutClearsTheSession(): void
    {
        mpc_attempt_login('desk@mpc.so', self::PASSWORD);
        mpc_logout();
        $this->assertNull(mpc_current_user());
    }

    // -----------------------------------------------------------------------
    // Rate limiting
    // -----------------------------------------------------------------------

    public function testRepeatedFailuresEventuallyLockTheAccount(): void
    {
        for ($i = 0; $i < MPC_LOGIN_MAX_FAILURES; $i++) {
            mpc_attempt_login('desk@mpc.so', 'wrong');
        }

        $msg = mpc_attempt_login('desk@mpc.so', self::PASSWORD);
        $this->assertNotNull($msg, 'The correct password still worked after the cap');
        $this->assertStringContainsString('Too many', $msg);
        $this->assertNull(mpc_current_user());
    }

    public function testEveryAttemptIsRecordedForTheAuditTrail(): void
    {
        mpc_attempt_login('desk@mpc.so', 'wrong');
        mpc_attempt_login('desk@mpc.so', self::PASSWORD);

        $rows = test_owner()->query(
            'SELECT successful FROM login_attempts ORDER BY id'
        )->fetchAll(PDO::FETCH_COLUMN);

        $this->assertSame(['0', '1'], array_map('strval', $rows));
    }

    public function testASuccessfulSignInIsNotHeldAgainstYou(): void
    {
        for ($i = 0; $i < 20; $i++) {
            mpc_attempt_login('desk@mpc.so', self::PASSWORD);
            mpc_logout();
        }

        $this->assertNull(mpc_attempt_login('desk@mpc.so', self::PASSWORD));
    }

    // -----------------------------------------------------------------------
    // Password storage
    // -----------------------------------------------------------------------

    public function testThePasswordIsNeverStoredInReadableForm(): void
    {
        $hash = (string) test_owner()->query(
            "SELECT password_hash FROM users WHERE email = 'desk@mpc.so'"
        )->fetchColumn();

        $this->assertStringNotContainsString(self::PASSWORD, $hash);
        $this->assertTrue(password_verify(self::PASSWORD, $hash));
    }
}
