<?php
/**
 * The guarantees that live in the database rather than in PHP.
 *
 * These are the assertions that would still hold if every line of application
 * code were replaced tomorrow, and they are the reason the design is worth
 * anything: append-only enforced by convention is a promise, append-only
 * enforced by the schema and the grants is a property.
 *
 * Note what is being tested and as whom. Where a test says "the app cannot",
 * it runs on the application connection with production grants. Where it needs
 * to set up a state the application is forbidden from creating, it borrows the
 * owner — which is itself the point being made.
 */

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class SchemaTest extends TestCase
{
    private int $paymentId;
    private int $enrolmentId;
    private int $staffId;

    protected function setUp(): void
    {
        test_reset();

        $o = test_owner();
        $o->exec("INSERT INTO users (full_name, email, role) VALUES ('Office Desk', 'desk@mpc.so', 'admin')");
        $this->staffId = (int) $o->lastInsertId();

        $courseId = (int) $o->query("SELECT id FROM courses WHERE slug = 'six-month-intensive'")->fetchColumn();
        $o->prepare("INSERT INTO intakes (course_id, name, starts_on, status)
                     VALUES (?, 'Test intake', '2027-01-10', 'open')")->execute([$courseId]);
        $intakeId = (int) $o->lastInsertId();

        $id = mpc_record_payment([
            'student_id' => null, 'name' => 'Aamina Cabdi', 'phone' => '+252770519098',
            'intake_id' => $intakeId, 'amount' => '100', 'method' => 'cash',
            'paid_on' => date('Y-m-d'), 'reference' => '', 'note' => '',
            'recorded_by' => $this->staffId,
        ]);

        $this->paymentId   = $id;
        $this->enrolmentId = (int) mpc_payment($id)['enrollment_id'];
    }

    // -----------------------------------------------------------------------
    // Append-only, from the application's side
    // -----------------------------------------------------------------------

    public function testTheAppCannotUpdateAPayment(): void
    {
        $this->expectException(PDOException::class);
        mpc_db()->exec('UPDATE payments SET amount = 999 WHERE id = ' . $this->paymentId);
    }

    public function testTheAppCannotDeleteAPayment(): void
    {
        $this->expectException(PDOException::class);
        mpc_db()->exec('DELETE FROM payments WHERE id = ' . $this->paymentId);
    }

    /**
     * TRUNCATE fires no trigger. This is the one the triggers do not cover and
     * only the missing DROP privilege does — and it was found by testing, after
     * a TRUNCATE silently emptied the table and reset AUTO_INCREMENT, leaving a
     * reversal pointing at a completely different payment.
     */
    public function testTheAppCannotTruncatePayments(): void
    {
        $this->expectException(PDOException::class);
        mpc_db()->exec('TRUNCATE TABLE payments');
    }

    public function testTheAppCannotDropTheTriggersThatConstrainIt(): void
    {
        $this->expectException(PDOException::class);
        mpc_db()->exec('DROP TRIGGER payments_no_update');
    }

    // -----------------------------------------------------------------------
    // Append-only, from the owner's side — the triggers, not the grants
    // -----------------------------------------------------------------------

    public function testEvenTheOwnerCannotUpdateAPayment(): void
    {
        $this->expectException(PDOException::class);
        $this->expectExceptionMessageMatches('/append-only/');
        test_owner()->exec('UPDATE payments SET amount = 999 WHERE id = ' . $this->paymentId);
    }

    public function testEvenTheOwnerCannotDeleteAPayment(): void
    {
        $this->expectException(PDOException::class);
        $this->expectExceptionMessageMatches('/append-only/');
        test_owner()->exec('DELETE FROM payments WHERE id = ' . $this->paymentId);
    }

    /**
     * The trapdoor. MariaDB does not fire triggers for deletes caused by a
     * foreign key CASCADE, so with the original CASCADE this would have wiped
     * every payment for the enrolment with nothing raised and nothing logged.
     */
    public function testDeletingAnEnrolmentWithPaymentsIsRefused(): void
    {
        $this->expectException(PDOException::class);
        test_owner()->exec('DELETE FROM enrollments WHERE id = ' . $this->enrolmentId);
    }

    public function testDeletingAStudentWithPaymentsIsRefused(): void
    {
        $userId = (int) mpc_payment($this->paymentId)['user_id'];
        $this->expectException(PDOException::class);
        test_owner()->exec('DELETE FROM users WHERE id = ' . $userId);
    }

    // -----------------------------------------------------------------------
    // Constraints
    // -----------------------------------------------------------------------

    public function testAPaymentCanBeReversedOnlyOnceEvenAtTheDatabaseLevel(): void
    {
        mpc_reverse_payment($this->paymentId, 'The first reversal', $this->staffId);

        // Bypass mpc_reverse_payment entirely: the unique key must hold on its
        // own, without the application checking first.
        $this->expectException(PDOException::class);
        test_owner()->prepare(
            "INSERT INTO payments (enrollment_id, amount, paid_on, reverses_payment_id, reversal_reason, verify_code)
             VALUES (?, -100, CURDATE(), ?, 'second', 'QQQQ2345WWWW')"
        )->execute([$this->enrolmentId, $this->paymentId]);
    }

    public function testVerifyCodesAreUnique(): void
    {
        $code = mpc_payment($this->paymentId)['verify_code'];

        $this->expectException(PDOException::class);
        test_owner()->prepare(
            'INSERT INTO payments (enrollment_id, amount, paid_on, verify_code)
             VALUES (?, 50, CURDATE(), ?)'
        )->execute([$this->enrolmentId, $code]);
    }

    /** The first real row is a walk-in paying cash, who has no email. */
    public function testAStudentCanExistWithoutAnEmailAddress(): void
    {
        $o = test_owner();
        $o->exec("INSERT INTO users (full_name, phone, role) VALUES ('No Email', '+252700000001', 'student')");
        $o->exec("INSERT INTO users (full_name, phone, role) VALUES ('Also None', '+252700000002', 'student')");

        // Two NULLs must not collide under the unique index. The payment
        // fixture in setUp also created a student with no email, so scope the
        // count to the two inserted here rather than counting the world.
        $this->assertSame(2, (int) $o->query(
            "SELECT COUNT(*) FROM users WHERE email IS NULL AND full_name IN ('No Email','Also None')"
        )->fetchColumn());
    }

    public function testCurrencyDefaultsToUsd(): void
    {
        $this->assertSame('USD', mpc_payment($this->paymentId)['currency']);
    }

    /** A rejected insert burns an auto-increment value, so ids have gaps. */
    public function testPaymentIdsAreNotGapless(): void
    {
        $o = test_owner();
        $code = mpc_payment($this->paymentId)['verify_code'];

        try {
            $o->prepare('INSERT INTO payments (enrollment_id, amount, paid_on, verify_code)
                         VALUES (?, 50, CURDATE(), ?)')->execute([$this->enrolmentId, $code]);
        } catch (PDOException $e) {
            // expected
        }

        $next = mpc_record_payment([
            'student_id' => (int) mpc_payment($this->paymentId)['user_id'], 'name' => '', 'phone' => '',
            'intake_id' => (int) mpc_payment($this->paymentId)['intake_id'], 'amount' => '50',
            'method' => 'cash', 'paid_on' => date('Y-m-d'), 'reference' => '', 'note' => '',
            'recorded_by' => $this->staffId,
        ]);

        $this->assertGreaterThan(
            $this->paymentId + 1,
            $next,
            'Ids were gapless, so receipt numbering could wrongly rely on them'
        );
    }

    // -----------------------------------------------------------------------
    // The schema file itself
    // -----------------------------------------------------------------------

    /** The suite builds its database from ledger.sql, so this passing at all
     *  means the file still loads. These check it produced what it promises. */
    public function testLedgerSqlProducesTheTriggersAndTheRightDeleteRules(): void
    {
        $o = test_owner();

        $triggers = $o->query(
            "SELECT trigger_name FROM information_schema.triggers
              WHERE trigger_schema = DATABASE() AND event_object_table = 'payments'"
        )->fetchAll(PDO::FETCH_COLUMN);
        sort($triggers);
        $this->assertSame(['payments_no_delete', 'payments_no_update'], $triggers);

        $rules = $o->query(
            "SELECT constraint_name, delete_rule FROM information_schema.referential_constraints
              WHERE constraint_schema = DATABASE() AND table_name = 'payments'"
        )->fetchAll(PDO::FETCH_KEY_PAIR);

        $this->assertSame('RESTRICT', $rules['fk_pay_enroll'], 'CASCADE here bypasses the triggers entirely');
        $this->assertSame('RESTRICT', $rules['fk_pay_reverses']);
    }

    public function testTheTwoProgrammesAreSeededWithMonthlyFees(): void
    {
        $courses = test_owner()->query(
            'SELECT slug, fee_amount, duration_months FROM courses ORDER BY slug'
        )->fetchAll();

        $this->assertCount(2, $courses);
        $bySlug = array_column($courses, null, 'slug');

        $this->assertSame('100.00', $bySlug['six-month-intensive']['fee_amount']);
        $this->assertSame(6, (int) $bySlug['six-month-intensive']['duration_months']);
        $this->assertSame('50.00', $bySlug['one-year-professional']['fee_amount']);
        $this->assertSame(12, (int) $bySlug['one-year-professional']['duration_months']);
    }
}
