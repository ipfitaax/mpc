# TODOS

Deferred work with the reasoning attached. A TODO without context is worse
than no TODO — it creates false confidence that the idea was captured while
actually losing why it mattered.

---

## 1. Move enquiries out of the JSONL file and into the database

**What.** `api/enquiry.php` writes each enquiry as one JSON line to
`storage/enquiries.jsonl`. Move that into the `enquiries` table, and give the
office a screen to work the list.

**Why.** The table is already designed. `database/future.sql` defines it with
a `status` ENUM of `new`, `contacted`, `enrolled`, `not_interested`, a
`handled_by` and a `handled_at`, and its own comment says plainly:

> Enquiries from the public site. Currently written to storage/enquiries.jsonl
> by api/enquiry.php; this is where they belong once the database exists, so
> the office can see who has been followed up and who has not.

Today a prospective student who fills in the form becomes a line in a file
nobody opens. The save-then-notify rule means the lead is never lost, but
"not lost" and "followed up" are different things. Nothing records whether
anyone called them back.

**Pros.** The ledger work creates the database anyway, so the expensive
prerequisite is already paid for. The table exists. And there is a reasonable
argument this is closer to revenue than the ledger is: an unworked enquiry is
a student who never enrolled, which costs a full course fee, while a disputed
payment costs the disputed amount.

**Cons.** It is a second project with its own migration, its own screen, and
its own decisions about what "contacted" means in practice. Bolting it onto
the ledger build is how the ledger stops shipping.

**Depends on / blocked by.** The ledger's MySQL database and `lib/db.php`
landing first. Do not start this before the ledger is in the office's hands.

**Where to start.** Read the `enquiries` table in `database/future.sql` and
`api/enquiry.php`. The migration is a one-off script reading the JSONL and
inserting rows; the ongoing change is one line in `api/enquiry.php` to write
to both during a transition period, then to the table alone.

---

## 2. Approach C — offline reconciliation, if connectivity turns out to be the blocker

**What.** Stop trying to replace the paper notebook and reconcile with it
instead. The office keeps writing in the book; the tool becomes a local-first
form that queues entries in the browser and syncs when the connection returns,
with a pre-printed numbered receipt pad as the offline fallback. Book and
database reconcile against the same receipt numbers.

**Why.** The notebook's one genuine advantage is that it works with no
internet, no power, no login and no training. The payment ledger design
(`~/.gstack/projects/mpc/admin-master-design-20260817-153108.md`) assumes the
office has workable connectivity, and that assumption is currently unverified
— it is Open Question 4 in that document. If it turns out to be false, the
ledger will quietly lose to the notebook on the days that matter and nobody
will be able to say why.

**Pros.** Never fails on a bad day. Adoption is near-free because nobody is
asked to abandon a working habit. The design doc already holds the full
reasoning in its Approaches Considered section, so the thinking is not lost.

**Cons.** Sync, deduplication and conflict handling are where projects of this
shape die. It is roughly 4-6 weeks of human work for a problem that may not
exist. Building it speculatively would be the exact over-engineering the
office hours session was run to avoid.

**Depends on / blocked by.** Evidence. Specifically: the office being observed
falling back to the notebook after the ledger ships, or the office visit
revealing that connectivity or power is unreliable enough to matter. Do not
build this on a hunch.

**Where to start.** Success criterion 5 in the design doc — thirty days after
launch, is the office still writing payments in the book? If yes, find out
whether the reason is connectivity or something else before reaching for this.

---

## 3. The student portal — exercises and points

**What.** Students sign in to do exercises and see their points. Not built, and
deliberately so: premise 1 of the ledger design was "the user of v1 is MPC
office staff, not students".

**Why it is back on the table.** 2026-08-19: MPC confirmed a student has asked
for it. That is the first demand evidence for the portal from the people who
would use it, and it is a stronger signal than anything behind the ledger,
which was justified on a role category ("office staff") rather than a named
person.

**What already exists.** `database/future.sql` holds the full design with its
reasoning intact: `modules`, `lessons`, `recordings`, `lesson_progress`,
`attendance`, `quizzes`, `quiz_questions`, `quiz_options`, `quiz_attempts`,
`quiz_answers`, `certificates`. None are created on the server; no PHP reads
them. `users` already carries `role`, `password_hash` and the social-login
columns, so students are rows in a table that exists.

**Pros.** The schema work is done and was paid for. `lib/auth.php` already does
sessions, CSRF, rate limiting and password hashing, and `lib/page.php` gives the
chrome. A student portal reuses all of it rather than starting from nothing.

**Cons.** It is a bigger build than the ledger, and it is the LMS the office
hours session cut on purpose. Eleven tables against seven.

**Questions it needs answered first, and they are not technical.**
- Who writes the exercises, and when? A quiz nobody authors is an empty screen.
- Are exercises done in class or at home? That decides whether this needs to
  work on a phone on a bad connection.
- Do students have their own devices, or is it the MPC lab? Open Question in
  README, still unanswered.
- What is a "point" — a quiz score, attendance, both? `quiz_attempts` stores a
  score; `attendance` is a separate table. Nothing currently combines them.
- Which student asked, and what exactly did they say they wanted to see?

**DECISION 2026-08-19: build it regardless of demand evidence.** Asked which
student asked and what they said, MPC answered that it should be built either
way. Recorded as a decision, not as validated demand, so nobody later reads it
as evidence it was not.

That is a legitimate call - institutions build ahead of demand - but it removes
the check that turned a 24-table LMS into a 7-table ledger that works. The
discipline has to come from somewhere else now, and the only remaining source
is the wedge question: **what is the smallest version a student would open
twice?** Not "what does the schema support". Eleven tables can become a quiz
engine, a grades page, an attendance record or a certificate check, and those
are four different products with four different amounts of work.

Answer that before writing code, or the schema will answer it for you and the
answer will be "all of it".

**Where to start.** `/office-hours` on the student portal specifically, the same
treatment the ledger got. Do not skip the diagnostic because the schema already
exists - having the tables is not the same as knowing which three screens
matter.

**Depends on / blocked by.** Nothing technical. `mpc-login.html` and
`mpc-register.html` are already built as honest placeholders and are where this
would land.

---

## 4. The points-total index, deferred until the query exists

**What.** Add a covering index on `quiz_attempts` for the points-total query,
once that query has actually been written.

**Why.** It runs on every `account.php` load for a signed-in student. It wants
an index shaped to it. Nobody knows its shape yet.

**Why it is not in `004-quiz-engine.sql`.** It nearly was. The plan originally
scored points as `SUM` of the best attempt per quiz, and an index of
`(user_id, quiz_id, score_points)` was written into the migration to serve that
grouped-maximum query. Review then changed the scoring rule to **first attempt
only** — because `quiz_questions.explanation` is shown after submission, so with
unlimited attempts and best-attempt scoring every student reaches 100% by
retaking, and points would measure persistence rather than knowledge. That
change deleted the query the index was for. Shipping it anyway would have put a
line in the schema whose stated reason was already false.

**Where to start.** Under first-attempt scoring the query is a per-quiz lookup
of the earliest attempt, so the index probably wants `(user_id, quiz_id,
attempt_no)` — and may already be served by `uq_attempt`. **Measure before
adding.** Do not re-derive this from a slow page; the shape depends entirely on
how the points function ends up written.

**Depends on / blocked by.** The classroom visit answering what points mean at
all, then `lib/quiz.php`'s points function existing. If points turn out to mean
nothing to MPC, this is moot and the whole points screen is not built.

---

## 5. Per-teacher course scoping (`intake_instructors`)

**DO THIS BEFORE THE SECOND INSTRUCTOR ACCOUNT EXISTS, NOT AFTER.**

**What.** Graduate `intake_instructors` from `future.sql` and scope
`teach/quizzes.php` so an instructor sees only the courses they teach.

**Why.** v1 deliberately ships without it: every signed-in instructor sees every
course and can edit any quiz in it. With one authoring teacher that is fine and
simpler. With two it means one teacher can silently overwrite another's work,
with nothing recording that they did.

**The non-obvious half.** The query is a join and a where clause. The real cost
is a screen for the office to assign instructors to intakes, because **nothing
today assigns a teacher to an intake at all**. That screen is a small product of
its own and nobody has designed it.

**Note on `created_by`.** `quizzes.created_by` is added in `004` so an author can
see their own unpublished work. That is authorship, not permission. Scoping is
what would turn it into permission. Do not mistake one for the other.

**Depends on / blocked by.** Nothing technical. The trigger is organisational:
the moment MPC wants a second person authoring.

---

## 6. `quiz_attempts.enrollment_id` — a decision with an expiry date

**THIS EXPIRES WHEN `004-quiz-engine.sql` RUNS. After that it is effectively
permanent, because `quiz_attempts` is append-only and its rows can never be
rewritten.**

**What.** Store `enrollment_id` on `quiz_attempts` instead of `user_id`.

**Why.** As designed, "every attempt belongs to a student enrolled in that
quiz's course" is a query you run, not a rule the database enforces. Pointing
attempts at the enrolment makes the bad state unrepresentable. It also turns
Success Criterion 3 from an assertion into a foreign key, and shortens every
join to intake and course.

**Why it was not simply done.** A student enrolled in two intakes of the same
course has two enrolments, and the code would have to choose one. Every query
in the design doc is written against `user_id`. Neither is hard; both are real.

**Context.** This exists because of P6: `lib/oauth.php` creates a `users` row for
any Google account that signs in, and that row has no enrolment behind it. The
whole Step 0 linking action exists to attach one. Referencing the enrolment
directly is the stronger version of that same fix.

**Depends on / blocked by.** Nothing. It must be decided before `004` runs.
