# TODOS

Deferred work with the reasoning attached. A TODO without context is worse
than no TODO — it creates false confidence that the idea was captured while
actually losing why it mattered.

---

## 1. Move enquiries out of the JSONL file and into the database

**What.** `api/enquiry.php` writes each enquiry as one JSON line to
`storage/enquiries.jsonl`. Move that into the `enquiries` table, and give the
office a screen to work the list.

**Why.** The table is already designed. `database/schema.sql` defines it with
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

**Where to start.** Read the `enquiries` table in `database/schema.sql` and
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
