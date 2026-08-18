<?php
/**
 * The money rules, in one place.
 *
 * WHY THIS IS NOT INSIDE THE SCREEN
 * The payment screen records payments and the reversal screen un-records them,
 * and both need the same answer to "what does this student owe". If each
 * computes it, the receipt in the student's hand and the total on the office
 * screen will eventually disagree — which is the exact dispute this whole
 * system was built to end. One definition, used by both.
 *
 * THE ONE RULE EVERYTHING ELSE FOLLOWS
 * A balance is `SUM(amount)` and nothing else. No CASE, no sign flipping, no
 * "if reversal then subtract". A reversal is a row with a negative amount, so
 * the arithmetic is already correct before anyone writes a query. That was the
 * whole point of choosing negative amounts over a direction flag.
 */

declare(strict_types=1);

require_once __DIR__ . '/db.php';

/**
 * Crockford base32 without vowels: no I, L, O or U.
 *
 * Nothing to misread off a printed slip, nothing to mishear over the phone,
 * and no chance of an accidental word appearing on a receipt.
 */
const MPC_CODE_ALPHABET = '23456789ABCDEFGHJKMNPQRSTVWXYZ';
const MPC_CODE_LENGTH   = 12;

/** A receipt code the student can check for themselves. */
function mpc_verify_code(): string
{
    $out = '';
    for ($i = 0; $i < MPC_CODE_LENGTH; $i++) {
        // random_int, not rand: this is the only thing standing between a
        // stranger and reading out somebody's payment history.
        $out .= MPC_CODE_ALPHABET[random_int(0, strlen(MPC_CODE_ALPHABET) - 1)];
    }

    return $out;
}

/**
 * Records a payment, creating the student and the enrolment if they are new.
 *
 * ALL OF IT OR NONE OF IT. A student created without their payment is a person
 * in the system who paid nothing; a payment recorded against a half-made
 * enrolment is money attached to nothing. Both are worse than a clean failure
 * the office can retry, so the whole thing is one transaction.
 *
 * Returns the new payment id. Throws on anything that should stop the office
 * from being shown a receipt.
 *
 * @param array{student_id:?int,name:?string,phone:?string,intake_id:int,
 *              amount:string,method:string,paid_on:string,reference:?string,
 *              note:?string,recorded_by:int} $in
 */
function mpc_record_payment(array $in): int
{
    $db = mpc_db();

    // Reject a negative here as well as in the form. Negative amounts are
    // reversals, and a reversal must go through mpc_reverse_payment() so it
    // carries a reason and a reference to what it cancels. An unexplained
    // negative row in an append-only ledger cannot be corrected, only annotated.
    if ((float) $in['amount'] <= 0) {
        throw new InvalidArgumentException('A payment must be more than zero.');
    }

    $db->beginTransaction();

    try {
        // 1. The student.
        $userId = $in['student_id'] ?? null;

        if (! $userId) {
            $stmt = $db->prepare(
                "INSERT INTO users (full_name, phone, role, status)
                 VALUES (?, ?, 'student', 'active')"
            );
            $stmt->execute([$in['name'], $in['phone'] !== '' ? $in['phone'] : null]);
            $userId = (int) $db->lastInsertId();
        }

        // 2. The enrolment. One per student per intake — the unique key says so,
        //    so look before inserting rather than catching a duplicate.
        $stmt = $db->prepare('SELECT id FROM enrollments WHERE user_id = ? AND intake_id = ?');
        $stmt->execute([$userId, $in['intake_id']]);
        $enrollmentId = $stmt->fetchColumn();

        if (! $enrollmentId) {
            // Fee terms are copied from the course at enrolment and then left
            // alone. MPC negotiates individually, so what this student agreed
            // must not change later because a brochure price did.
            $stmt = $db->prepare(
                'SELECT c.fee_amount, c.duration_months
                   FROM intakes i JOIN courses c ON c.id = i.course_id
                  WHERE i.id = ?'
            );
            $stmt->execute([$in['intake_id']]);
            $course = $stmt->fetch();

            if (! $course) {
                throw new RuntimeException('That intake does not exist.');
            }

            $stmt = $db->prepare(
                "INSERT INTO enrollments (user_id, intake_id, status, fee_agreed, fee_months, fee_currency)
                 VALUES (?, ?, 'active', ?, ?, 'USD')"
            );
            $stmt->execute([
                $userId, $in['intake_id'],
                $course['fee_amount'], $course['duration_months'],
            ]);
            $enrollmentId = (int) $db->lastInsertId();
        }

        // 3. The payment, with a code the student can check.
        //
        //    Retry on a code collision rather than assuming. Twelve characters
        //    of a thirty-symbol alphabet makes one astronomically unlikely, but
        //    "astronomically unlikely" and "handled" are different states, and
        //    the unique index would otherwise surface it as a raw driver error
        //    in front of a student.
        $paymentId = null;
        for ($attempt = 0; $attempt < 5; $attempt++) {
            try {
                $stmt = $db->prepare(
                    'INSERT INTO payments
                        (enrollment_id, amount, currency, method, reference, paid_on,
                         recorded_by, note, verify_code)
                     VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)'
                );
                $stmt->execute([
                    $enrollmentId,
                    $in['amount'],
                    'USD',                       // see the note on payments.currency
                    $in['method'],
                    $in['reference'] !== '' ? $in['reference'] : null,
                    $in['paid_on'],
                    $in['recorded_by'],
                    $in['note'] !== '' ? $in['note'] : null,
                    mpc_verify_code(),
                ]);
                $paymentId = (int) $db->lastInsertId();
                break;
            } catch (PDOException $e) {
                // 23000 is an integrity violation. Only retry when it was the
                // code; anything else is a real problem and must not be
                // swallowed by a retry loop.
                if ($e->getCode() === '23000' && str_contains($e->getMessage(), 'uq_pay_verify_code')) {
                    continue;
                }
                throw $e;
            }
        }

        if ($paymentId === null) {
            throw new RuntimeException('Could not generate a unique receipt code.');
        }

        $db->commit();

        return $paymentId;
    } catch (Throwable $e) {
        // rollBack can itself throw if the connection died mid-transaction,
        // and that must not mask the original cause.
        try { $db->rollBack(); } catch (Throwable $ignored) {}
        throw $e;
    }
}

/**
 * Cancels a payment by adding its opposite.
 *
 * NOT AN UNDO. Nothing is deleted and nothing is edited — a reversal is a new
 * row with a negative amount pointing at the one it cancels. Both stay on the
 * student's history forever, which is the point: the record shows what actually
 * happened, including the mistake and the correction, rather than a tidied
 * version that only MPC can vouch for.
 *
 * WHAT IT REFUSES, and why each one matters
 *
 *   Already reversed  — the unique key on reverses_payment_id would catch this
 *                       anyway, but as a raw driver error in front of a student.
 *                       Checked first so the office gets a sentence instead.
 *   A reversal itself — reversing a cancellation to un-cancel something is the
 *                       kind of history nobody can read afterwards. If a
 *                       reversal was wrong, record the payment again.
 *   No reason         — the schema cannot enforce this (CHECK is honoured on
 *                       MariaDB and ignored on MySQL 5.7), so it is enforced
 *                       here. A negative row with no explanation is unreadable
 *                       in a year, and it can never be edited to add one.
 *
 * Returns the new reversal's payment id.
 */
function mpc_reverse_payment(int $paymentId, string $reason, int $recordedBy): int
{
    $db = mpc_db();

    $reason = trim($reason);
    if (mb_strlen($reason) < 5) {
        throw new InvalidArgumentException(
            'Say why this is being reversed. It stays on the record for this '
            . 'student permanently and cannot be edited later.'
        );
    }

    $db->beginTransaction();

    try {
        // Locked for the duration so two staff members cannot reverse the same
        // payment at once. The unique key would stop the second one regardless;
        // this makes it a clean failure rather than a race.
        $stmt = $db->prepare(
            'SELECT p.id, p.amount, p.enrollment_id, p.paid_on, p.reverses_payment_id,
                    (SELECT r.id FROM payments r WHERE r.reverses_payment_id = p.id) AS already
               FROM payments p WHERE p.id = ? FOR UPDATE'
        );
        $stmt->execute([$paymentId]);
        $original = $stmt->fetch();

        if (! $original) {
            throw new RuntimeException('There is no payment with that number.');
        }
        if ($original['reverses_payment_id'] !== null) {
            throw new RuntimeException(
                'That entry is already a reversal. To put the money back, record '
                . 'the payment again rather than reversing the cancellation.'
            );
        }
        if ($original['already'] !== null) {
            throw new RuntimeException('That payment has already been reversed.');
        }

        // Dated today, not on the original's date. The reversal is a thing that
        // happened now, and back-dating it would hide when the correction was
        // actually made.
        $reversalId = null;
        for ($attempt = 0; $attempt < 5; $attempt++) {
            try {
                $stmt = $db->prepare(
                    'INSERT INTO payments
                        (enrollment_id, amount, currency, method, paid_on, recorded_by,
                         reverses_payment_id, reversal_reason, verify_code)
                     SELECT enrollment_id, -amount, currency, method, ?, ?, id, ?, ?
                       FROM payments WHERE id = ?'
                );
                $stmt->execute([
                    date('Y-m-d'), $recordedBy, $reason, mpc_verify_code(), $paymentId,
                ]);
                $reversalId = (int) $db->lastInsertId();
                break;
            } catch (PDOException $e) {
                if ($e->getCode() === '23000' && str_contains($e->getMessage(), 'uq_pay_verify_code')) {
                    continue;
                }
                throw $e;
            }
        }

        if ($reversalId === null) {
            throw new RuntimeException('Could not generate a unique receipt code.');
        }

        $db->commit();

        return $reversalId;
    } catch (Throwable $e) {
        try { $db->rollBack(); } catch (Throwable $ignored) {}
        throw $e;
    }
}

/**
 * One payment with everything a receipt needs, or null.
 *
 * Read fresh from the database by id. The receipt page calls this AFTER the
 * redirect, so a receipt cannot be rendered for a row that did not commit —
 * which is the rule this repo was founded on, applied to money.
 */
function mpc_payment(int $id): ?array
{
    $stmt = mpc_db()->prepare(
        'SELECT p.*, u.full_name, u.phone, u.id AS user_id,
                i.name AS intake_name, i.id AS intake_id,
                c.title AS course_title,
                e.fee_agreed, e.fee_months,
                s.full_name AS recorded_by_name
           FROM payments p
           JOIN enrollments e ON e.id = p.enrollment_id
           JOIN users u       ON u.id = e.user_id
           JOIN intakes i     ON i.id = e.intake_id
           JOIN courses c     ON c.id = i.course_id
           LEFT JOIN users s  ON s.id = p.recorded_by
          WHERE p.id = ?'
    );
    $stmt->execute([$id]);

    return $stmt->fetch() ?: null;
}

/**
 * What this enrolment owes and has paid.
 *
 * `paid` is a plain SUM. Reversals are negative rows, so they are already
 * subtracted — see the note at the top of this file.
 *
 * `months_paid` is the number the office is actually asked for at the desk,
 * more often than the outstanding balance: "how many months is she paid up
 * to?" It is floor(paid / monthly), so a part-month reads as the last whole
 * month covered, which is the honest answer.
 */
function mpc_enrollment_balance(int $enrollmentId): array
{
    $stmt = mpc_db()->prepare(
        'SELECT e.fee_agreed, e.fee_months,
                COALESCE(SUM(p.amount), 0) AS paid
           FROM enrollments e
           LEFT JOIN payments p ON p.enrollment_id = e.id
          WHERE e.id = ?
          GROUP BY e.id'
    );
    $stmt->execute([$enrollmentId]);
    $row = $stmt->fetch();

    if (! $row) {
        return ['total' => 0.0, 'paid' => 0.0, 'balance' => 0.0, 'months_paid' => 0, 'months' => 0];
    }

    $monthly = (float) $row['fee_agreed'];
    $months  = (int) $row['fee_months'];
    $total   = $monthly * $months;
    $paid    = (float) $row['paid'];

    return [
        'monthly'     => $monthly,
        'months'      => $months,
        'total'       => $total,
        'paid'        => $paid,
        'balance'     => $total - $paid,
        'months_paid' => $monthly > 0 ? (int) floor($paid / $monthly) : 0,
    ];
}

/**
 * Students matching a name or phone fragment, with their enrolments.
 *
 * A plain LIKE. The roster is deliberately NOT shipped to the browser for
 * client-side filtering: at MPC's size that would be faster, but it puts every
 * student's name and phone number into the cache and back-button history of a
 * machine several staff share.
 */
function mpc_find_students(string $q): array
{
    $like = '%' . str_replace(['%', '_'], ['\%', '\_'], $q) . '%';

    $stmt = mpc_db()->prepare(
        'SELECT u.id, u.full_name, u.phone,
                GROUP_CONCAT(DISTINCT i.name ORDER BY i.starts_on DESC SEPARATOR ", ") AS intakes
           FROM users u
           LEFT JOIN enrollments e ON e.user_id = u.id
           LEFT JOIN intakes i     ON i.id = e.intake_id
          WHERE u.role = "student" AND (u.full_name LIKE ? OR u.phone LIKE ?)
          GROUP BY u.id
          ORDER BY u.full_name
          LIMIT 25'
    );
    $stmt->execute([$like, $like]);

    return $stmt->fetchAll();
}
