<?php
/**
 * Google sign-in: what it accepts, and what it must refuse.
 *
 * Two halves, and they fail in different ways.
 *
 * The TOKEN half decides whether a blob of base64 is really Google saying who
 * somebody is. Every check in it — issuer, audience, expiry, nonce — is the
 * only thing standing between "signed in as this student" and "signed in as
 * anyone the caller cares to name", so each one is tested by removing it and
 * confirming the door shuts.
 *
 * The LINKING half decides which user row a Google identity becomes. Its
 * failure mode is quieter and worse: not a rejected login but the wrong
 * account, or a second duplicate account for a student the office already has,
 * or a staff account reachable by a route that was never meant to reach it.
 */

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class GoogleAuthTest extends TestCase
{
    private const CLIENT_ID = '1234.apps.googleusercontent.com';
    private const NONCE     = 'nonce-for-this-attempt';
    private const SUB       = '110000000000000000001';

    protected function setUp(): void
    {
        test_reset();
    }

    /** Builds an id_token whose payload is whatever a test needs it to be. The
     *  signature is a placeholder: nothing verifies it, which is precisely the
     *  property the header of lib/oauth.php justifies and bounds. */
    private function idToken(array $overrides = []): string
    {
        $claims = array_merge([
            'iss'            => 'https://accounts.google.com',
            'aud'            => self::CLIENT_ID,
            'sub'            => self::SUB,
            'exp'            => time() + 3600,
            'nonce'          => self::NONCE,
            'email'          => 'student@gmail.com',
            'email_verified' => true,
            'name'           => 'Amina Yusuf',
        ], $overrides);

        $b64 = static fn(array $a): string
            => rtrim(strtr(base64_encode(json_encode($a)), '+/', '-_'), '=');

        return $b64(['alg' => 'RS256']) . '.' . $b64($claims) . '.signature-not-checked';
    }

    private function read(array $overrides = [], string $nonce = self::NONCE): array
    {
        return mpc_google_read_id_token($this->idToken($overrides), self::CLIENT_ID, $nonce);
    }

    private function makeUser(
        string $email,
        string $role = 'student',
        string $status = 'active',
        bool $emailVerified = true
    ): int {
        test_owner()->prepare(
            'INSERT INTO users (full_name, email, email_verified_at, role, status)
             VALUES (?, ?, ?, ?, ?)'
        )->execute(['Existing Person', $email, $emailVerified ? date('Y-m-d H:i:s') : null, $role, $status]);

        return (int) test_owner()->lastInsertId();
    }

    // -----------------------------------------------------------------------
    // The ID token
    // -----------------------------------------------------------------------

    public function testAWellFormedTokenYieldsItsClaims(): void
    {
        $claims = $this->read();

        $this->assertSame(self::SUB, $claims['sub']);
        $this->assertSame('student@gmail.com', $claims['email']);
        $this->assertTrue($claims['email_verified']);
        $this->assertSame('Amina Yusuf', $claims['name']);
    }

    public function testATokenForAnotherApplicationIsRefused(): void
    {
        // The single most important check here. Without it, anyone who registers
        // their own Google OAuth client can hand this endpoint a genuine,
        // unexpired, correctly signed Google token for any user and be signed
        // in as them.
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('another application');
        $this->read(['aud' => '9999.apps.googleusercontent.com']);
    }

    public function testATokenFromAnotherIssuerIsRefused(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('issuer');
        $this->read(['iss' => 'https://accounts.evil.example']);
    }

    public function testTheOtherGoogleIssuerSpellingIsAccepted(): void
    {
        // Google uses both forms. Accepting only the https:// one turns a
        // working login into an intermittent failure that depends on which
        // spelling arrived.
        $this->assertSame(self::SUB, $this->read(['iss' => 'accounts.google.com'])['sub']);
    }

    public function testAnExpiredTokenIsRefused(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('expired');
        $this->read(['exp' => time() - 3600]);
    }

    public function testATokenJustInsideTheClockSkewAllowanceIsAccepted(): void
    {
        // A shared host's clock drifts. A student rejected because the server
        // is a minute fast has no way to know that is what happened.
        $this->assertSame(self::SUB, $this->read(['exp' => time() - 30])['sub']);
    }

    public function testATokenFromADifferentAttemptIsRefused(): void
    {
        // The nonce is what stops a token captured from one sign-in being
        // replayed into another browser's session.
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('nonce');
        $this->read([], 'a-different-attempts-nonce');
    }

    public function testAMissingNonceIsRefused(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('nonce');
        $this->read(['nonce' => null]);
    }

    public function testTheStringTrueCountsAsVerifiedAndTheStringFalseDoesNot(): void
    {
        // Google sends this as a boolean or as a string depending on the route.
        // A loose comparison to true would also accept the string "false",
        // which is exactly backwards and would silently trust an address nobody
        // proved they own.
        $this->assertTrue($this->read(['email_verified' => 'true'])['email_verified']);
        $this->assertFalse($this->read(['email_verified' => 'false'])['email_verified']);
        $this->assertFalse($this->read(['email_verified' => false])['email_verified']);
    }

    public function testAMalformedTokenIsRefused(): void
    {
        $this->expectException(RuntimeException::class);
        mpc_google_read_id_token('not.a.token.at.all', self::CLIENT_ID, self::NONCE);
    }

    // -----------------------------------------------------------------------
    // Becoming a user row
    // -----------------------------------------------------------------------

    public function testFirstSignInCreatesAnActiveStudent(): void
    {
        [$id, $error] = mpc_google_link_user($this->read());

        $this->assertNull($error);
        $this->assertIsInt($id);

        $row = test_owner()->query("SELECT * FROM users WHERE id = $id")->fetch();
        $this->assertSame('student', $row['role']);
        $this->assertSame('active', $row['status']);
        $this->assertSame('Amina Yusuf', $row['full_name']);
        $this->assertSame('student@gmail.com', $row['email']);
        $this->assertNotNull($row['email_verified_at']);
        $this->assertNull($row['password_hash'], 'A Google account has no password to get wrong');
    }

    public function testSigningInTwiceDoesNotCreateASecondAccount(): void
    {
        [$first] = mpc_google_link_user($this->read());
        [$second] = mpc_google_link_user($this->read());

        $this->assertSame($first, $second);
        $this->assertSame(1, (int) test_owner()->query('SELECT COUNT(*) FROM users')->fetchColumn());
        $this->assertSame(1, (int) test_owner()->query('SELECT COUNT(*) FROM social_accounts')->fetchColumn());
    }

    public function testTheSubjectIsWhatIdentifiesAReturningStudentNotTheEmail(): void
    {
        // A student who changes their Gmail address is still the same student.
        // Matching on email would either lock them out or, when someone else
        // later takes that address, hand over their account.
        [$first] = mpc_google_link_user($this->read());
        [$second] = mpc_google_link_user($this->read(['email' => 'changed@gmail.com']));

        $this->assertSame($first, $second);
    }

    public function testAVerifiedEmailLinksToTheAccountTheOfficeAlreadyHas(): void
    {
        $existing = $this->makeUser('walkin@gmail.com', 'student', 'active', true);

        [$id, $error] = mpc_google_link_user($this->read(['email' => 'walkin@gmail.com']));

        $this->assertNull($error);
        $this->assertSame($existing, $id, 'The student must not become two people');
        $this->assertSame(1, (int) test_owner()->query('SELECT COUNT(*) FROM users')->fetchColumn());
    }

    public function testAnUnverifiedExistingAccountIsNotLinkedAutomatically(): void
    {
        // That row was created by somebody TYPING an address. Nobody has proved
        // they own it, so linking here would hand the account to whoever can
        // register the address at Google.
        $this->makeUser('typed@gmail.com', 'student', 'active', false);

        [$id, $error] = mpc_google_link_user($this->read(['email' => 'typed@gmail.com']));

        $this->assertNull($id);
        $this->assertStringContainsString('not been confirmed', $error);
        $this->assertSame(0, (int) test_owner()->query('SELECT COUNT(*) FROM social_accounts')->fetchColumn());
    }

    public function testGoogleCannotBeUsedToReachAStaffAccount(): void
    {
        // admin/ writes to an append-only money ledger. Letting Google in there
        // would make the ledger's security the security of somebody's Gmail.
        $this->makeUser('desk@mpc.so', 'admin', 'active', true);

        [$id, $error] = mpc_google_link_user($this->read(['email' => 'desk@mpc.so']));

        $this->assertNull($id);
        $this->assertStringContainsString('staff account', $error);
        $this->assertSame(0, (int) test_owner()->query('SELECT COUNT(*) FROM social_accounts')->fetchColumn());
    }

    public function testTheSameRefusalAppliesToStaffWhoseRoleIsStaff(): void
    {
        $this->makeUser('teacher@mpc.so', 'staff', 'active', true);

        [$id] = mpc_google_link_user($this->read(['email' => 'teacher@mpc.so']));

        $this->assertNull($id);
    }

    public function testASuspendedStudentIsRefused(): void
    {
        $this->makeUser('gone@gmail.com', 'student', 'suspended', true);

        [$id, $error] = mpc_google_link_user($this->read(['email' => 'gone@gmail.com']));

        $this->assertNull($id);
        $this->assertStringContainsString('suspended', $error);
    }

    public function testASuspendedStudentIsRefusedOnTheirNextVisitToo(): void
    {
        // The first visit links them; suspension has to hold on the path that
        // matches an ALREADY linked account, which is a different branch.
        [$id] = mpc_google_link_user($this->read());
        test_owner()->exec("UPDATE users SET status = 'suspended' WHERE id = $id");

        [$again, $error] = mpc_google_link_user($this->read());

        $this->assertNull($again);
        $this->assertStringContainsString('suspended', $error);
    }

    public function testAnUnverifiedGoogleEmailIsNotStoredOnTheUser(): void
    {
        // users.email is UNIQUE. Writing an address nobody has proved they own
        // would take the slot and block the real owner from ever registering.
        [$id, $error] = mpc_google_link_user($this->read(['email_verified' => false]));

        $this->assertNull($error);

        $row = test_owner()->query("SELECT email, email_verified_at FROM users WHERE id = $id")->fetch();
        $this->assertNull($row['email']);
        $this->assertNull($row['email_verified_at']);

        // It is still recorded against the provider link, where it is a fact
        // about what Google said rather than a claim about who the student is.
        $link = test_owner()->query('SELECT provider_email, email_verified FROM social_accounts')->fetch();
        $this->assertSame('student@gmail.com', $link['provider_email']);
        $this->assertSame(0, (int) $link['email_verified']);
    }

    public function testAStudentWithNoEmailAtAllStillGetsAnAccount(): void
    {
        [$id, $error] = mpc_google_link_user($this->read(['email' => null, 'name' => null]));

        $this->assertNull($error);
        $this->assertIsInt($id);
    }

    // -----------------------------------------------------------------------
    // The session, and the trail it leaves
    // -----------------------------------------------------------------------

    public function testSigningInEstablishesASessionAndStampsTheLogin(): void
    {
        [$id] = mpc_google_link_user($this->read());
        mpc_establish_session($id);

        $this->assertSame($id, $_SESSION['user_id']);
        $this->assertSame($id, (int) mpc_current_user()['id']);
        $this->assertNotNull(
            test_owner()->query("SELECT last_login_at FROM users WHERE id = $id")->fetchColumn()
        );
    }

    public function testGoogleAttemptsAreLoggedAsGoogleNotAsPassword(): void
    {
        // "Was this account broken into" is answered from this table. A burst
        // of failures means something different per route, and a column that
        // records every attempt as 'password' cannot tell you which you have.
        mpc_log_login_attempt('student@gmail.com', null, false, 'google');

        $row = test_owner()->query('SELECT method, successful FROM login_attempts')->fetch();
        $this->assertSame('google', $row['method']);
        $this->assertSame(0, (int) $row['successful']);
    }

    public function testPasswordLoginsStillRecordThemselvesAsPassword(): void
    {
        // The method argument defaults, so adding it must not have silently
        // relabelled the office tool's own logins.
        mpc_log_login_attempt('desk@mpc.so', null, false);

        $this->assertSame(
            'password',
            test_owner()->query('SELECT method FROM login_attempts')->fetchColumn()
        );
    }

    // -----------------------------------------------------------------------
    // Configuration
    // -----------------------------------------------------------------------

    public function testSignInReportsItselfUnconfiguredRatherThanBreaking(): void
    {
        // The test config carries no 'google' block, which is the same state a
        // deployment is in before anyone registers an OAuth client. It has to
        // be a plain "not switched on", not a fatal error on a public page.
        $this->assertFalse(mpc_google_configured());
        $this->assertNull(mpc_google_config());
    }
}
