-- Mogadishu Professional Certificate — database schema
--
-- MySQL / MariaDB, InnoDB, utf8mb4. utf8mb4 is not optional: Somali names and
-- any Arabic text break under plain utf8, and a student whose name will not
-- save is a student who does not enrol.
--
-- Read this file top to bottom; the tables are ordered so foreign keys always
-- point at something already created.
--
-- WHAT THIS COVERS
--   people        users, social logins, email verification, password resets
--   catalogue     courses, intakes (cohorts), modules, lessons, recordings
--   study         enrolments, lesson progress, attendance
--   assessment    quizzes, questions, options, attempts, answers
--   money         payments against an enrolment
--   admin         enquiries from the public site, login audit
--
-- WHAT IT DELIBERATELY DOES NOT COVER
--   Video files. See the note above `recordings`.

SET NAMES utf8mb4;
SET time_zone = '+00:00';

-- ===========================================================================
-- PEOPLE
-- ===========================================================================

-- Everyone who can log in: students, instructors, office staff.
--
-- One table rather than three. A person can be a student on one course and an
-- instructor on another, and splitting them means duplicating a human being
-- and then keeping two rows in sync forever.
CREATE TABLE users (
  id                BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  full_name         VARCHAR(160)    NOT NULL,
  email             VARCHAR(190)    NOT NULL,
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

-- Instructor detail, kept out of `users` because only a handful of rows have it
-- and the public site reads these fields on every page load.
CREATE TABLE instructor_profiles (
  user_id       BIGINT UNSIGNED NOT NULL,
  title         VARCHAR(120)    NULL,   -- "Networking, security and data-centre management"
  bio           TEXT            NULL,
  years_experience TINYINT UNSIGNED NULL,
  -- The content plan is explicit that names, titles, credentials and photos
  -- must be verified and consented to before publication. This is that gate.
  is_published  TINYINT(1)      NOT NULL DEFAULT 0,
  display_order SMALLINT        NOT NULL DEFAULT 0,

  PRIMARY KEY (user_id),
  CONSTRAINT fk_instructor_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Google / Facebook / TikTok logins.
--
-- A separate table, not columns on `users`, so one person can link several
-- providers and still be one student. The unique key is (provider,
-- provider_user_id): that pair is what the provider guarantees is stable.
--
-- NEVER match a social login to an account by email alone. Providers can
-- return an unverified email, and trusting it lets someone sign in as a
-- student whose address they merely typed. Match on provider_user_id; only
-- offer to link by email when the existing account is already verified AND
-- the provider says the email is verified too.
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

-- Email verification links.
--
-- Only the HASH of the token is stored. If the database is ever read by
-- someone who should not have it, the rows are useless: a hash cannot be put
-- back into a URL. Same reasoning as password_hash.
CREATE TABLE email_verifications (
  id         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  user_id    BIGINT UNSIGNED NOT NULL,
  token_hash CHAR(64)        NOT NULL,   -- sha256 of the token in the emailed link
  expires_at DATETIME        NOT NULL,   -- 24 hours is plenty
  used_at    DATETIME        NULL,       -- set on first use; a link works once
  created_at DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,

  PRIMARY KEY (id),
  UNIQUE KEY uq_verify_token (token_hash),
  KEY ix_verify_user (user_id),
  CONSTRAINT fk_verify_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE password_resets (
  id         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  user_id    BIGINT UNSIGNED NOT NULL,
  token_hash CHAR(64)        NOT NULL,
  expires_at DATETIME        NOT NULL,   -- 1 hour
  used_at    DATETIME        NULL,
  created_at DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,

  PRIMARY KEY (id),
  UNIQUE KEY uq_reset_token (token_hash),
  KEY ix_reset_user (user_id),
  CONSTRAINT fk_reset_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
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

  duration_weeks  SMALLINT UNSIGNED NULL,
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

-- Who teaches which intake. Many-to-many: courses are often co-taught.
CREATE TABLE intake_instructors (
  intake_id BIGINT UNSIGNED NOT NULL,
  user_id   BIGINT UNSIGNED NOT NULL,
  PRIMARY KEY (intake_id, user_id),
  KEY ix_ii_user (user_id),
  CONSTRAINT fk_ii_intake FOREIGN KEY (intake_id) REFERENCES intakes (id) ON DELETE CASCADE,
  CONSTRAINT fk_ii_user   FOREIGN KEY (user_id)   REFERENCES users (id)   ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE modules (
  id            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  course_id     BIGINT UNSIGNED NOT NULL,
  title         VARCHAR(160)    NOT NULL,
  summary       VARCHAR(400)    NULL,
  position      SMALLINT        NOT NULL DEFAULT 0,

  PRIMARY KEY (id),
  KEY ix_modules_course (course_id, position),
  CONSTRAINT fk_modules_course FOREIGN KEY (course_id) REFERENCES courses (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE lessons (
  id               BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  module_id        BIGINT UNSIGNED NOT NULL,
  title            VARCHAR(160)    NOT NULL,
  body             MEDIUMTEXT      NULL,       -- notes, instructions, links
  duration_minutes SMALLINT UNSIGNED NULL,
  position         SMALLINT        NOT NULL DEFAULT 0,

  -- One lesson per course can be free so a prospective student can see the
  -- teaching before paying. Costs nothing and answers "what is it actually
  -- like" better than any amount of marketing copy.
  is_free_preview  TINYINT(1)      NOT NULL DEFAULT 0,

  PRIMARY KEY (id),
  KEY ix_lessons_module (module_id, position),
  CONSTRAINT fk_lessons_module FOREIGN KEY (module_id) REFERENCES modules (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Class recordings.
--
-- DO NOT PUT VIDEO FILES ON THE SHARED HOST. A one-hour recording is roughly
-- 0.5-1 GB; twenty students watching it once is 10-20 GB of transfer, and
-- shared hosting bills or throttles for that. Worse, PHP streaming video ties
-- up a worker for the whole playback and will take the website down with it.
--
-- So this table stores a URL, not a file: an unlisted YouTube or Vimeo link,
-- or Google Drive. The provider handles bandwidth, seeking and mobile quality,
-- all of which are hard and none of which are MPC's business.
--
-- `visibility` is the access rule the app enforces before it hands the URL
-- out. An unlisted URL is not a secret once it is shared, so treat this as a
-- convenience, not protection.
CREATE TABLE recordings (
  id           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  intake_id    BIGINT UNSIGNED NOT NULL,      -- a recording belongs to a class, not a course
  lesson_id    BIGINT UNSIGNED NULL,          -- optional link to the lesson it covers
  title        VARCHAR(200)    NOT NULL,
  recorded_on  DATE            NULL,
  provider     ENUM('youtube','vimeo','drive','other') NOT NULL DEFAULT 'youtube',
  video_url    VARCHAR(500)    NOT NULL,
  duration_minutes SMALLINT UNSIGNED NULL,
  visibility   ENUM('enrolled','staff') NOT NULL DEFAULT 'enrolled',
  created_at   DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,

  PRIMARY KEY (id),
  KEY ix_recordings_intake (intake_id, recorded_on),
  CONSTRAINT fk_rec_intake FOREIGN KEY (intake_id) REFERENCES intakes (id) ON DELETE CASCADE,
  CONSTRAINT fk_rec_lesson FOREIGN KEY (lesson_id) REFERENCES lessons (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ===========================================================================
-- STUDY
-- ===========================================================================

-- A student on one running of a course.
--
-- UNIQUE (user_id, intake_id) stops the double-enrolment that otherwise shows
-- up as one student counted twice in every report.
CREATE TABLE enrollments (
  id            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  user_id       BIGINT UNSIGNED NOT NULL,
  intake_id     BIGINT UNSIGNED NOT NULL,
  status        ENUM('pending','active','completed','withdrawn','cancelled') NOT NULL DEFAULT 'pending',
  enrolled_at   DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
  completed_at  DATETIME        NULL,
  fee_agreed    DECIMAL(10,2)   NULL,       -- what THIS student agreed to pay
  fee_currency  CHAR(3)         NOT NULL DEFAULT 'USD',
  notes         VARCHAR(500)    NULL,

  PRIMARY KEY (id),
  UNIQUE KEY uq_enrollment (user_id, intake_id),
  KEY ix_enroll_intake (intake_id, status),
  CONSTRAINT fk_enroll_user   FOREIGN KEY (user_id)   REFERENCES users (id)   ON DELETE CASCADE,
  CONSTRAINT fk_enroll_intake FOREIGN KEY (intake_id) REFERENCES intakes (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE lesson_progress (
  user_id      BIGINT UNSIGNED NOT NULL,
  lesson_id    BIGINT UNSIGNED NOT NULL,
  completed_at DATETIME        NULL,
  updated_at   DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

  PRIMARY KEY (user_id, lesson_id),
  KEY ix_progress_lesson (lesson_id),
  CONSTRAINT fk_prog_user   FOREIGN KEY (user_id)   REFERENCES users (id)   ON DELETE CASCADE,
  CONSTRAINT fk_prog_lesson FOREIGN KEY (lesson_id) REFERENCES lessons (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Attendance, because the certificate rules in the content plan depend on it
-- ("awarded according to clearly stated attendance, assessment and completion
-- requirements"). Without this table that promise cannot be checked.
CREATE TABLE attendance (
  id            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  enrollment_id BIGINT UNSIGNED NOT NULL,
  class_date    DATE            NOT NULL,
  status        ENUM('present','absent','late','excused') NOT NULL DEFAULT 'present',
  recorded_by   BIGINT UNSIGNED NULL,
  recorded_at   DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,

  PRIMARY KEY (id),
  UNIQUE KEY uq_attendance (enrollment_id, class_date),
  CONSTRAINT fk_att_enroll FOREIGN KEY (enrollment_id) REFERENCES enrollments (id) ON DELETE CASCADE,
  CONSTRAINT fk_att_by     FOREIGN KEY (recorded_by)   REFERENCES users (id)       ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ===========================================================================
-- ASSESSMENT
-- ===========================================================================

CREATE TABLE quizzes (
  id                BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  course_id         BIGINT UNSIGNED NOT NULL,
  lesson_id         BIGINT UNSIGNED NULL,     -- NULL = a course-level exam
  title             VARCHAR(160)    NOT NULL,
  instructions      TEXT            NULL,
  pass_mark_percent TINYINT UNSIGNED NOT NULL DEFAULT 50,
  time_limit_minutes SMALLINT UNSIGNED NULL,  -- NULL = untimed
  max_attempts      TINYINT UNSIGNED NOT NULL DEFAULT 1,
  shuffle_questions TINYINT(1)      NOT NULL DEFAULT 1,
  is_published      TINYINT(1)      NOT NULL DEFAULT 0,
  created_at        DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,

  PRIMARY KEY (id),
  KEY ix_quiz_course (course_id),
  CONSTRAINT fk_quiz_course FOREIGN KEY (course_id) REFERENCES courses (id) ON DELETE CASCADE,
  CONSTRAINT fk_quiz_lesson FOREIGN KEY (lesson_id) REFERENCES lessons (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE quiz_questions (
  id        BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  quiz_id   BIGINT UNSIGNED NOT NULL,
  type      ENUM('single','multiple','truefalse','short_text') NOT NULL DEFAULT 'single',
  text      TEXT            NOT NULL,
  points    SMALLINT UNSIGNED NOT NULL DEFAULT 1,
  position  SMALLINT        NOT NULL DEFAULT 0,
  explanation TEXT          NULL,   -- shown after submission; this is where teaching happens

  PRIMARY KEY (id),
  KEY ix_qq_quiz (quiz_id, position),
  CONSTRAINT fk_qq_quiz FOREIGN KEY (quiz_id) REFERENCES quizzes (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Answer options. `is_correct` NEVER leaves the server for an unsubmitted
-- quiz: select it in the marking query, not in the query that renders the
-- paper, or the answers are in the page source.
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

CREATE TABLE quiz_attempts (
  id            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  quiz_id       BIGINT UNSIGNED NOT NULL,
  user_id       BIGINT UNSIGNED NOT NULL,
  attempt_no    TINYINT UNSIGNED NOT NULL DEFAULT 1,
  started_at    DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
  submitted_at  DATETIME        NULL,        -- NULL = still open or abandoned
  score_points  SMALLINT UNSIGNED NULL,
  total_points  SMALLINT UNSIGNED NULL,
  score_percent DECIMAL(5,2)    NULL,
  passed        TINYINT(1)      NULL,

  PRIMARY KEY (id),
  UNIQUE KEY uq_attempt (quiz_id, user_id, attempt_no),
  KEY ix_attempt_user (user_id),
  CONSTRAINT fk_att_quiz FOREIGN KEY (quiz_id) REFERENCES quizzes (id) ON DELETE CASCADE,
  CONSTRAINT fk_att_user FOREIGN KEY (user_id) REFERENCES users (id)   ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- One row per question answered. Multi-select questions produce several rows
-- for the same question, which is why the primary key is not (attempt, question).
CREATE TABLE quiz_answers (
  id             BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  attempt_id     BIGINT UNSIGNED NOT NULL,
  question_id    BIGINT UNSIGNED NOT NULL,
  option_id      BIGINT UNSIGNED NULL,      -- for choice questions
  text_answer    VARCHAR(500)    NULL,      -- for short_text
  is_correct     TINYINT(1)      NULL,
  points_awarded SMALLINT        NOT NULL DEFAULT 0,
  answered_at    DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,

  PRIMARY KEY (id),
  KEY ix_ans_attempt (attempt_id),
  KEY ix_ans_question (question_id),
  CONSTRAINT fk_ans_attempt  FOREIGN KEY (attempt_id)  REFERENCES quiz_attempts (id)  ON DELETE CASCADE,
  CONSTRAINT fk_ans_question FOREIGN KEY (question_id) REFERENCES quiz_questions (id) ON DELETE CASCADE,
  CONSTRAINT fk_ans_option   FOREIGN KEY (option_id)   REFERENCES quiz_options (id)   ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ===========================================================================
-- CERTIFICATES AND MONEY
-- ===========================================================================

CREATE TABLE certificates (
  id            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  enrollment_id BIGINT UNSIGNED NOT NULL,
  serial        VARCHAR(40)     NOT NULL,   -- printed on the certificate; verifiable
  issued_on     DATE            NOT NULL,
  issued_by     BIGINT UNSIGNED NULL,
  file_path     VARCHAR(255)    NULL,       -- generated PDF, stored outside the web root
  revoked_at    DATETIME        NULL,

  PRIMARY KEY (id),
  UNIQUE KEY uq_cert_serial (serial),
  UNIQUE KEY uq_cert_enrollment (enrollment_id),
  CONSTRAINT fk_cert_enroll FOREIGN KEY (enrollment_id) REFERENCES enrollments (id) ON DELETE CASCADE,
  CONSTRAINT fk_cert_by     FOREIGN KEY (issued_by)     REFERENCES users (id)       ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Fee payments. Recorded by staff, not collected online: this is a cash and
-- mobile-money business, and pretending otherwise would build a checkout
-- nobody uses.
CREATE TABLE payments (
  id            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  enrollment_id BIGINT UNSIGNED NOT NULL,
  amount        DECIMAL(10,2)   NOT NULL,
  currency      CHAR(3)         NOT NULL DEFAULT 'USD',
  method        ENUM('cash','evc','zaad','edahab','bank','other') NOT NULL DEFAULT 'cash',
  reference     VARCHAR(80)     NULL,       -- mobile-money transaction id
  paid_on       DATE            NOT NULL,
  recorded_by   BIGINT UNSIGNED NULL,
  note          VARCHAR(255)    NULL,
  created_at    DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,

  PRIMARY KEY (id),
  KEY ix_pay_enrollment (enrollment_id, paid_on),
  CONSTRAINT fk_pay_enroll FOREIGN KEY (enrollment_id) REFERENCES enrollments (id) ON DELETE CASCADE,
  CONSTRAINT fk_pay_by     FOREIGN KEY (recorded_by)   REFERENCES users (id)       ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ===========================================================================
-- ADMIN
-- ===========================================================================

-- Enquiries from the public site. Currently written to storage/enquiries.jsonl
-- by api/enquiry.php; this is where they belong once the database exists, so
-- the office can see who has been followed up and who has not.
CREATE TABLE enquiries (
  id            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  full_name     VARCHAR(160)    NOT NULL,
  phone         VARCHAR(32)     NOT NULL,
  email         VARCHAR(190)    NULL,       -- not required: this business runs on phones
  education     VARCHAR(60)     NULL,
  program       VARCHAR(160)    NULL,
  interest      VARCHAR(200)    NULL,
  best_time     VARCHAR(40)     NULL,
  message       TEXT            NULL,
  source        VARCHAR(40)     NOT NULL DEFAULT 'website',
  status        ENUM('new','contacted','enrolled','not_interested') NOT NULL DEFAULT 'new',
  handled_by    BIGINT UNSIGNED NULL,
  handled_at    DATETIME        NULL,
  ip            VARCHAR(45)     NULL,
  created_at    DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,

  PRIMARY KEY (id),
  KEY ix_enq_status (status, created_at),
  CONSTRAINT fk_enq_by FOREIGN KEY (handled_by) REFERENCES users (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE newsletter_subscribers (
  id            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  email         VARCHAR(190)    NOT NULL,
  subscribed_at DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
  unsubscribed_at DATETIME      NULL,

  PRIMARY KEY (id),
  UNIQUE KEY uq_subscriber_email (email)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Login attempts, successful and failed.
--
-- Two jobs: rate limiting (count recent failures for an email or IP before
-- allowing another try) and answering "was this account broken into". Keep it
-- for a few months, then delete — it is a log, not an archive.
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
