<?php
/**
 * Courses — what MPC teaches and what it charges, as the office ledger sees it.
 *
 * WHAT THIS IS NOT
 * The public website. Every course-*.html page is a static file with its copy
 * inlined, and nothing here reads or writes them. Changing a fee on this screen
 * changes what the payment desk charges a NEW enrolment; it does not change a
 * number a visitor sees on mpc.so. Saying that on the screen is the whole
 * defence against an administrator raising a fee here and assuming the website
 * followed.
 *
 * WHY A FEE CHANGE IS SAFE TO ALLOW
 * Because enrolments do not point at it. mpc_record_payment() copies
 * fee_amount and duration_months onto the enrolment when it is created
 * (fee_agreed, fee_months), and every "is this student paid up" question reads
 * those copies. So raising the fee next month leaves every current student on
 * the rate they agreed at the desk, which is what they were promised. If that
 * copy is ever replaced by a join back to `courses`, this screen becomes a way
 * to put a whole class into arrears with one click — read lib/ledger.php before
 * doing it.
 *
 * WHY THERE IS NO DELETE
 * Intakes, and through them enrolments and payments, hang off a course. The
 * application holds no DELETE on `courses` and should not get one: a course with
 * money behind it is history, and history is not removed. A course MPC stops
 * teaching simply has no new intakes.
 */

declare(strict_types=1);

require_once __DIR__ . '/db.php';

const MPC_COURSE_LEVELS = [
    'beginner'     => 'Beginner',
    'intermediate' => 'Intermediate',
    'advanced'     => 'Advanced',
];

const MPC_COURSE_PATHWAYS = [
    'one_year'   => 'One-year professional',
    'six_month'  => 'Six-month intensive',
    'standalone' => 'Standalone course',
];

/**
 * Checks a submitted course form.
 *
 * Returns [clean values, null] or [partial values, error message]. Validated in
 * the order the form reads, so the first complaint is about the first field.
 *
 * $creating decides whether `slug` is read at all. It is fixed once the course
 * exists: it "matches the page URL" (ledger.sql), so renaming it here would
 * silently disconnect the course from course-<slug>.html, and nothing would
 * report it.
 */
function mpc_course_clean(array $in, bool $creating): array
{
    $c = [
        'title'           => trim((string) ($in['title'] ?? '')),
        'summary'         => trim((string) ($in['summary'] ?? '')),
        'level'           => (string) ($in['level'] ?? 'beginner'),
        'pathway'         => (string) ($in['pathway'] ?? 'standalone'),
        'duration_months' => trim((string) ($in['duration_months'] ?? '')),
        'duration_weeks'  => trim((string) ($in['duration_weeks'] ?? '')),
        'fee_amount'      => trim((string) ($in['fee_amount'] ?? '')),
        'display_order'   => trim((string) ($in['display_order'] ?? '0')),
    ];

    if ($creating) {
        $c['slug'] = strtolower(trim((string) ($in['slug'] ?? '')));
    }

    $error = match (true) {
        $creating && ! preg_match('/^[a-z0-9]+(?:-[a-z0-9]+)*$/', $c['slug'])
            => 'The short name must be lowercase letters, digits and single hyphens, like "web-design".',
        $creating && strlen($c['slug']) > 120
            => 'The short name is too long.',
        $c['title'] === ''
            => 'Give the course a title.',
        mb_strlen($c['title']) > 160
            => 'The title is too long (160 characters at most).',
        mb_strlen($c['summary']) > 400
            => 'The summary is too long (400 characters at most).',
        ! isset(MPC_COURSE_LEVELS[$c['level']])
            => 'Unknown level.',
        ! isset(MPC_COURSE_PATHWAYS[$c['pathway']])
            => 'Unknown pathway.',
        // Months are what the money is counted in: months_due on every balance
        // comes from this. Blank is allowed — the payment gate fails OPEN on a
        // missing length, by design — but zero is not, because zero months
        // makes every student paid up the day they enrol.
        $c['duration_months'] !== '' && (! ctype_digit($c['duration_months'])
            || (int) $c['duration_months'] < 1 || (int) $c['duration_months'] > 60)
            => 'Length in months must be a whole number from 1 to 60, or left blank.',
        $c['duration_weeks'] !== '' && (! ctype_digit($c['duration_weeks'])
            || (int) $c['duration_weeks'] < 1 || (int) $c['duration_weeks'] > 520)
            => 'Length in weeks must be a whole number, or left blank.',
        // Two decimal places and no more. "50.005" would be stored as 50.01 by
        // the DECIMAL column, which is the database quietly choosing a price.
        $c['fee_amount'] !== '' && (! preg_match('/^\d{1,8}(?:\.\d{1,2})?$/', $c['fee_amount']))
            => 'The monthly fee must be an amount like 50 or 49.50, or left blank.',
        ! preg_match('/^-?\d{1,4}$/', $c['display_order'])
            => 'Display order must be a whole number.',
        default => null,
    };

    return [$c, $error];
}

/** The values to bind, in column order, with blanks turned into NULLs. */
function mpc_course_row(array $c): array
{
    return [
        $c['title'],
        $c['summary'] !== '' ? $c['summary'] : null,
        $c['level'],
        $c['pathway'],
        $c['duration_months'] !== '' ? (int) $c['duration_months'] : null,
        $c['duration_weeks'] !== '' ? (int) $c['duration_weeks'] : null,
        // A string, not a float: the column is DECIMAL and a float round-trip
        // is how 49.50 becomes 49.4999999.
        $c['fee_amount'] !== '' ? $c['fee_amount'] : null,
        (int) $c['display_order'],
    ];
}

/**
 * Adds a course. Returns its id, or an error message for a taken short name.
 *
 * @return int|string
 */
function mpc_course_create(PDO $db, array $c): int|string
{
    try {
        $db->prepare(
            'INSERT INTO courses (title, summary, level, pathway, duration_months,
                                  duration_weeks, fee_amount, display_order, slug)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)'
        )->execute([...mpc_course_row($c), $c['slug']]);
    } catch (PDOException $e) {
        if ($e->getCode() === '23000') {
            return 'A course with the short name "' . $c['slug'] . '" already exists.';
        }
        throw $e;
    }

    return (int) $db->lastInsertId();
}

/**
 * Changes a course's details. The short name is never touched — see
 * mpc_course_clean().
 *
 * This needs UPDATE on `courses`, which the application did not hold before
 * this screen existed. database/migrations/005-office-management.sql grants it,
 * and a missing grant fails here as a privileges error the page shows in full,
 * rather than as a save that appeared to work.
 */
function mpc_course_update(PDO $db, int $courseId, array $c): void
{
    $db->prepare(
        'UPDATE courses
            SET title = ?, summary = ?, level = ?, pathway = ?, duration_months = ?,
                duration_weeks = ?, fee_amount = ?, display_order = ?
          WHERE id = ?'
    )->execute([...mpc_course_row($c), $courseId]);
}
