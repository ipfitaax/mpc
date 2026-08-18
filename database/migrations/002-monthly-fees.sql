-- 002 — fees are monthly, so say which figure is which
--
-- Answers the question 001 left open and the design doc carried as part of
-- Open Question 3: is a fee a whole-course figure, a monthly one, or the head
-- of an installment plan?
--
-- ANSWER (2026-08-18, from MPC):
--   Six-Month Intensive IT Skills Program   $100 per month  x  6  = $600
--   One-Year Professional IT Skills Program  $50 per month  x 12  = $600
--
-- Both totals land on $600. That is a pricing decision, not a coincidence to
-- rely on: do not let any query derive one program's total from the other's.
--
-- WHY THIS NEEDS A MIGRATION AT ALL
-- `enrollments.fee_agreed` was a single DECIMAL with no period attached. With
-- monthly fees that column is ambiguous — $100 could mean the month or the
-- course — and the two readings differ by a factor of six. A balance query
-- written against the wrong reading is off by $500 and looks entirely normal.
-- Ambiguity in a money column is not a style problem.
--
-- Run it with:
--     mysql -u root mpc_db < database/migrations/002-monthly-fees.sql


-- ---------------------------------------------------------------------------
-- 1. Courses carry the monthly rate and how many months it runs.
-- ---------------------------------------------------------------------------
-- duration_weeks stays — it is what the public site advertises — but months is
-- what the money is counted in, and deriving 6 from 26 weeks is a rounding
-- argument nobody should have to have while a student is waiting.
ALTER TABLE courses
  ADD COLUMN duration_months TINYINT UNSIGNED NULL AFTER duration_weeks;

-- fee_amount is now explicitly PER MONTH. The column did not change shape, so
-- nothing errors if someone keeps the old reading in their head — which is
-- exactly why it is being written down here and in ledger.sql rather than
-- left to memory.


-- ---------------------------------------------------------------------------
-- 2. Enrolments carry the agreed monthly rate and the agreed number of months.
-- ---------------------------------------------------------------------------
-- Both live on the enrolment rather than being read from the course, because
-- MPC negotiates individually: a student may agree a different rate, or a
-- different length, and the ledger has to show what THEY agreed, not what the
-- brochure says. Copied from the course at enrolment and then left alone.
--
-- Total owed  = fee_agreed * fee_months
-- Balance     = total owed - SUM(payments.amount)
--
-- Two explicit columns rather than one total, so "how many months has this
-- student actually paid for" is answerable — which is the question the office
-- is really asked at the desk, more often than "what is the outstanding
-- balance".
ALTER TABLE enrollments
  ADD COLUMN fee_months TINYINT UNSIGNED NULL AFTER fee_agreed;


-- ---------------------------------------------------------------------------
-- 3. The two real programs.
-- ---------------------------------------------------------------------------
-- Seeded because nothing in the 8-file scope creates a course, and the payment
-- screen cannot record anything without an intake to record it against. These
-- are the two programs the public site advertises, with the figures MPC gave.
--
-- INSERT IGNORE so re-running this does not duplicate them; the slug is unique.
INSERT IGNORE INTO courses
  (slug, title, pathway, duration_weeks, duration_months, fee_amount, fee_currency, is_published, display_order)
VALUES
  ('six-month-intensive', 'Six-Month Intensive IT Skills Program',
   'six_month', 26, 6, 100.00, 'USD', 1, 1),
  ('one-year-professional', 'One-Year Professional IT Skills Program',
   'one_year', 52, 12, 50.00, 'USD', 1, 2);
