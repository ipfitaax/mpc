# Mogadishu Professional Certificate (MPC)

The MPC website: practical IT training for recent high-school graduates in
Mogadishu. One page, two program pathways, and an enquiry form that reaches a
human.

Content follows the plan in *Mogadishu Professional Certificate (MPC) — Complete
Website Renewal and New Page Content Document*.

---

## What this is, and deliberately is not

It is a **static page plus two small PHP endpoints**. There is no framework, no
build step, no database.

That is a choice, not a shortcut. The whole job is one page and one form. A
Laravel install would add a deployment pipeline, a migration story and a
dependency tree to a site whose hardest requirement is "email the enrollment
team when a student asks about a course". When MPC needs student records,
batches and payment tracking, that is the point to reach for a framework —
and this page will still be the front of it.

```
index.html          the whole site: 16 sections, per the plan's page order
assets/logo.png     MPC logo
api/shared.php      storage location + input cleaning, in one place
api/enquiry.php     receives a student enquiry: saves it, then emails MPC
api/newsletter.php  receives a newsletter signup
storage/            enquiries.jsonl, subscribers.jsonl (never web-readable)
```

## The rule these endpoints are built around

**Save first, notify second, and never claim success for something that did not
happen.**

The page this was built from did the opposite. Its form was:

```html
<form onsubmit="event.preventDefault(); showThanks();">
```

A student typed in their name, phone and chosen program, pressed *Submit My
Inquiry*, read "Thank you for contacting MPC" — and nobody was told. Every
prospective student was lost while the page reported success.

So now the enquiry is written to disk **before** any email is attempted, and the
student only sees the thank-you if that write succeeded. If the mail fails, the
lead is still on disk and can be followed up tomorrow. If the write fails, the
student is told plainly and given the phone number, because a student who thinks
they have applied and has not is worse off than one who knows to call.

## Running it locally

XAMPP serves this directly:

```
http://localhost/mpc/
```

**Email will not work locally.** XAMPP has no mail server, so `mail()` fails and
the endpoint logs it. The enquiry is still saved — check
`storage/enquiries.jsonl`. That is the intended behaviour, not a bug: the lead
survives the mail failure.

**Do not test the storage protection with `php -S`.** PHP's built-in server
ignores `.htaccess` entirely, so it will happily serve `storage/enquiries.jsonl`
and make the directory look wide open. Under Apache the same request returns
403. Verified both ways: `php -S` → 200, XAMPP Apache → 403. Use a real Apache
when checking anything that depends on `.htaccess`.

## Deploying to mpc.so

The host is the Namecheap/cPanel shared account, which runs **PHP 8.4** and has
a working mail server.

1. Upload into `public_html`: every `.html` page, `support.js`, `image-slot.js`,
   `api-form.js`, `assets/`, `uploads/` and `api/`.

   **All three `.js` files are required, and a missing one fails quietly.**
   `support.js` is the runtime that renders the pages — without it a visitor
   gets a blank screen. `api-form.js` holds the form-posting logic, and if it
   is absent the enquiry button sticks on "Sending…" rather than erroring, so
   the page looks alive while accepting nothing. The handlers now detect that
   and show the phone number instead, but the file still has to be there for a
   form to actually work. This step is easy to under-do because the site has no
   build output to copy — the repo *is* the deployment.
2. **Create `mpc-storage/` alongside `public_html`, not inside it**, and make it
   writable. `api/shared.php` prefers that path automatically. A directory
   outside the document root cannot be requested over HTTP at all, whatever a
   future Apache config does. If the host refuses, `storage/` ships with an
   `.htaccess` that denies access — but outside is better.
3. Send a test enquiry and confirm it arrives at `info@mpc.so`.
4. Copy `lib/config.example.php` to `mpc-config.php` **alongside `public_html`**
   and fill it in. Read the note in it about the restricted database user: the
   application must not connect as the account owner, because the append-only
   triggers do not stop `TRUNCATE` and only a missing `DROP` privilege does.
5. Run `php bin/selftest.php --owner-user=... --owner-pass=...` and read every
   line. It checks the things that are true locally and may not be true here:
   whether the host grants `TRIGGER`, whether `shell_exec` is disabled, whether
   `mysqldump` exists and whether its output contains the triggers.
6. Create the first office account:
   `php bin/adduser.php --email=you@mpc.so --name="Your Name" --role=admin`
7. Schedule the backup in cPanel's Cron Jobs, once a night:

   ```
   /usr/local/bin/php /home/USER/public_html/bin/backup.php --quiet
   ```

   Then **check it actually ran.** A failure here writes to stderr and exits
   non-zero, which cPanel emails only if you have configured it to. A backup
   that never runs looks exactly like one that does, until the day you need it.
   `--email=info@mpc.so` attaches a copy so success is visible too, at the cost
   of mailing student data on a schedule; decide that deliberately.
8. Do a restore drill before this holds a month of real payments. Restore the
   newest dump into a scratch database and confirm the append-only triggers came
   back with it — a `mysqldump` restore recreates tables and silently loses
   triggers if the dump omitted them, and an append-only guarantee that
   evaporates on first recovery is not a guarantee.

### Why the email will actually arrive

`mpc.so`'s SPF record authorises this host:

```
v=spf1 +a +mx +ip4:162.213.255.89 +ip4:162.213.255.90 include:spf.web-hosting.com ~all
```

So mail sent as `info@mpc.so` from that server passes authentication. This is
not a given — `infotaxpro.com` on the same account authorises nothing, and
Gmail rejected its mail outright with `550-5.7.26 sender is unauthenticated`.

**Keep the From address on `mpc.so`.** Changing `NOTIFY_FROM` in
`api/enquiry.php` to another domain reintroduces exactly that failure.

## Still needed from MPC before launch

Section 24 of the plan lists what management must confirm. The page can go live
without them, but these are the questions a student asks before enrolling:

- Exact program names, durations and start dates
- Fees and payment schedule
- Entry requirements, minimum age, documents
- Class schedule and teaching language
- Whether students need their own computer, or MPC provides lab access
- Assessment rules and what the certificate requires
- Instructor names, titles and credentials, with their consent
- Confirmed office hours and a map link

Two claims to keep honest, both flagged in the plan: do not promise a job
without a formal placement agreement, and do not describe MPC as an official
provider of CCNA or CompTIA certification unless that authorisation exists.
