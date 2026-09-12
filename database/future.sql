-- Mogadishu Professional Certificate — the portal that is not built yet
--
-- NOTHING CREATES THIS FILE'S TABLES. Do not run it against a live database
-- expecting the application to use them; no PHP in this repo touches anything
-- here. It is a design, kept because the reasoning in it was paid for and
-- would be rewritten worse from memory in a year.
--
-- The fourteen tables that DO exist live in `ledger.sql`. No table is defined in
-- both files. When one of these graduates — when there is code that reads and
-- writes it — move the CREATE TABLE and its comments across, do not copy them.
-- Two definitions of one table is how they drift. `social_accounts` is the
-- worked example: it left this file the day api/auth/google started writing it.
--
-- These depend on `users`, `courses`, `intakes` and `enrollments` from
-- ledger.sql, so that file comes first if this one is ever run.
--
-- WHAT IS HERE
--   people        instructor profiles, email verification, password resets
--   catalogue     modules, lessons, recordings
--   study         lesson progress, attendance
--   awards        certificates
--   admin         enquiries from the public site, newsletter subscribers
--
-- WHAT IT DELIBERATELY DOES NOT COVER
--   Video files. See the note above `recordings`.

SET NAMES utf8mb4;
SET time_zone = '+00:00';


-- ===========================================================================
-- PEOPLE
-- ===========================================================================

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

-- social_accounts GRADUATED. It now lives in ledger.sql, created and used by
-- api/auth/google. Facebook and TikTok are still only labels in its provider
-- ENUM — no code implements them — but the table itself is real, so it is not
-- described here any more.

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

-- intake_instructors GRADUATED. It now lives in ledger.sql, created and read by
-- lib/quiz.php, which uses it to answer "may this instructor touch this quiz?".
-- It left this file the day instructor scoping stopped being a comment and
-- became a check.

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

-- ALL FIVE ASSESSMENT TABLES GRADUATED: quizzes, quiz_questions, quiz_options,
-- quiz_attempts and quiz_answers now live in ledger.sql, created and written by
-- lib/quiz.php, admin/quizzes.php and quiz.php. They left this file the day
-- students could sit a paper.
--
-- Two things changed on the way across, and both are argued in ledger.sql
-- rather than repeated here:
--
--   * `quizzes.lesson_id` was dropped. It pointed at `lessons`, which is still
--     below in this file and still does not exist.
--   * `quiz_questions.type` lost its 'short_text' member. Nothing can mark a
--     free-text answer automatically, and this pass builds no marking queue to
--     send one to.
--
-- What did NOT graduate, and is not designed here either: exercises, projects
-- and file uploads. Those are a second pass. When they arrive they are their
-- own tables — a project submission is a file and a human's mark, which shares
-- nothing with a multiple-choice paper beyond the word "assessment".


-- ===========================================================================
-- AWARDS
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


-- ===========================================================================
-- ADMIN
-- ===========================================================================

-- Enquiries from the public site. Currently written to storage/enquiries.jsonl
-- by api/enquiry.php; this is where they belong once the database exists, so
-- the office can see who has been followed up and who has not.
--
-- This is the table most likely to graduate next. See TODOS.md item 1 — the
-- argument that an unworked enquiry costs a whole course fee, while a disputed
-- payment costs only the disputed amount, is worth taking seriously.
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
