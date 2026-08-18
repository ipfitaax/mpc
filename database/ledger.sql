-- Mogadishu Professional Certificate — the tables that actually exist
--
-- MySQL / MariaDB, InnoDB, utf8mb4. utf8mb4 is not optional: Somali names and
-- any Arabic text break under plain utf8, and a student whose name will not
-- save is a student who does not enrol.
--
-- Read this file top to bottom; the tables are ordered so foreign keys always
-- point at something already created.
--
-- THIS FILE IS THE DATABASE. Seven tables, and they are the seven the payment
-- ledger needs. If it is not here, it is not on the server.
--
--   users            students and office staff. One table, not two.
--   courses          the thing that is taught
--   intakes          one running of a course
--   enrollments      a student on one running of a course
--   payments         money, append-only
--   login_attempts   rate limiting and "was this account broken into"
--   verify_attempts  failed public receipt lookups
--
-- The rest of the portal — modules, lessons, recordings, quizzes, attendance,
-- certificates, social logins, enquiries — is designed and reasoned about in
-- `future.sql`, and nothing creates it. That file is not dead weight: the
-- thinking in it was paid for, and it is where those tables go when they are
-- built. No table is defined in both files.
--
-- FRESH INSTALL: run this file. It is the current state, hardening included.
-- EXISTING DATABASE built from the old schema.sql: do NOT run this file. Run
-- `migrations/001-ledger-hardening.sql` instead, which carries an existing
-- database to the same place without dropping anything.

SET NAMES utf8mb4;
SET time_zone = '+00:00';
-- The application sets Africa/Mogadishu on its own connection. cPanel hosts
-- typically run UTC, and Mogadishu is UTC+3, so a payment taken at 21:30 local
-- would otherwise record the previous day — a dispute manufactured by the tool
-- built to end disputes. See lib/db.php.


-- ===========================================================================
-- PEOPLE
-- ===========================================================================

-- Everyone who can log in: students, instructors, office staff.
--
-- One table rather than three. A person can be a student on one course and an
-- instructor on another, and splitting them means duplicating a human being
-- and then keeping two rows in sync forever.
--
-- `email` is NULLABLE, and that is a change from the original design. This
-- table was drawn around students logging in; the first real row is a walk-in
-- paying cash at the desk, who has a phone and no email address. NOT NULL
-- would reject them at the counter. The UNIQUE index stays — MariaDB permits
-- repeated NULLs, so "no email" does not collide with another "no email".
CREATE TABLE users (
  id                BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  full_name         VARCHAR(160)    NOT NULL,
  email             VARCHAR(190)    NULL,
  phone             VARCHAR(32)     NULL,

  -- NULL for accounts created purely through Google/Facebook/TikTok. Those
  -- users have no password and must never be told their password is "wrong" —
  -- they were never given one. The login screen checks this and points them
  -- back to the provider they signed up with.
  password_hash     VARCHAR(255)    NULL,

  -- NULL until the student clicks the link in their email. Enrolment is
  -- allowed before this; access to lessons and recordings is not.
  email_verified_at DATETIME        NULL,

  role              ENUM('student','instructor','staff','admin') NOT NULL DEFAULT 'student',
  status            ENUM('active','suspended') NOT NULL DEFAULT 'active',

  -- Set when a social login supplies one. Local uploads go in avatar_path.
  avatar_url        VARCHAR(500)    NULL,
  avatar_path       VARCHAR(255)    NULL,

  last_login_at     DATETIME        NULL,
  created_at        DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at        DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

  PRIMARY KEY (id),
  UNIQUE KEY uq_users_email (email),
  KEY ix_users_role (role)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- ===========================================================================
-- CATALOGUE
-- ===========================================================================

-- A course is the thing that is taught. It is NOT a date.
CREATE TABLE courses (
  id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  slug            VARCHAR(120)    NOT NULL,   -- "basic-computers", matches the page URL
  title           VARCHAR(160)    NOT NULL,
  summary         VARCHAR(400)    NULL,
  description     TEXT            NULL,
  level           ENUM('beginner','intermediate','advanced') NOT NULL DEFAULT 'beginner',

  -- The two pathways from the content plan, plus standalone subjects.
  pathway         ENUM('one_year','six_month','standalone') NOT NULL DEFAULT 'standalone',

  duration_weeks  SMALLINT UNSIGNED NULL,   -- what the public site advertises
  duration_months TINYINT UNSIGNED NULL,    -- what the money is counted in

  -- PER MONTH, not per course. MPC's fees are monthly: the six-month intensive
  -- is $100/month and the one-year professional is $50/month, so both land on
  -- $600 total. That coincidence is a pricing decision — never derive one
  -- program's total from the other's.
  --
  -- months is stored rather than derived from duration_weeks because 26 weeks
  -- to 6 months is a rounding argument, and nobody should have to have it
  -- while a student is waiting at the desk.
  fee_amount      DECIMAL(10,2)   NULL,       -- NULL means "ask the office"
  fee_currency    CHAR(3)         NOT NULL DEFAULT 'USD',
  hero_image      VARCHAR(255)    NULL,
  is_published    TINYINT(1)      NOT NULL DEFAULT 0,
  display_order   SMALLINT        NOT NULL DEFAULT 0,
  created_at      DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at      DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

  PRIMARY KEY (id),
  UNIQUE KEY uq_courses_slug (slug),
  KEY ix_courses_published (is_published)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- An intake is one running of a course: a start date, a class, a teacher.
--
-- This is the table people forget, and its absence is what forces "which
-- students were in the January class?" to be answered from memory. A real
-- institute runs the same course many times; enrolments belong to a RUNNING of
-- a course, not to the course itself.
CREATE TABLE intakes (
  id            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  course_id     BIGINT UNSIGNED NOT NULL,
  name          VARCHAR(120)    NOT NULL,   -- "January 2027 — morning"
  starts_on     DATE            NULL,
  ends_on       DATE            NULL,
  schedule_note VARCHAR(255)    NULL,       -- "Sat-Wed, 08:00-10:00"
  capacity      SMALLINT UNSIGNED NULL,
  status        ENUM('planned','open','running','finished','cancelled') NOT NULL DEFAULT 'planned',
  created_at    DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,

  PRIMARY KEY (id),
  KEY ix_intakes_course (course_id),
  KEY ix_intakes_status (status),
  CONSTRAINT fk_intakes_course FOREIGN KEY (course_id) REFERENCES courses (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- ===========================================================================
-- STUDY
-- ===========================================================================

-- A student on one running of a course.
--
-- UNIQUE (user_id, intake_id) stops the double-enrolment that otherwise shows
-- up as one student counted twice in every report.
--
-- fee_agreed is the agreed MONTHLY rate, and fee_months is how many months.
-- Both live here rather than being read from the course because MPC negotiates
-- individually: a student may agree a different rate or a different length, and
-- the ledger has to show what THEY agreed, not what the brochure says. Copied
-- from the course at enrolment, then left alone.
--
--   total owed = fee_agreed * fee_months
--   balance    = total owed - SUM(payments.amount)
--
-- Two columns rather than one total, so "how many months has this student
-- actually paid for" is answerable. That is the question the office is really
-- asked at the desk, more often than "what is the outstanding balance".
--
-- A single ambiguous fee column is how a balance query ends up off by a factor
-- of six and looks entirely normal while doing it.
--
-- fee_currency is always 'USD' as of 2026-08-18 — see payments.currency.
CREATE TABLE enrollments (
  id            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  user_id       BIGINT UNSIGNED NOT NULL,
  intake_id     BIGINT UNSIGNED NOT NULL,
  status        ENUM('pending','active','completed','withdrawn','cancelled') NOT NULL DEFAULT 'pending',
  enrolled_at   DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
  completed_at  DATETIME        NULL,
  fee_agreed    DECIMAL(10,2)   NULL,       -- agreed rate PER MONTH
  fee_months    TINYINT UNSIGNED NULL,      -- how many months they agreed to
  fee_currency  CHAR(3)         NOT NULL DEFAULT 'USD',
  notes         VARCHAR(500)    NULL,

  PRIMARY KEY (id),
  UNIQUE KEY uq_enrollment (user_id, intake_id),
  KEY ix_enroll_intake (intake_id, status),
  CONSTRAINT fk_enroll_user   FOREIGN KEY (user_id)   REFERENCES users (id)   ON DELETE CASCADE,
  CONSTRAINT fk_enroll_intake FOREIGN KEY (intake_id) REFERENCES intakes (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- ===========================================================================
-- MONEY
-- ===========================================================================

-- Fee payments. Recorded by staff, not collected online: this is a cash and
-- mobile-money business, and pretending otherwise would build a checkout
-- nobody uses.
--
-- APPEND-ONLY. A row here is never updated and never deleted. A correction is
-- a NEW row with a negative amount pointing at the row it cancels. Two
-- consequences worth stating plainly:
--
--   * Every balance anywhere is SUM(amount). One expression, no CASE, so the
--     receipt in the student's hand and the total on the office screen cannot
--     disagree about a sign. That disagreement is the dispute this whole
--     system exists to end.
--
--   * UNIQUE on reverses_payment_id means a payment is reversed at most once.
--     A second attempt is a database error, not a code path someone forgot.
--
-- ON DELETE RESTRICT on the enrolment, and this is not a style preference.
-- MariaDB and MySQL DO NOT FIRE TRIGGERS for deletes caused by a foreign key
-- CASCADE. With CASCADE, deleting one enrolment row would remove every payment
-- attached to it, the triggers below would never run, and nothing would be
-- logged. The append-only guarantee had a trapdoor, and the trapdoor was a
-- DELETE on a different table. Do not change this back.
--
-- Payment ids are NOT gapless. A rejected INSERT still consumes an
-- auto-increment value, so anything that numbers receipts must carry its own
-- sequence rather than assuming `id` counts without gaps.
CREATE TABLE payments (
  id                  BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  enrollment_id       BIGINT UNSIGNED NOT NULL,
  amount              DECIMAL(10,2)   NOT NULL,   -- negative == a reversal

  -- USD only, decided 2026-08-18. Every row is 'USD', so SUM(amount) is safe
  -- everywhere with no grouping and no CASE.
  --
  -- Kept as a column rather than dropped, deliberately. It costs three bytes a
  -- row, and dropping it would turn "accept shillings too" from a change of
  -- policy into a migration against live financial data. Nothing enforces USD
  -- at this layer for the same reason: a constraint would have to be removed
  -- to ever accept anything else. The application is the enforcer.
  --
  -- MPC operates where USD and SOS circulate together, so cash WILL arrive as
  -- shillings. Somebody converts at the counter, and the rate they used is not
  -- in the ledger unless it is written down — put the original amount and rate
  -- in `reference`. Without that, a dispute about a shilling payment becomes a
  -- dispute about an exchange rate nobody recorded.
  currency            CHAR(3)         NOT NULL DEFAULT 'USD',
  method              ENUM('cash','evc','zaad','edahab','bank','other') NOT NULL DEFAULT 'cash',
  reference           VARCHAR(80)     NULL,       -- mobile-money transaction id, or
                                                  -- the original SOS amount and
                                                  -- rate when cash was converted
  paid_on             DATE            NOT NULL,
  recorded_by         BIGINT UNSIGNED NULL,
  note                VARCHAR(255)    NULL,

  -- Set only on a reversal. The application requires a reason alongside it;
  -- that rule is not expressed here because CHECK is enforced on MariaDB 10.2+
  -- and silently ignored on MySQL 5.7, so relying on it would mean the
  -- constraint quietly disappearing depending on the host. It lives in PHP,
  -- where it can produce a message a human reads.
  reverses_payment_id BIGINT UNSIGNED NULL,
  reversal_reason     VARCHAR(255)    NULL,

  -- Printed on the receipt. The student types it into a public page and sees
  -- the amount and date back, which is what makes this a ledger both sides can
  -- read rather than a record MPC merely asserts. Twelve characters of
  -- Crockford base32 (no I, L, O or U, so nothing is misread off a printed
  -- slip or over the phone), generated with random_bytes.
  verify_code         CHAR(12)        NULL,

  created_at          DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,

  PRIMARY KEY (id),
  KEY ix_pay_enrollment (enrollment_id, paid_on),
  UNIQUE KEY uq_pay_reverses (reverses_payment_id),
  UNIQUE KEY uq_pay_verify_code (verify_code),
  CONSTRAINT fk_pay_enroll   FOREIGN KEY (enrollment_id)       REFERENCES enrollments (id) ON DELETE RESTRICT,
  CONSTRAINT fk_pay_by       FOREIGN KEY (recorded_by)         REFERENCES users (id)       ON DELETE SET NULL,
  CONSTRAINT fk_pay_reverses FOREIGN KEY (reverses_payment_id) REFERENCES payments (id)    ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Append-only, enforced by the database rather than by remembering.
--
-- No BEGIN/END, so no DELIMITER juggling: each body is a single statement.
--
-- THIS IS HALF THE ENFORCEMENT, and the weaker half. TRUNCATE fires no trigger
-- at all — tested, and it silently emptied the table and reset AUTO_INCREMENT,
-- after which a reversal pointed at a completely different payment than the one
-- it was written to cancel. The other half is a second database user that the
-- application connects as, holding INSERT and SELECT on this table and no
-- UPDATE, DELETE, DROP or TRIGGER. As that user TRUNCATE, DROP TRIGGER and DROP
-- TABLE are all refused before the statement runs. Without that grant these
-- triggers are one DROP TRIGGER away from gone, and phpMyAdmin is one click
-- away in cPanel — available to exactly the person append-only constrains.
--
-- AND: a mysqldump restore drops and recreates tables, taking the triggers with
-- them unless the dump includes them. An append-only guarantee that evaporates
-- on the first real recovery is not a guarantee. bin/selftest.php asserts these
-- are still here.
CREATE TRIGGER payments_no_update BEFORE UPDATE ON payments
  FOR EACH ROW SIGNAL SQLSTATE '45000'
  SET MESSAGE_TEXT = 'payments is append-only: correct with a reversal row, never an UPDATE';

CREATE TRIGGER payments_no_delete BEFORE DELETE ON payments
  FOR EACH ROW SIGNAL SQLSTATE '45000'
  SET MESSAGE_TEXT = 'payments is append-only: a payment row is never deleted';


-- ===========================================================================
-- ADMIN
-- ===========================================================================

-- Login attempts, successful and failed.
--
-- Two jobs: rate limiting (count recent failures for an email or IP before
-- allowing another try) and answering "was this account broken into". Keep it
-- for a few months, then delete — it is a log, not an archive.
--
-- Note the `ip` column is only as trustworthy as REMOTE_ADDR. Behind Cloudflare
-- or any host proxy every request shares one address, and rate limiting on it
-- would lock out the whole office at once. Confirm what the host actually sets
-- before trusting this for anything but the audit trail.
CREATE TABLE login_attempts (
  id         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  email      VARCHAR(190)    NULL,
  user_id    BIGINT UNSIGNED NULL,
  successful TINYINT(1)      NOT NULL DEFAULT 0,
  method     ENUM('password','google','facebook','tiktok') NOT NULL DEFAULT 'password',
  ip         VARCHAR(45)     NULL,
  user_agent VARCHAR(300)    NULL,
  created_at DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,

  PRIMARY KEY (id),
  KEY ix_login_email_time (email, created_at),
  KEY ix_login_ip_time (ip, created_at),
  CONSTRAINT fk_login_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- Failed receipt lookups, for the public verify page.
--
-- What protects the receipt codes is their SIZE - twelve characters of a
-- thirty-symbol alphabet is about 5.3e17 combinations - not this table. This
-- exists because the endpoint is public and on shared hosting, where a script
-- hammering it is a resource problem long before it is a disclosure one. Be
-- honest about the limit: a rate limiter that queries the database still costs
-- a query, so it slows casual probing rather than defeating a flood.
--
-- Only FAILED lookups are recorded. A student checking their own receipt over
-- and over, which is what a worried person does, must never be locked out.
--
-- The application prunes this table itself, so it needs DELETE on it and only
-- on it - safe in a way DELETE on payments is not, because this is a log and
-- not money. See lib/config.example.php.
CREATE TABLE verify_attempts (
  id         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  ip         VARCHAR(45)     NULL,
  created_at DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,

  PRIMARY KEY (id),
  KEY ix_verify_ip_time (ip, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- ===========================================================================
-- SEED
-- ===========================================================================

-- The two programs the public site advertises, with the figures MPC gave.
--
-- Seeded here rather than left to an administrator because NOTHING in the
-- application creates a course, and the payment screen cannot record anything
-- without an intake, which cannot exist without a course. A fresh database
-- without these rows is a tool that cannot be used at all.
--
-- INSERT IGNORE so re-running is safe; slug is unique.
--
-- Intakes are NOT seeded. An intake is a real date — "January 2027 — morning"
-- — and inventing one would put a class in the database that does not exist.
-- See the note in README about creating them.
INSERT IGNORE INTO courses
  (slug, title, pathway, duration_weeks, duration_months, fee_amount, fee_currency, is_published, display_order)
VALUES
  ('six-month-intensive', 'Six-Month Intensive IT Skills Program',
   'six_month', 26, 6, 100.00, 'USD', 1, 1),
  ('one-year-professional', 'One-Year Professional IT Skills Program',
   'one_year', 52, 12, 50.00, 'USD', 1, 2);
