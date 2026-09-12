# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## What this is

The public website for Mogadishu Professional Certificate (MPC), an IT training
institute in Mogadishu. Static HTML plus two small PHP endpoints — no framework,
no build step, no package manager, no tests. Read `README.md` first; it records
the deployment target (Namecheap/cPanel, PHP 8.4, `mpc.so`) and why the stack is
deliberately this small.

## Running it

Served by the local XAMPP Apache at `http://localhost/mpc/`. There is nothing to
build — edit a file and reload.

Do **not** use `php -S` to check anything involving `.htaccess`: the built-in
server ignores it entirely and will happily serve `storage/enquiries.jsonl`,
making the storage directory look wide open when Apache would return 403.

`mail()` always fails locally (XAMPP has no mail server). That is expected —
the enquiry is still written to `storage/enquiries.jsonl`, which is the whole
point of the save-then-notify order.

## The pages are client-rendered "dc" templates, not plain HTML

Every `.html` file here is an omelette/design-canvas document, not something a
browser renders directly. The structure is:

```
<script src="./support.js">   generated runtime; loads React 18 + Babel from unpkg
<x-dc> … </x-dc>              the template
<script type="text/x-dc" data-dc-script>  class Component extends DCLogic { … }
```

Inside `<x-dc>` the markup uses template syntax that support.js compiles at page
load: `{{ expr }}` bindings, `<sc-if value="{{ … }}">`, `<sc-for list="{{ … }}"
as="item">`, and `style-hover="…"` for hover styles. All page content lives as
plain JS arrays on the `Component` class at the bottom of the file (`courses`,
`faqs`, `instructors`, `curriculumOne`, …) — **edit copy there, not in the
markup**, which only iterates over those arrays.

Consequences worth knowing:

- Styling is inline `style="…"` attributes throughout. There is no stylesheet
  beyond the small `<style>` block in `<helmet>`. Match that convention; the
  brand green is `#24A68A` (hover `#1C8570`), text `#646965`, headings `#1d1f20`.
- The page needs network access to unpkg to render at all.
- `support.js` and `image-slot.js` are **generated/vendored** — do not hand-edit
  them. `image-slot.js` provides `<image-slot>`, a drop-target image placeholder
  that renders into shadow DOM (so styling its `<img>` from the page requires
  `::part(image)` with `!important` — see the comment at the top of `index.html`).

`index.html` is the whole marketing site (16 sections, one file, ~900 lines).
`course-*.html`, `mpc-login.html`, `mpc-register.html` and
`mpc-forgot-password.html` are standalone sub-pages using the same runtime.

## The PHP endpoints, and the rule they exist to enforce

`api/enquiry.php` and `api/newsletter.php` **save to disk first, then notify**,
and never report success for something that did not happen. The endpoint only
returns `ok` after the write succeeds; a failed `mail()` is logged, not
surfaced as failure, because the lead is already safe on disk.

`api/shared.php` holds the storage path and input cleaning. Storage prefers
`../../mpc-storage` (above the web root) and falls back to `./storage`, which
ships with an `.htaccess` deny rule. These files hold students' names and phone
numbers; `.gitignore` keeps the `.jsonl` files out of the repo.

Keep `NOTIFY_FROM` on an `mpc.so` address — that domain's SPF authorises the
host, and moving the From to another domain reintroduces outright rejection by
Gmail.

## Forms

`api-form.js` holds the client half of the endpoints' rule: **never show a
success message the server did not confirm.** `window.mpcPostForm(form, url)`
posts the form, resolves `{ok, error}`, and turns every other outcome — a
validation 400, a 500, a non-JSON response, a dead network — into an error
message carrying MPC's phone number. It never rejects. Both submitting pages
use it; do not inline a second copy of that logic.

Wired: the enquiry form and newsletter signup in `index.html`, and the enquiry
form in `mpc-register.html`. Each has `name` attributes matching the PHP field
names, the hidden `website` honeypot, a disabled/relabelled button while
sending, and a message rendered green on confirmed success or red on failure.

The **password** form on `mpc-login.html`, and `mpc-forgot-password.html`, have
**no endpoint** — nothing accepts a student's email and password, because no
student has a password to check one against. Their handlers block the submit and
say plainly that nothing was sent. Do not replace those with a green "welcome
back": a message is not a login.

## Google sign-in

The one route a student *can* actually get in by. It is sign-in and nothing
else, and the code says so out loud rather than implying more.

- `api/auth/google/start.php` → Google → `api/auth/google/callback.php` →
  `account.php`. The button is a plain `<a>`, so the flow has no JavaScript in
  it at all and survives `support.js` failing to load from unpkg.
- `lib/oauth.php` holds the provider half; `lib/auth.php` gained
  `mpc_establish_session()`, which **both** login routes go through so the
  session-fixation defence cannot be the thing the newer route forgets.
- Credentials live in `mpc-config.php` above the web root, under a `google`
  key. **Leaving it out is a supported state**: the endpoint answers "not
  switched on yet" with the office phone number. Never put the client secret in
  the repo — the repo IS the deployment.
- The redirect URI must match Google Cloud Console character for character. A
  mismatch fails *at Google* and never reaches this server, so nothing appears
  in the MPC log to explain it.

Three rules in there are load-bearing, each with a test that fails when removed:

1. **Staff and admin accounts are unreachable by Google.** `admin/` writes an
   append-only money ledger; letting Google in would make that ledger's
   security the security of someone's Gmail, including its recovery flow and
   Google's own takeover surface — none of which this project can audit.
2. **Identity is the Google `sub`, never the email.** People change addresses;
   matching by email either locks them out or hands their account to whoever
   inherits it. An existing account is linked by email only when *both* sides
   say that address is verified.
3. **The ID token's `aud` is checked.** Without it, anyone who registers their
   own OAuth client can present a genuine, correctly signed Google token for
   any user and be signed in as them.

The token's signature is deliberately *not* verified, and `lib/oauth.php`
explains the bound on that: it is fetched by this server from Google's token
endpoint over TLS, which is why the shortcut holds. If an ID token ever arrives
from anywhere else — a JS sign-in button posting one — full JWKS verification
becomes mandatory. `mpc_google_claims_from_code()` takes a code and never a
token so the shape of the API keeps that honest.

`account.php` is the whole signed-in area: it names the student and offers a
sign-out, and states plainly that there is no portal behind it. Do not dress it
up with empty dashboard cards — that is "a message is not a login" pointing the
other way.

Note for any form added later: a string `onsubmit="…"` does not work here. The
runtime maps `onsubmit` to React's `onSubmit`, and React ignores a string
listener, so the form falls through to a native GET submit that reloads the
page. Bind a real handler with `onsubmit="{{ methodName }}"` instead.

## Quizzes

The first thing behind the student login that is not just a login. Papers are
written in `admin/`, sat at `quiz.php`, and marked by the server.

`lib/quiz.php` holds every rule; the pages render and never decide. Four routes
touch this feature (author, publish, sit, review) and a rule written in a page
is a rule that holds only on the pages someone remembered to write it on.

Two rules are load-bearing and each has a test that fails when removed:

1. **The answer key never reaches a browser sitting a paper.**
   `mpc_quiz_paper()` does not select `is_correct` — not filtered afterwards,
   never fetched — so a page can hand its whole return value to a template and
   the answers are still not in it. `mpc_quiz_mark_attempt()` is the only
   function that reads the key, and `mpc_quiz_review()` the only one that
   shows it, for submitted attempts only.
2. **Nothing the browser posts becomes a score.** The submission carries option
   ids and nothing else; points, percentage and pass flag are computed on the
   server. Option ids belonging to another question are dropped rather than
   matched, so a hand-edited form cannot borrow a correct option from elsewhere.

Marking is **all-or-nothing on multi-select** — two of three correct options
scores zero, not two thirds. Partial credit is four different schemes that give
four different grades for one paper, so it is a decision to make out loud rather
than drift into. A question with **no correct option is excluded from the
total** rather than scoring everyone zero, so one broken question cannot drag a
class below the pass mark; the publish guard in `admin/quiz-edit.php` is what
stops such a question reaching students in the first place, and it names the
question that fails.

**An attempt is spent when the paper is OPENED, not when it is submitted.**
Counting submissions lets a student read every question, close the tab, and come
back with an attempt still in hand. That is why `quiz.php` has a briefing screen
that says so before the student presses Start, and why reopening an unsubmitted
attempt returns the same row rather than burning another.

Three things gate a student, in this order: the paper is published, they hold an
**active** enrolment on an intake of that course, and their **fees are paid to
the current month**. Fees are monthly, so "paid up" is `months_paid >=
months_due`, never a zero balance — comparing against the course total would
lock out everyone not paying six months in advance. The gate **fails open on
missing data**: an enrolment with no start date or no agreed fee is incomplete
paperwork, not evidence of non-payment.

That third gate is MPC's decision and it has a cost worth keeping visible: a
payment taken at the desk and not yet keyed in closes an exam the student has
already paid for, and it looks to them like a broken website. That is why the
refusal names the figures and the office number instead of saying no — the
person answering the phone needs the student to be able to read out what the
screen said.

**Instructors** can now sign in. `MPC_OFFICE_ROLES` in `lib/auth.php` is one
list read by three files that must never disagree: it is who may hold a
password, who `lib/oauth.php` refuses a Google identity, and who reaches the
quiz screens. Adding a role to it has two consequences and the second is easy to
miss — it can sign in, *and* it stops being reachable by Google.

Instructors are scoped to their own classes through `intake_instructors`,
assigned on the Intakes screen. An instructor assigned to nothing may author
nothing, which is the safe direction, but it fails silently — so the quiz screen
explains it rather than rendering empty. Scoping is on the intake while quizzes
hang off the course, so an instructor teaching any intake of a course can edit
every paper on it; that is fine at MPC's size and wrong at four times it, and
the fix when it hurts is a `quiz_intakes` table.

`admin/quizzes.php` and its two screens call `mpc_require_teaching_staff()`,
**not** `mpc_require_login()`, which still admits staff and admin only. Two
gates on purpose: instructors teach, they do not work the payment desk. `MPC_NAV`
in `lib/page.php` carries the roles for each screen so the nav does not offer a
link the gate will refuse — a link that bounces you to a login page you are
already past reads as a broken system, not a closed door.

Grades are **not editable**. There is no box to type a mark into, and the
application holds no `DELETE` on `quiz_attempts` and neither `UPDATE` nor
`DELETE` on `quiz_answers` — the `payments` argument, one size down. A student
who deserves another chance is given another attempt, which leaves both sittings
on the record.

## The database

`database/` is split so that one file describes what exists and another holds
what is only designed. **No table is defined in both.** When a table graduates,
move its `CREATE TABLE` and its comments across; do not copy them.

- **`ledger.sql`** — the fourteen tables that are real: `users`,
  `social_accounts`, `courses`, `intakes`, `enrollments`, `payments`,
  `intake_instructors`, `quizzes`, `quiz_questions`, `quiz_options`,
  `quiz_attempts`, `quiz_answers`, `login_attempts`, `verify_attempts`. Run this
  on a fresh database and you are done; it is the current state, hardening
  included.
- **`future.sql`** — the other eleven (modules, lessons, recordings,
  attendance, certificates, enquiries). **Nothing creates these** and no PHP
  touches them. Kept because the reasoning in them was paid for.
  `social_accounts` is the worked example of graduation: it moved to
  `ledger.sql` the day `api/auth/google` started writing it, and `future.sql`
  keeps only a one-line note saying where it went. The six assessment tables
  followed the same route the day students could sit a paper — two of them
  changed on the way across (`quizzes` lost `lesson_id`, `quiz_questions` lost
  `short_text`), and both changes are argued in `ledger.sql` rather than in the
  note left behind.
- **`migrations/001-ledger-hardening.sql`** — carries a database built from the
  old `schema.sql` up to `ledger.sql`. Do not run it *and* `ledger.sql`.
- **`migrations/004-assessment.sql`** — adds the six assessment tables to an
  existing database. **Its GRANT section is part of the migration, not an
  optional extra.** The application connects as a restricted user whose
  privileges were granted on the tables that existed at the time, and a `GRANT`
  does not reach forward to tables created later. Skip it and the quiz screens
  load, list papers correctly, and refuse every edit — which reads as a code bug
  for as long as it takes someone to think of it.

Local dev database is **`mpc_db`** on XAMPP, which is **MariaDB 10.4**, not
MySQL. That distinction matters: `CHECK` constraints are enforced on MariaDB
10.2+ and silently ignored on MySQL 5.7, so a constraint that works here can
vanish on the host. Confirm what the host runs before relying on one.

`payments` is **append-only** and three things enforce it, none sufficient
alone:

1. `BEFORE UPDATE` / `BEFORE DELETE` triggers raising `SIGNAL SQLSTATE '45000'`.
2. `ON DELETE RESTRICT` on the enrolment foreign key — because **triggers do not
   fire for deletes caused by a foreign key CASCADE**, so CASCADE would let one
   enrolment delete silently erase a student's whole payment history.
3. A **second database user** the app connects as, with `INSERT` and `SELECT`
   only. `TRUNCATE` fires no trigger at all — tested, and it emptied the table
   and reset `AUTO_INCREMENT`, after which a reversal pointed at the wrong
   payment. Only the missing `DROP` privilege stops that.

Corrections are new rows with a negative `amount` and a `reverses_payment_id`,
so every balance is a plain `SUM(amount)` with no `CASE` anywhere. Payment ids
are **not gapless** — a rejected insert still consumes one — so receipt
numbering must carry its own sequence.

## Tests

```
composer install          # once; PHPUnit is a dev-only dependency
composer test             # or: vendor/bin/phpunit
php bin/selftest.php      # the things only the server can answer
```

The suite **builds its own scratch database** (`mpc_db_test`) from
`database/ledger.sql` on every run and refuses to touch anything else, so a
test run cannot damage `mpc_db` or production. That also means every run
re-verifies that `ledger.sql` still loads and still produces the triggers and
delete rules the tests assert on.

It runs as **two connections on purpose**: the restricted application user for
everything under test, and the owner for fixtures and for asserting on things
the application deliberately cannot see. A test that passes as owner but fails
as the app means the grants are wrong, and that is a finding.

`vendor/` is gitignored and must never be uploaded — the repo IS the
deployment, so anything committed there would ship. Nothing in `lib/` or
`admin/` uses composer's autoloader; every file requires what it needs
directly.

The suite has been mutation-checked. Reverting the payments foreign key to
`ON DELETE CASCADE` fails 3 tests, removing the negative-amount guard fails 1,
and making `months_paid` round up fails 1. On the quiz side: adding `is_correct`
to the paper query fails 1, counting only submitted attempts fails 2, giving
partial credit on multi-select fails 2, removing the payment gate fails 1, and
granting the application `DELETE` on `quiz_attempts` fails 1. A suite that
passes proves nothing until it has been shown to fail.

That last exercise earned its keep immediately: the abandoned-attempt test
passed against a deliberately broken implementation, because it set
`submitted_at` to *simulate* abandoning — which is the opposite of abandoning.
A test that cannot fail is worse than a missing one, because it is counted.

## Conventions

- Comments in this codebase explain *why*, often at length, and record decisions
  that were paid for with a bug. Preserve them; write new ones in the same voice.
- `.gitattributes` forces LF on `.php`, `.html`, `.md` and `.htaccess` because
  the repo is authored on Windows and deployed to Linux/Apache.
- Content claims are constrained: do not promise job placement, and do not
  describe MPC as an authorised provider of CCNA/CompTIA certification. See the
  end of `README.md`.

## Deploy Configuration (configured by /setup-deploy)

- Platform: Namecheap/cPanel shared hosting, PHP 8.4, via **cPanel Git Version
  Control** running `.cpanel.yml`
- Production URL: https://mpc.so (https://www.mpc.so serves the same site)
- Deploy workflow: `.cpanel.yml` — cPanel clones this repo to
  `~/repositories/mpc`, outside the web root, and its tasks copy files into
  `public_html`. Nothing is built; the repo is the deployment.
- Merge method: commits land directly on `main` (no PR flow in this repo)
- Project type: static site plus small PHP endpoints, no build step

### Custom deploy hooks

- Pre-merge: `composer test` and `php bin/selftest.php` — neither runs on the
  host, so a broken ledger only surfaces here
- Deploy trigger: **manual, two clicks.** cPanel → Git Version Control → Manage
  → *Update from Remote*, then *Deploy HEAD Commit*. A `git push` alone changes
  nothing on mpc.so; that is a property of this host, not a misconfiguration.
- Deploy status: no CLI, but `.cpanel.yml` stamps the deployed SHA into
  `public_html/build.txt` as its last task, so
  `curl -s https://mpc.so/build.txt` answers "what is live?" without a cPanel
  login. Compare it against `git rev-parse HEAD`. cPanel's Manage screen shows
  the same SHA and is the fallback when the stamp reads `unknown` or is absent
  (both mean the stamp task itself did not run — see the note in `.cpanel.yml`).
- Health check: `curl -sf https://mpc.so/` plus the checks below.

### Post-deploy health check

```bash
for p in "" support.js image-slot.js api-form.js verify assets/logo.png admin/login.php; do
  printf "%-18s %s\n" "/$p" "$(curl -s -o /dev/null -m 20 -w '%{http_code}' "https://mpc.so/$p")"
done
curl -s -o /dev/null -w 'storage deny: %{http_code}\n' https://mpc.so/storage/enquiries.jsonl
STAMP=$(curl -sf -m 20 https://mpc.so/build.txt) || STAMP='NO STAMP (build.txt did not fetch)'
printf 'deployed:   %s\nlocal HEAD: %s\n' "$(printf '%s\n' "$STAMP" | head -1)" "$(git rev-parse HEAD)"

# Signed-in pages. NOT ONE OF THESE IS A 200, and that is the pass condition —
# every one is requested signed out, so a 200 means it rendered a signed-in page
# to nobody, which is the single outcome that must never happen.
#   account.php                 302 to the login page.
#   quizzes.php, quiz.php       302 likewise. A 200 on quiz.php would mean an
#                                     exam paper served to an anonymous
#                                     visitor; a 500 means lib/quiz.php or
#                                     lib/student-page.php did not deploy.
#   api/auth/google/start.php   302 to accounts.google.com when configured,
#                                     503 when the config has no google block.
#                                     500 means lib/oauth.php did not deploy.
for p in account.php quizzes.php "quiz.php?id=1" api/auth/google/start.php; do
  printf "%-28s %s\n" "/$p" "$(curl -s -o /dev/null -m 20 -w '%{http_code}' "https://mpc.so/$p")"
done
```

Both halves of that are load-bearing, and each replaced a version that failed
silently:

- **`curl -sf`.** Without `-f` a 404 is a *success* carrying the host's error
  page, so the comparison printed `deployed: <!DOCTYPE html>`.
- **The plain assignment, not `curl … | head -1 || echo`.** A pipeline's exit
  status is the *last* command's, so `head` returning 0 swallowed curl's
  failure and the fallback never fired — it printed an empty `deployed:` line,
  which reads like an empty stamp rather than a missing one.

The shape to keep: capture first, test the capture, then format. Anything that
tests a pipeline is testing the wrong command.

Everything in the first loop must be **200** and the storage line must be
**403**. The three `.js` files are checked individually because a missing one
fails *quietly*: no `support.js` is a blank page, no `api-form.js` leaves the
enquiry button stuck on "Sending…" while the page still looks alive. `/verify`
is checked because it is the URL printed on paper receipts and it requires
`lib/`, so a 500 there means `lib/` did not deploy.

The last two lines must match. They are the only part of this check that can
tell a current deploy from a stale one: every status code above returns 200 on
the previous commit just as happily as on this one, so without the stamp a
green health check says the site is *up*, not that it is *current*.

A 404 on any of `quizzes.php`, `quiz.php` or `admin/login.php` means
`.cpanel.yml` did not copy them. All three deploy now; `admin/` was added when
the quiz screens landed, because student pages without an office tool are a
portal where no paper can ever be written.

`admin/login.php` should answer **200** — it is the one directory here with no
deny rule, because staff have to reach it. A **500** there means
`mpc-config.php` is missing from beside `public_html`, and that is the failure
this directory was held back for; comment its two tasks out again until the
config is in place.

Do not expect a fresh `admin/` deploy to have working quiz screens. Tables do
not deploy with files — see the next section.

Do not use a 404-vs-403 difference to decide whether a directory reached the
host. On this host `/lib/`, `/bin/` and `/database/` all return 404 while
`/storage/` returns 403 from a byte-identical deny rule, so the status code does
not distinguish "denied" from "missing". Check over SSH or in File Manager.

### First-time setup on the host (once)

1. cPanel → **Git Version Control** → Create, clone URL
   `https://github.com/ipfitaax/mpc.git`, path `repositories/mpc`, branch `main`.
   Private repo: cPanel needs a deploy key or a token in the URL.
2. Leave the repository path **outside `public_html`**. Cloning into the web
   root publishes `.git/` — the entire source history — to anyone who guesses
   the URL.
3. Press *Update from Remote*, then *Deploy HEAD Commit*, then run the health
   check above.

### The database does not deploy, and nothing tells you so

`.cpanel.yml` copies files. It does not run migrations, and there is no step
anywhere that does. So the normal state of the first deploy of any schema change
is **code present, tables absent** — and it lasts until a person notices.

For the quiz module that means running, once, against the production database:

```
cd ~/repositories/mpc
export MPC_CONFIG=~/mpc-config.php
mysql -u USER -p DBNAME < database/migrations/004-assessment.sql
```

including the GRANT section at its bottom, with the user and database names
edited. Skip the grants and the screens load, list papers, and refuse every
edit — which reads as a code bug rather than a missing privilege.

The code survives the gap rather than crashing through it:
`mpc_quiz_tables_present()` in `lib/quiz.php` names the migration on the office
screens and tells students the feature is not switched on yet. That is a
courtesy, not a substitute for running it.

`bin/` and `database/` are still **not** deployed and should stay that way.
cPanel clones the repo to `~/repositories/mpc`, so they are already on the host
outside the web root — run them from there with `MPC_CONFIG` set, as above.
Copying them into `public_html` would put a database dumper and the whole schema
behind nothing but a deny rule, to gain nothing at all.

## Skill routing

When the user's request matches an available skill, invoke it via the Skill tool. When in doubt, invoke the skill.

Key routing rules:
- Product ideas/brainstorming → invoke /office-hours
- Strategy/scope → invoke /plan-ceo-review
- Architecture → invoke /plan-eng-review
- Design system/plan review → invoke /design-consultation or /plan-design-review
- Full review pipeline → invoke /autoplan
- Bugs/errors → invoke /investigate
- QA/testing site behavior → invoke /qa or /qa-only
- Code review/diff check → invoke /review
- Visual polish → invoke /design-review
- Ship/deploy/PR → invoke /ship or /land-and-deploy
- Save progress → invoke /context-save
- Resume context → invoke /context-restore
- Author a backlog-ready spec/issue → invoke /spec
