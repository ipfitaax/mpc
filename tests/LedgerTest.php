<?php
/**
 * The money rules. If anything in this file starts failing, stop and read it
 * before changing the code — every assertion here corresponds to a decision
 * that was argued about, and several to a bug that was actually shipped.
 */

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class LedgerTest extends TestCase
{
    private int $intakeSix;
    private int $intakeYear;
    private int $staffId;

    protected function setUp(): void
    {
        test_reset();

        $db = test_owner();

        // A real staff account, because recorded_by is who took the money and
        // every payment in production has one.
        $db->exec("INSERT INTO users (full_name, email, role) VALUES ('Office Desk', 'desk@mpc.so', 'admin')");
        $this->staffId = (int) $db->lastInsertId();

        foreach ([['six-month-intensive', 'intakeSix'], ['one-year-professional', 'intakeYear']] as [$slug, $prop]) {
            $courseId = (int) $db->query("SELECT id FROM courses WHERE slug = '$slug'")->fetchColumn();
            $db->prepare("INSERT INTO intakes (course_id, name, starts_on, status)
                          VALUES (?, ?, '2027-01-10', 'open')")
               ->execute([$courseId, "Test intake $slug"]);
            $this->$prop = (int) $db->lastInsertId();
        }
    }

    /** @return array{0:int,1:int} payment id, user id */
    private function pay(int $intake, string $amount, ?int $studentId = null, string $name = 'Aamina Cabdi'): array
    {
        $id = mpc_record_payment([
            'student_id' => $studentId, 'name' => $name, 'phone' => '+252770519098',
            'intake_id' => $intake, 'amount' => $amount, 'method' => 'cash',
            'paid_on' => date('Y-m-d'), 'reference' => '', 'note' => '',
            'recorded_by' => $this->staffId,
        ]);
        $row = mpc_payment($id);

        return [$id, (int) $row['user_id']];
    }

    // -----------------------------------------------------------------------
    // Receipt codes
    // -----------------------------------------------------------------------

    public function testVerifyCodeHasTheRightShape(): void
    {
        $code = mpc_verify_code();
        $this->assertSame(12, strlen($code));
        $this->assertSame(strlen($code), strspn($code, MPC_CODE_ALPHABET));
    }

    /** No I, L, O or U — so nothing is misread off a printed slip. */
    public function testVerifyCodeNeverContainsAmbiguousLetters(): void
    {
        for ($i = 0; $i < 400; $i++) {
            $this->assertDoesNotMatchRegularExpression('/[ILOU]/', mpc_verify_code());
        }
    }

    public function testVerifyCodesDoNotRepeatInAnyReasonableRun(): void
    {
        $seen = [];
        for ($i = 0; $i < 2000; $i++) {
            $seen[mpc_verify_code()] = true;
        }
        $this->assertCount(2000, $seen);
    }

    // -----------------------------------------------------------------------
    // Recording
    // -----------------------------------------------------------------------

    public function testRecordingCreatesStudentEnrolmentAndPaymentTogether(): void
    {
        [$id] = $this->pay($this->intakeSix, '100');
        $p = mpc_payment($id);

        $this->assertSame('Aamina Cabdi', $p['full_name']);
        $this->assertSame('100.00', $p['amount']);
        $this->assertSame('USD', $p['currency']);
        $this->assertNotEmpty($p['verify_code']);
    }

    /** Fee terms are copied from the course, not read from it later. */
    public function testEnrolmentCopiesTheFeeTermsFromTheCourse(): void
    {
        [$id] = $this->pay($this->intakeSix, '100');
        $p = mpc_payment($id);
        $this->assertSame('100.00', $p['fee_agreed']);
        $this->assertSame(6, (int) $p['fee_months']);

        [$id2] = $this->pay($this->intakeYear, '50', null, 'Maxamed Cali');
        $p2 = mpc_payment($id2);
        $this->assertSame('50.00', $p2['fee_agreed']);
        $this->assertSame(12, (int) $p2['fee_months']);
    }

    public function testSecondPaymentReusesTheStudentAndTheEnrolment(): void
    {
        [, $userId] = $this->pay($this->intakeSix, '100');
        $this->pay($this->intakeSix, '100', $userId);

        $o = test_owner();
        $this->assertSame(1, (int) $o->query("SELECT COUNT(*) FROM users WHERE role='student'")->fetchColumn());
        $this->assertSame(1, (int) $o->query('SELECT COUNT(*) FROM enrollments')->fetchColumn());
        $this->assertSame(2, (int) $o->query('SELECT COUNT(*) FROM payments')->fetchColumn());
    }

    public function testZeroAndNegativeAmountsAreRefused(): void
    {
        foreach (['0', '-100', '0.00'] as $bad) {
            try {
                $this->pay($this->intakeSix, $bad);
                $this->fail("Accepted $bad as a payment");
            } catch (InvalidArgumentException $e) {
                $this->assertStringContainsString('more than zero', $e->getMessage());
            }
        }
    }

    /**
     * The atomicity test. A student is inserted BEFORE the enrolment lookup
     * fails, so a broken rollback leaves a person in the system who paid
     * nothing — and nobody would ever notice.
     */
    public function testAFailedPaymentLeavesNoOrphanStudent(): void
    {
        $o = test_owner();
        $before = (int) $o->query("SELECT COUNT(*) FROM users WHERE role='student'")->fetchColumn();

        try {
            mpc_record_payment([
                'student_id' => null, 'name' => 'Orphan Test', 'phone' => '',
                'intake_id' => 999999, 'amount' => '50', 'method' => 'cash',
                'paid_on' => date('Y-m-d'), 'reference' => '', 'note' => '',
                'recorded_by' => $this->staffId,
            ]);
            $this->fail('Accepted a payment against an intake that does not exist');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('intake does not exist', $e->getMessage());
        }

        $this->assertSame($before, (int) $o->query("SELECT COUNT(*) FROM users WHERE role='student'")->fetchColumn());
        $this->assertSame(0, (int) $o->query("SELECT COUNT(*) FROM users WHERE full_name='Orphan Test'")->fetchColumn());
        $this->assertSame(0, (int) $o->query('SELECT COUNT(*) FROM enrollments')->fetchColumn());
    }

    // -----------------------------------------------------------------------
    // Balances
    // -----------------------------------------------------------------------

    public function testBalanceIsFeeTimesMonthsMinusPaid(): void
    {
        [$id] = $this->pay($this->intakeSix, '100');
        $bal = mpc_enrollment_balance((int) mpc_payment($id)['enrollment_id']);

        $this->assertSame(600.0, $bal['total']);
        $this->assertSame(100.0, $bal['paid']);
        $this->assertSame(500.0, $bal['balance']);
        $this->assertSame(1, $bal['months_paid']);
        $this->assertSame(6, $bal['months']);
    }

    /** A part-month reads as the last WHOLE month covered. */
    public function testMonthsPaidRoundsDownNotUp(): void
    {
        [$id, $u] = $this->pay($this->intakeSix, '100');
        $this->pay($this->intakeSix, '50', $u);
        $bal = mpc_enrollment_balance((int) mpc_payment($id)['enrollment_id']);

        $this->assertSame(150.0, $bal['paid']);
        $this->assertSame(1, $bal['months_paid']);
    }

    /** The rule the whole design rests on: a balance is SUM(amount), no CASE. */
    public function testAReversalIsSubtractedByPlainArithmetic(): void
    {
        [$id, $u] = $this->pay($this->intakeSix, '100');
        [$second] = $this->pay($this->intakeSix, '100', $u);
        mpc_reverse_payment($second, 'Recorded against the wrong student', $this->staffId);

        $bal = mpc_enrollment_balance((int) mpc_payment($id)['enrollment_id']);
        $this->assertSame(100.0, $bal['paid']);
        $this->assertSame(500.0, $bal['balance']);
        $this->assertSame(1, $bal['months_paid']);
    }

    public function testUnknownEnrolmentBalanceIsZeroesNotAnError(): void
    {
        $bal = mpc_enrollment_balance(999999);
        $this->assertSame(0.0, $bal['total']);
        $this->assertSame(0, $bal['months_paid']);
    }

    // -----------------------------------------------------------------------
    // Reversals
    // -----------------------------------------------------------------------

    public function testAReversalIsANegativeRowPointingAtTheOriginal(): void
    {
        [$id] = $this->pay($this->intakeSix, '100');
        $revId = mpc_reverse_payment($id, 'Recorded against the wrong student', $this->staffId);
        $rev = mpc_payment($revId);

        $this->assertSame('-100.00', $rev['amount']);
        $this->assertSame($id, (int) $rev['reverses_payment_id']);
        $this->assertSame('Recorded against the wrong student', $rev['reversal_reason']);
        $this->assertNotSame(mpc_payment($id)['verify_code'], $rev['verify_code']);
    }

    /** Dated when the correction happened, not back-dated onto the original. */
    public function testAReversalIsDatedToday(): void
    {
        $o = test_owner();
        [$id] = $this->pay($this->intakeSix, '100');
        $o->exec('DROP TRIGGER payments_no_update');
        $o->prepare('UPDATE payments SET paid_on = ? WHERE id = ?')->execute(['2026-01-01', $id]);
        $o->exec("CREATE TRIGGER payments_no_update BEFORE UPDATE ON payments
                  FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'append-only'");

        $rev = mpc_payment(mpc_reverse_payment($id, 'Backdated original', $this->staffId));
        $this->assertSame(date('Y-m-d'), $rev['paid_on']);
    }

    public function testAReasonIsRequiredAndMustSaySomething(): void
    {
        [$id] = $this->pay($this->intakeSix, '100');
        foreach (['', '   ', 'oops'] as $bad) {
            try {
                mpc_reverse_payment($id, $bad, $this->staffId);
                $this->fail("Accepted '$bad' as a reason");
            } catch (InvalidArgumentException $e) {
                $this->assertStringContainsString('Say why', $e->getMessage());
            }
        }
    }

    public function testAPaymentCannotBeReversedTwice(): void
    {
        [$id] = $this->pay($this->intakeSix, '100');
        mpc_reverse_payment($id, 'First and only reversal', $this->staffId);

        $this->expectExceptionMessage('already been reversed');
        mpc_reverse_payment($id, 'Second attempt at it', $this->staffId);
    }

    public function testAReversalCannotItselfBeReversed(): void
    {
        [$id] = $this->pay($this->intakeSix, '100');
        $revId = mpc_reverse_payment($id, 'The original reversal', $this->staffId);

        $this->expectExceptionMessage('already a reversal');
        mpc_reverse_payment($revId, 'Trying to un-cancel it', $this->staffId);
    }

    public function testReversingSomethingThatDoesNotExistIsRefused(): void
    {
        $this->expectExceptionMessage('no payment with that number');
        mpc_reverse_payment(999999, 'Reversing a ghost', $this->staffId);
    }

    // -----------------------------------------------------------------------
    // Lookups
    // -----------------------------------------------------------------------

    public function testStudentSearchMatchesNameAndPhone(): void
    {
        $this->pay($this->intakeSix, '100');
        $this->assertCount(1, mpc_find_students('Aamina'));
        $this->assertCount(1, mpc_find_students('aamina'));
        $this->assertCount(1, mpc_find_students('770519'));
        $this->assertCount(0, mpc_find_students('Nobody'));
    }

    /** A wildcard typed into the search box is text, not a query. */
    public function testSqlWildcardsInSearchAreLiteral(): void
    {
        $this->pay($this->intakeSix, '100');
        $this->assertCount(0, mpc_find_students('%'));
        $this->assertCount(0, mpc_find_students('_'));
        $this->assertCount(0, mpc_find_students('%%%'));
    }

    public function testPaymentLookupReturnsNullForAMissingId(): void
    {
        $this->assertNull(mpc_payment(999999));
    }

    /** Somali and Arabic names must survive the round trip. utf8mb4 is why. */
    public function testNonLatinNamesRoundTripIntact(): void
    {
        $name = 'Cabdiraxmaan Sheekh Muuse — صف';
        [$id] = $this->pay($this->intakeSix, '100', null, $name);
        $this->assertSame($name, mpc_payment($id)['full_name']);
    }
}
