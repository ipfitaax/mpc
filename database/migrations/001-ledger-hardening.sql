-- 001 — make the payments table safe to hold money
--
-- Brings an existing database created from schema.sql in line with the ledger
-- design. Written as ALTERs rather than a fresh CREATE so it can be run against
-- a database that already has data in it; the local mpc_db was empty when this
-- was first applied, but the cPanel one will not be forever.
--
-- Run it with:
--     mysql -u root mpc_db < database/migrations/001-ledger-hardening.sql
--
-- Every statement here is idempotent-hostile: running it twice will error on
-- the second pass (duplicate column, duplicate trigger). That is deliberate.
-- A migration that silently does nothing the second time hides the question of
-- whether it ran at all.
--
-- WHAT THIS DOES NOT DO
-- It does not touch currency. Whether MPC records shillings as shillings or
-- converts at the counter is a question for the office, not for this file. See
-- Open Question 3 in the design doc. Nothing here forecloses either answer.


-- ---------------------------------------------------------------------------
-- 1. A student can exist without an email address.
-- ---------------------------------------------------------------------------
-- schema.sql had `email VARCHAR(190) NOT NULL` because it was designed around
-- students logging in. The first real user of this system is the office, and
-- the first real row is a walk-in paying cash who has a phone and no email.
-- NOT NULL would reject them at the counter.
--
-- The UNIQUE index stays. MariaDB permits repeated NULLs in a unique index, so
-- "no email" is not a value that collides with another "no email".
ALTER TABLE users MODIFY email VARCHAR(190) NULL;


-- ---------------------------------------------------------------------------
-- 2. Deleting an enrolment must not delete its payments.
-- ---------------------------------------------------------------------------
-- This is the one that matters most, and it is not obvious.
--
-- The append-only guarantee below is enforced with BEFORE UPDATE and BEFORE
-- DELETE triggers. MariaDB and MySQL DO NOT FIRE TRIGGERS for deletes caused
-- by a foreign key CASCADE. So with the original ON DELETE CASCADE, deleting
-- one enrolment row would remove every payment attached to it, the trigger
-- would never run, and nothing would be logged. The ledger had a trapdoor, and
-- the trapdoor was a DELETE on a different table.
--
-- RESTRICT closes it at the same layer the FK opened it. An enrolment with
-- money against it can no longer be deleted at all; correct it with a reversal.
ALTER TABLE payments DROP FOREIGN KEY fk_pay_enroll;
ALTER TABLE payments
  ADD CONSTRAINT fk_pay_enroll FOREIGN KEY (enrollment_id)
  REFERENCES enrollments (id) ON DELETE RESTRICT;


-- ---------------------------------------------------------------------------
-- 3. Corrections are new rows, and they point at what they correct.
-- ---------------------------------------------------------------------------
-- A reversal is a payment row with a NEGATIVE amount and a reason, pointing at
-- the row it cancels. Two consequences worth stating:
--
--   * Every balance anywhere is SUM(amount). One expression, no CASE, no
--     chance of the receipt page and the office total disagreeing about the
--     sign. That disagreement is exactly the dispute this system exists to end.
--
--   * UNIQUE on reverses_payment_id means a payment can be reversed once.
--     A second attempt is a database error, not a code path someone forgot.
--
-- reversal_reason is NULL for ordinary payments. The application requires it
-- for reversals; that rule is not expressible here without a CHECK, and CHECK
-- behaves differently across MariaDB and MySQL versions, so it lives in PHP
-- where it can produce a message a human reads.
ALTER TABLE payments
  ADD COLUMN reverses_payment_id BIGINT UNSIGNED NULL AFTER note,
  ADD COLUMN reversal_reason     VARCHAR(255)    NULL AFTER reverses_payment_id,
  ADD UNIQUE KEY uq_pay_reverses (reverses_payment_id),
  ADD CONSTRAINT fk_pay_reverses FOREIGN KEY (reverses_payment_id)
      REFERENCES payments (id) ON DELETE RESTRICT;


-- ---------------------------------------------------------------------------
-- 4. The code the student can check for themselves.
-- ---------------------------------------------------------------------------
-- Printed on the receipt. A student types it into a public page and sees the
-- amount and date back. That is what makes this a ledger both sides can read
-- rather than a record MPC merely asserts.
--
-- CHAR(12), generated with random_bytes over a Crockford base32 alphabet — no
-- I, L, O or U, so nothing is misread off a printed slip or over the phone.
-- Twelve characters of that alphabet is far too large a space to walk.
--
-- Nullable only so this migration can run against existing rows. New rows are
-- always given one by the application.
ALTER TABLE payments
  ADD COLUMN verify_code CHAR(12) NULL AFTER reversal_reason,
  ADD UNIQUE KEY uq_pay_verify_code (verify_code);


-- ---------------------------------------------------------------------------
-- 5. Append-only, enforced by the database.
-- ---------------------------------------------------------------------------
-- No BEGIN/END, so no DELIMITER juggling: each trigger body is one statement.
--
-- This is HALF the enforcement. The other half is a second MySQL user that the
-- application connects as, holding INSERT and SELECT on payments and no
-- UPDATE, DELETE, DROP or TRIGGER. Without that grant these triggers are one
-- `DROP TRIGGER` away from gone, and phpMyAdmin is one click away in cPanel —
-- available to exactly the person append-only exists to constrain.
--
-- AND NOTE: a mysqldump restore drops and recreates tables, and takes the
-- triggers with them unless the dump includes them. An append-only guarantee
-- that evaporates on the first real recovery is not a guarantee. bin/selftest.php
-- exists to assert these are still here.
CREATE TRIGGER payments_no_update BEFORE UPDATE ON payments
  FOR EACH ROW SIGNAL SQLSTATE '45000'
  SET MESSAGE_TEXT = 'payments is append-only: correct with a reversal row, never an UPDATE';

CREATE TRIGGER payments_no_delete BEFORE DELETE ON payments
  FOR EACH ROW SIGNAL SQLSTATE '45000'
  SET MESSAGE_TEXT = 'payments is append-only: a payment row is never deleted';
