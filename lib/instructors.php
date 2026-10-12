<?php
/**
 * Instructor accounts — created, reset and suspended from the office.
 *
 * WHY ONLY INSTRUCTORS, AND NOT STAFF OR ADMIN
 * Every function here touches role = 'instructor' and nothing else, and that
 * restriction is written into each statement's WHERE clause rather than checked
 * beforehand. A posted user id belonging to the administrator therefore matches
 * no row: it cannot be suspended, and its password cannot be reset by anybody
 * holding this screen.
 *
 * Staff and admin accounts stay on bin/adduser.php, run over SSH. That is not
 * an oversight to fix later. An account that can write the money ledger should
 * need shell access to the host to create, so that a stolen administrator
 * session on the web is not also a way to mint a second, quieter one.
 *
 * WHY CREATING ONE REFUSES AN EXISTING ADDRESS
 * Including a student's. Turning a student into an instructor would hand them
 * the grade book for the classes they sit in, and it would also take their
 * address away from Google sign-in (MPC_OFFICE_ROLES) — so the next time they
 * press "Sign in with Google" they are told no, with no idea why. bin/adduser.php
 * refuses that promotion for the same reason. If a former student becomes a
 * teacher, they get a new account under a staff address.
 *
 * PASSWORDS ARE GENERATED, NEVER TYPED
 * The administrator is handed a generated password once and gives it to the
 * instructor in person. There is no "choose a password" box, because the
 * password chosen at a busy desk in front of the person it belongs to is the
 * institute's name and the year.
 */

declare(strict_types=1);

require_once __DIR__ . '/auth.php';

/**
 * Creates an instructor account with a generated password.
 *
 * @return array{0: ?int, 1: ?string, 2: ?string}  [id, password, error]
 */
function mpc_instructor_create(PDO $db, string $name, string $email, string $phone): array
{
    $name  = trim($name);
    $email = strtolower(trim($email));
    $phone = trim($phone);

    $error = match (true) {
        $name === ''                                => 'Enter their full name.',
        mb_strlen($name) > 160                      => 'That name is too long.',
        ! filter_var($email, FILTER_VALIDATE_EMAIL) => 'Enter the email address they will sign in with.',
        mb_strlen($email) > 190                     => 'That address is too long.',
        mb_strlen($phone) > 32                      => 'That phone number is too long.',
        default                                     => null,
    };

    if ($error !== null) {
        return [null, null, $error];
    }

    // Looked up rather than left to the unique key, because the answer differs
    // by who holds the address and the person at the screen needs to know which.
    $stmt = $db->prepare('SELECT role FROM users WHERE email = ?');
    $stmt->execute([$email]);
    $taken = $stmt->fetchColumn();

    if ($taken !== false) {
        return [null, null, $taken === 'student'
            ? 'That address belongs to a student account. An instructor needs an address of their own.'
            : 'That address already has an office account.'];
    }

    $password = mpc_generate_password();

    $db->prepare(
        "INSERT INTO users (full_name, email, phone, password_hash, role, status)
         VALUES (?, ?, ?, ?, 'instructor', 'active')"
    )->execute([$name, $email, $phone !== '' ? $phone : null, password_hash($password, PASSWORD_DEFAULT)]);

    return [(int) $db->lastInsertId(), $password, null];
}

/**
 * Gives an instructor a new generated password. Returns it, or null when the
 * id is not an instructor — which is what a posted admin id comes back as.
 */
function mpc_instructor_reset_password(PDO $db, int $userId): ?string
{
    $password = mpc_generate_password();

    $stmt = $db->prepare(
        "UPDATE users SET password_hash = ? WHERE id = ? AND role = 'instructor'"
    );
    $stmt->execute([password_hash($password, PASSWORD_DEFAULT), $userId]);

    return $stmt->rowCount() === 1 ? $password : null;
}

/**
 * Suspends or reactivates an instructor.
 *
 * Suspension takes effect on their very next click, not at their next login:
 * mpc_current_user() re-reads the status on every request and signs out anyone
 * no longer active. Their quiz papers, and every grade they marked, stay
 * exactly where they are — this switches off a person, not their work.
 */
function mpc_instructor_set_status(PDO $db, int $userId, string $status): bool
{
    if (! in_array($status, ['active', 'suspended'], true)) {
        return false;
    }

    // Asked first rather than read off rowCount(): MariaDB reports rows CHANGED,
    // not rows matched, so suspending someone already suspended — a double
    // click — would come back as "no such instructor".
    $stmt = $db->prepare("SELECT 1 FROM users WHERE id = ? AND role = 'instructor'");
    $stmt->execute([$userId]);
    if ($stmt->fetchColumn() === false) {
        return false;
    }

    $db->prepare("UPDATE users SET status = ? WHERE id = ? AND role = 'instructor'")
       ->execute([$status, $userId]);

    return true;
}
