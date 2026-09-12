-- 004 — the quiz module
--
-- Carries a database built from ledger.sql at 003 up to the fourteen-table
-- ledger.sql that includes assessment. Six new tables, no existing table
-- altered, nothing dropped.
--
-- FRESH INSTALL: do not run this. Run ledger.sql, which already contains all of
-- it. Running both is an error — the CREATE TABLEs collide.
--
-- Run it with:
--     mysql -u root mpc_db < database/migrations/004-assessment.sql
--
-- The table comments are NOT repeated here. They live in ledger.sql, which is
-- the description of the database; a migration is the diff that gets you there.
-- Two copies of an argument is how the copies stop agreeing.
--
-- THE GRANT SECTION AT THE BOTTOM IS PART OF THE MIGRATION, not an optional
-- extra. Skip it and every one of these tables is invisible to the
-- application: it connects as a restricted user whose SELECT and INSERT were
-- granted on the tables that existed at the time, and a GRANT does not reach
-- forward to tables created later.


SET NAMES utf8mb4;
SET time_zone = '+00:00';


-- ---------------------------------------------------------------------------
-- 0. FIRST, CHECK WHETHER THESE TABLES ALREADY EXIST IN THE OLD SHAPE.
-- ---------------------------------------------------------------------------
--
-- This is not hypothetical: it was true of the machine this migration was
-- written on. `future.sql` carries a warning that nothing creates its tables,
-- but the file is runnable, and running it once — to look at the design, or to
-- try something — leaves a database holding `quizzes`, `quiz_questions`,
-- `quiz_options`, `quiz_attempts`, `quiz_answers` and `intake_instructors` in
-- the shape future.sql described rather than the shape the code now expects.
--
-- That state is WORSE than not having the tables at all, and it fails late.
-- The CREATE statements below stop with "table already exists", which is loud
-- and fine. But if someone skips them because "the tables are already there",
-- the application then half-works: the quiz screens load and list papers, and
-- creating one dies with `Unknown column 'created_by'` — a fatal error on a
-- POST, which is the least diagnosable place for one.
--
-- The two shapes differ in exactly the ways ledger.sql argues for:
--   quizzes         old has lesson_id, lacks created_by
--   quiz_questions  old ENUM includes 'short_text'
--   quiz_answers    old has text_answer, and SET NULL on the option FK
--
-- CHECK before running anything:
--
--     SELECT TABLE_NAME FROM information_schema.TABLES
--      WHERE TABLE_SCHEMA = DATABASE()
--        AND TABLE_NAME IN ('quizzes','quiz_questions','quiz_options',
--                           'quiz_attempts','quiz_answers','intake_instructors');
--
-- Nothing listed: skip this section, run section 1 below.
--
-- Anything listed: these tables were created by future.sql and NOTHING has ever
-- written to them, so they should be empty. Confirm that yourself rather than
-- taking this file's word for it — a colleague may have been experimenting:
--
--     SELECT (SELECT COUNT(*) FROM quizzes)        AS quizzes,
--            (SELECT COUNT(*) FROM quiz_questions) AS questions,
--            (SELECT COUNT(*) FROM quiz_options)   AS options,
--            (SELECT COUNT(*) FROM quiz_attempts)  AS attempts,
--            (SELECT COUNT(*) FROM quiz_answers)   AS answers,
--            (SELECT COUNT(*) FROM intake_instructors) AS teaching;
--
-- All zero: drop them and carry on with section 1. Children first, because the
-- foreign keys refuse it in any other order.
--
--     DROP TABLE quiz_answers, quiz_attempts, quiz_options, quiz_questions,
--                quizzes, intake_instructors;
--
-- NOT all zero: stop. Somebody has data you did not know about, and this file
-- is not the place to decide what happens to it.
--
-- Note the other future.sql tables — modules, lessons, recordings, attendance
-- and the rest — are left exactly where they are. They are unused either way,
-- nothing here references them any more (quizzes dropped its lesson_id for
-- precisely that reason), and dropping tables that are not in your way is how a
-- migration becomes something people are afraid to run.


-- ---------------------------------------------------------------------------
-- 1. The tables.
-- ---------------------------------------------------------------------------

CREATE TABLE intake_instructors (
  intake_id BIGINT UNSIGNED NOT NULL,
  user_id   BIGINT UNSIGNED NOT NULL,
  PRIMARY KEY (intake_id, user_id),
  KEY ix_ii_user (user_id),
  CONSTRAINT fk_ii_intake FOREIGN KEY (intake_id) REFERENCES intakes (id) ON DELETE CASCADE,
  CONSTRAINT fk_ii_user   FOREIGN KEY (user_id)   REFERENCES users (id)   ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE quizzes (
  id                 BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  course_id          BIGINT UNSIGNED NOT NULL,
  title              VARCHAR(160)    NOT NULL,
  instructions       TEXT            NULL,
  pass_mark_percent  TINYINT UNSIGNED NOT NULL DEFAULT 50,
  time_limit_minutes SMALLINT UNSIGNED NULL,
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

CREATE TABLE quiz_questions (
  id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  quiz_id     BIGINT UNSIGNED NOT NULL,
  type        ENUM('single','multiple','truefalse') NOT NULL DEFAULT 'single',
  text        TEXT            NOT NULL,
  points      SMALLINT UNSIGNED NOT NULL DEFAULT 1,
  position    SMALLINT        NOT NULL DEFAULT 0,
  explanation TEXT            NULL,

  PRIMARY KEY (id),
  KEY ix_qq_quiz (quiz_id, position),
  CONSTRAINT fk_qq_quiz FOREIGN KEY (quiz_id) REFERENCES quizzes (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

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


-- ---------------------------------------------------------------------------
-- 2. Grants. Run these as the owner, and edit the user and database names.
-- ---------------------------------------------------------------------------
--
-- The application already holds SELECT and INSERT on `mpc_db.*`, which covers
-- these six tables the moment they exist. What follows is only the writes that
-- go beyond appending, granted one table at a time.
--
-- WHAT GETS UPDATE, AND WHY
--   quizzes, quiz_questions, quiz_options  — a paper is edited. Wording is
--       fixed, a wrong option is corrected, a draft is published. These are
--       documents, and a document that cannot be corrected gets replaced by a
--       second copy with the fix, which is worse.
--   quiz_attempts — the score columns are written once, at submission, by an
--       UPDATE on the row that was created when the student opened the paper.
--
-- WHAT DELIBERATELY GETS NEITHER
--   quiz_attempts DELETE and quiz_answers UPDATE/DELETE are withheld, and this
--   is the same argument as `payments`, one size down. An attempt row IS the
--   record that a named student sat a paper and scored what they scored. If the
--   application can delete it, then "the system has no record of her exam" is a
--   thing that can happen through a bug or a stray click, and there is nothing
--   left to show it ever happened. A grade that needs correcting is corrected
--   by letting the student sit another attempt, which leaves both sittings
--   visible — the same shape as a reversal row in the ledger.
--
--   This is deliberately weaker than payments: there is no trigger, so the
--   OWNER account can still delete an attempt, and a mistaken grade is a
--   smaller emergency than a mistaken payment. The grant is what stops the
--   application doing it by accident, which is the failure that actually
--   happens.
--
--   intake_instructors gets DELETE because unassigning an instructor from a
--   class is a normal act, and there is nothing in the row to preserve — it is
--   two foreign keys and no history.
--
-- GRANT ... ON <db>.<table> requires the table to exist, so this section runs
-- AFTER the CREATE TABLEs above and not before.

-- GRANT UPDATE, DELETE ON mpc_db.quizzes            TO 'mpc_app'@'localhost';
-- GRANT UPDATE, DELETE ON mpc_db.quiz_questions     TO 'mpc_app'@'localhost';
-- GRANT UPDATE, DELETE ON mpc_db.quiz_options       TO 'mpc_app'@'localhost';
-- GRANT UPDATE         ON mpc_db.quiz_attempts      TO 'mpc_app'@'localhost';
-- GRANT DELETE         ON mpc_db.intake_instructors TO 'mpc_app'@'localhost';
-- FLUSH PRIVILEGES;

-- They are commented out on purpose. A GRANT names a user this file cannot
-- know — on cPanel the application user is `cpaneluser_mpcapp`, not `mpc_app`,
-- and running the line as written creates a grant for a user that does not
-- exist rather than failing loudly. Uncomment, fix both names, then run.
--
-- To check afterwards, as the application user:
--     SHOW GRANTS;
-- The quiz tables must show UPDATE where this file says UPDATE, and
-- quiz_attempts must NOT show DELETE. tests/bootstrap.php builds exactly these
-- grants for the scratch database, so the suite is testing the real shape.
