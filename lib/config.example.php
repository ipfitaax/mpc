<?php
/**
 * Database configuration — TEMPLATE. Copy, do not edit in place.
 *
 * ON THE SERVER
 *   Copy to `mpc-config.php` ALONGSIDE public_html, not inside it. A file
 *   outside the document root cannot be requested over HTTP at all, whatever
 *   a future Apache config does. db.php looks there first.
 *
 * FOR LOCAL DEVELOPMENT
 *   Copy to `lib/config.local.php`. It is gitignored, and lib/.htaccess denies
 *   web access to the whole directory.
 *
 * ON THE `user` BELOW — this is the part people get wrong.
 *
 * The application must NOT connect as the cPanel account owner. It connects as
 * a restricted user holding INSERT and SELECT and nothing else on `payments`.
 *
 * That is not belt-and-braces. The append-only triggers do not stop TRUNCATE —
 * tested, and it silently emptied the table and reset AUTO_INCREMENT, after
 * which a reversal pointed at a completely different payment than the one it
 * was written to cancel. TRUNCATE requires the DROP privilege. Withholding
 * DROP is the only thing that closes it.
 *
 * Create the restricted user in cPanel's MySQL Databases screen, or:
 *
 *     CREATE USER 'mpc_app'@'localhost' IDENTIFIED BY '...';
 *     GRANT SELECT, INSERT ON mpc_db.* TO 'mpc_app'@'localhost';
 *     GRANT UPDATE, DELETE ON mpc_db.users TO 'mpc_app'@'localhost';
 *     GRANT UPDATE ON mpc_db.enrollments TO 'mpc_app'@'localhost';
 *     FLUSH PRIVILEGES;
 *
 * users and enrollments get UPDATE because a student's phone number and an
 * enrolment's status legitimately change. `payments` never does.
 *
 * Keep the owner account for migrations and backups only.
 */

return [
    'host' => '127.0.0.1',
    'port' => 3306,
    'name' => 'mpc_db',
    'user' => 'mpc_app',
    'pass' => '',
];
