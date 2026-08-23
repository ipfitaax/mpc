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

`mpc-login.html` and `mpc-forgot-password.html` have **no endpoint** — there is
no auth code, and the portal tables in `database/future.sql` are unused. Their
handlers block the submit and say plainly that nothing was sent. Do not replace those
with a green "welcome back": a message is not a login.

Note for any form added later: a string `onsubmit="…"` does not work here. The
runtime maps `onsubmit` to React's `onSubmit`, and React ignores a string
listener, so the form falls through to a native GET submit that reloads the
page. Bind a real handler with `onsubmit="{{ methodName }}"` instead.

## The database

`database/` is split so that one file describes what exists and another holds
what is only designed. **No table is defined in both.** When a table graduates,
move its `CREATE TABLE` and its comments across; do not copy them.

- **`ledger.sql`** — the six tables that are real: `users`, `courses`,
  `intakes`, `enrollments`, `payments`, `login_attempts`. Run this on a fresh
  database and you are done; it is the current state, hardening included.
- **`future.sql`** — the other eighteen (modules, lessons, recordings, quizzes,
  attendance, certificates, social logins, enquiries). **Nothing creates these**
  and no PHP touches them. Kept because the reasoning in them was paid for.
- **`migrations/001-ledger-hardening.sql`** — carries a database built from the
  old `schema.sql` up to `ledger.sql`. Do not run it *and* `ledger.sql`.

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
and making `months_paid` round up fails 1. A suite that passes proves nothing
until it has been shown to fail.

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
for p in "" support.js image-slot.js api-form.js verify assets/logo.png; do
  printf "%-16s %s\n" "/$p" "$(curl -s -o /dev/null -m 20 -w '%{http_code}' "https://mpc.so/$p")"
done
curl -s -o /dev/null -w 'storage deny: %{http_code}\n' https://mpc.so/storage/enquiries.jsonl
printf 'deployed:   %s\nlocal HEAD: %s\n' \
  "$(curl -sf -m 20 https://mpc.so/build.txt | head -1 || echo 'NO STAMP (see below)')" \
  "$(git rev-parse HEAD)"
```

`curl -sf` there is load-bearing: without `-f`, a 404 succeeds and pipes the
host's error *page* into the comparison, so the line reads
`deployed: <!DOCTYPE html>` instead of saying the stamp is missing.

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

`admin/`, `bin/` and `database/` are commented out in `.cpanel.yml`. Read the
block at the bottom of that file before enabling them: `admin/` carries no deny
rule and needs `mpc-config.php` above `public_html` first, or it throws a fatal
error at a visitor.

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
