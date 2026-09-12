-- Mogadishu Professional Certificate — the tables that actually exist
--
-- MySQL / MariaDB, InnoDB, utf8mb4. utf8mb4 is not optional: Somali names and
-- any Arabic text break under plain utf8, and a student whose name will not
-- save is a student who does not enrol.
--
-- Read this file top to bottom; the tables are ordered so foreign keys always
-- point at something already created.
--
-- THIS FILE IS THE DATABASE. Fourteen tables: the seven the payment ledger
-- needs, the one Google sign-in needs, and the six the quiz module needs. If it
-- is not here, it is not on the server.
--
--   users              students, instructors and office staff. One table, not three.
--   social_accounts    a Google identity linked to a user
--   courses            the thing that is taught
--   intakes            one running of a course
--   enrollments        a student on one running of a course
--   payments           money, append-only
--   intake_instructors who teaches which intake
--   quizzes            a quiz paper, hung off a course
--   quiz_questions     one question on one paper
--   quiz_options       the choices, and which of them are right
--   quiz_attempts      one sitting of one paper by one student
--   quiz_answers       what they picked
--   login_attempts     rate limiting and "was this account broken into"
--   verify_attempts    failed public receipt lookups
--
-- The rest of the portal — modules, lessons, recordings, attendance,
-- certificates, enquiries — is designed and reasoned about in
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


-- Google logins. Moved here from future.sql when api/auth/google was built;
-- the provider ENUM keeps 'facebook' and 'tiktok' because widening an ENUM
-- later rewrites the table, and the cost of carrying two unused labels is zero.
--
-- A separate table, not columns on `users`, so one person can link several
-- providers and still be one student. The unique key is (provider,
-- provider_user_id): that pair is what the provider guarantees is stable.
--
-- NEVER match a social login to an account by email alone. Providers can
-- return an unverified email, and trusting it lets someone sign in as a
-- student whose address they merely typed. Match on provider_user_id; only
-- link by email when the existing account is already verified AND the provider
-- says the email is verified too. lib/oauth.php implements exactly that, and
-- refuses the link outright for staff and admin accounts — see the comment
-- there, which is the one rule in this feature that is about privilege rather
-- than identity.
--
-- Note `users.email` is nullable, which sharpens this: a walk-in student
-- recorded at the desk has no email at all, so there is nothing to match on
-- even if you wanted to.
CREATE TABLE social_accounts (
  id               BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  user_id          BIGINT UNSIGNED NOT NULL,
  provider         ENUM('google','facebook','tiktok') NOT NULL,
  provider_user_id VARCHAR(191)    NOT NULL,
  provider_email   VARCHAR(190)    NULL,
  email_verified   TINYINT(1)      NOT NULL DEFAULT 0,
  linked_at        DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,

  PRIMARY KEY (id),
  UNIQUE KEY uq_social_provider_user (provider, provider_user_id),
  KEY ix_social_user (user_id),
  CONSTRAINT fk_social_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
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
-- ASSESSMENT
-- ===========================================================================

-- Who teaches which intake. Many-to-many: courses are often co-taught.
--
-- Graduated from future.sql when admin/quizzes.php started reading it. It is
-- here rather than left designed because it is the ONLY thing that answers
-- "may this instructor touch this quiz?" — without it, scoping an instructor
-- to their own class is a comment rather than a check.
--
-- Note what it scopes on: an INTAKE, not a course. An instructor who taught
-- the January class has no business editing the paper the March class is about
-- to sit — except that quizzes hang off the course, so they do. That is a real
-- limitation, written down here rather than discovered later: see the comment
-- on `quizzes.course_id`.
CREATE TABLE intake_instructors (
  intake_id BIGINT UNSIGNED NOT NULL,
  user_id   BIGINT UNSIGNED NOT NULL,
  PRIMARY KEY (intake_id, user_id),
  KEY ix_ii_user (user_id),
  CONSTRAINT fk_ii_intake FOREIGN KEY (intake_id) REFERENCES intakes (id) ON DELETE CASCADE,
  CONSTRAINT fk_ii_user   FOREIGN KEY (user_id)   REFERENCES users (id)   ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- A quiz paper.
--
-- `course_id`, NOT intake_id, and that is a decision with a cost. A quiz
-- belongs to the COURSE, so every intake of that course sits the same paper and
-- a question written once is not retyped for the March class. The cost is that
-- instructor scoping is coarser than it looks: an instructor who teaches any
-- intake of a course can edit every quiz on it, including papers a colleague
-- wrote for a different class. That is acceptable at MPC's size — one or two
-- instructors per course who talk to each other daily — and it is the wrong
-- answer at four times that size. When it starts to hurt, the fix is a
-- quiz_intakes table, not a second course_id.
--
-- NO lesson_id, and the design in future.sql had one. `lessons` does not exist:
-- it is still in future.sql and nothing creates it. A foreign key to a table
-- that is not there fails at CREATE, and a bare column that never points at
-- anything is a field people fill in with a number that means nothing. Add it
-- back in the same commit that graduates `lessons`, and not before.
--
-- `is_published` is the catch between "I am writing this paper" and "the class
-- can see it". A half-written quiz students can sit is worse than no quiz: it
-- produces recorded grades against questions that were never finished. Nothing
-- renders an unpublished quiz to a student — see lib/quiz.php.
CREATE TABLE quizzes (
  id                 BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  course_id          BIGINT UNSIGNED NOT NULL,
  title              VARCHAR(160)    NOT NULL,
  instructions       TEXT            NULL,
  pass_mark_percent  TINYINT UNSIGNED NOT NULL DEFAULT 50,
  time_limit_minutes SMALLINT UNSIGNED NULL,  -- NULL = untimed
  max_attempts       TINYINT UNSIGNED NOT NULL DEFAULT 1,
  shuffle_questions  TINYINT(1)      NOT NULL DEFAULT 1,
  is_published       TINYINT(1)      NOT NULL DEFAULT 0,
  created_by         BIGINT UNSIGNED NULL,
  created_at         DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,

  PRIMARY KEY (id),
  KEY ix_quiz_course (course_id, is_published),
  CONSTRAINT fk_quiz_course FOREIGN KEY (course_id)  REFERENCES courses (id) ON DELETE CASCADE,
  CONSTRAINT fk_quiz_author FOREIGN KEY (created_by) REFERENCES users (id)   ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- One question on one paper.
--
-- `short_text` from the designed ENUM is deliberately NOT carried over. This
-- pass marks every question automatically, and a free-text answer cannot be
-- marked automatically without either a fuzzy string match — which fails a
-- correct answer over a typo, in front of a student who then carries a grade
-- they did not earn — or a human, which is the marking queue this pass does not
-- build. Widening an ENUM later rewrites the table; that is a one-off cost
-- worth paying on the day there is a marker to send the answers to.
CREATE TABLE quiz_questions (
  id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  quiz_id     BIGINT UNSIGNED NOT NULL,
  type        ENUM('single','multiple','truefalse') NOT NULL DEFAULT 'single',
  text        TEXT            NOT NULL,
  points      SMALLINT UNSIGNED NOT NULL DEFAULT 1,
  position    SMALLINT        NOT NULL DEFAULT 0,
  explanation TEXT            NULL,   -- shown after submission; the teaching happens here

  PRIMARY KEY (id),
  KEY ix_qq_quiz (quiz_id, position),
  CONSTRAINT fk_qq_quiz FOREIGN KEY (quiz_id) REFERENCES quizzes (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- Answer options.
--
-- `is_correct` NEVER leaves the server for a paper a student is sitting. Select
-- it in the marking query, not in the query that renders the paper, or the
-- answer key is in the page source and the quiz measures nothing. This is not
-- theoretical: it is one careless `SELECT *` away, which is why lib/quiz.php
-- names its columns and why a test asserts on the rendered markup rather than
-- on what the function happens to return.
CREATE TABLE quiz_options (
  id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  question_id BIGINT UNSIGNED NOT NULL,
  text        VARCHAR(500)    NOT NULL,
  is_correct  TINYINT(1)      NOT NULL DEFAULT 0,
  position    SMALLINT        NOT NULL DEFAULT 0,

  PRIMARY KEY (id),
  KEY ix_qo_question (question_id, position),
  CONSTRAINT fk_qo_question FOREIGN KEY (question_id) REFERENCES quiz_questions (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- One sitting of one paper by one student.
--
-- A row is created when the student OPENS the paper, not when they submit, so
-- `submitted_at IS NULL` is a sitting in progress or one that was abandoned.
-- That ordering is what makes `max_attempts` mean anything: counting only
-- submitted rows lets a student open a paper, read every question, close the
-- tab, and come back with the questions known and the attempt counter still at
-- zero.
--
-- The score columns are NULL until submission and are written exactly once, by
-- the server, from the option rows. Nothing the browser posts contributes a
-- number to them.
--
-- `total_points` is stored rather than recomputed from the questions. A paper
-- edited after a class sat it would otherwise silently restate every past grade
-- as a fraction of the new total — a student's 8/10 becoming 8/14 months later,
-- with nothing in the record showing why.
--
-- `enrollment_id` records which enrolment the sitting was under. It is
-- ON DELETE SET NULL, not CASCADE: deleting an enrolment must not delete the
-- record that a student sat and passed an exam.
CREATE TABLE quiz_attempts (
  id            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  quiz_id       BIGINT UNSIGNED NOT NULL,
  user_id       BIGINT UNSIGNED NOT NULL,
  enrollment_id BIGINT UNSIGNED NULL,
  attempt_no    TINYINT UNSIGNED NOT NULL DEFAULT 1,
  started_at    DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
  submitted_at  DATETIME        NULL,
  score_points  SMALLINT UNSIGNED NULL,
  total_points  SMALLINT UNSIGNED NULL,
  score_percent DECIMAL(5,2)    NULL,
  passed        TINYINT(1)      NULL,

  PRIMARY KEY (id),
  UNIQUE KEY uq_attempt (quiz_id, user_id, attempt_no),
  KEY ix_attempt_user (user_id),
  KEY ix_attempt_quiz (quiz_id, submitted_at),
  CONSTRAINT fk_att_quiz  FOREIGN KEY (quiz_id)       REFERENCES quizzes (id)     ON DELETE CASCADE,
  CONSTRAINT fk_att_user  FOREIGN KEY (user_id)       REFERENCES users (id)       ON DELETE CASCADE,
  CONSTRAINT fk_att_enrol FOREIGN KEY (enrollment_id) REFERENCES enrollments (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- One row per option a student selected.
--
-- Multi-select questions produce several rows for one question, which is why
-- the primary key is not (attempt, question). A question left blank produces no
-- rows at all — absence is the record of "not answered", and it scores zero the
-- same way a wrong answer does.
--
-- `points_awarded` is SIGNED and stays that way: it is never negative today,
-- but an UNSIGNED column turns any future negative-marking scheme into a wrap
-- to 65535, which is a grade nobody can explain to the student holding it.
CREATE TABLE quiz_answers (
  id             BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  attempt_id     BIGINT UNSIGNED NOT NULL,
  question_id    BIGINT UNSIGNED NOT NULL,
  option_id      BIGINT UNSIGNED NULL,
  is_correct     TINYINT(1)      NULL,
  points_awarded SMALLINT        NOT NULL DEFAULT 0,
  answered_at    DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,

  PRIMARY KEY (id),
  KEY ix_ans_attempt (attempt_id),
  KEY ix_ans_question (question_id),
  CONSTRAINT fk_ans_attempt  FOREIGN KEY (attempt_id)  REFERENCES quiz_attempts (id)  ON DELETE CASCADE,
  CONSTRAINT fk_ans_question FOREIGN KEY (question_id) REFERENCES quiz_questions (id) ON DELETE CASCADE,
  CONSTRAINT fk_ans_option   FOREIGN KEY (option_id)   REFERENCES quiz_options (id)   ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


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
