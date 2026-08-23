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
 *     GRANT DELETE ON mpc_db.verify_attempts TO 'mpc_app'@'localhost';
 *     FLUSH PRIVILEGES;
 *
 * users and enrollments get UPDATE because a student's phone number and an
 * enrolment's status legitimately change. `payments` never does.
 *
 * verify_attempts gets DELETE so the public verify page can prune its own
 * rate-limit log. That is safe in a way DELETE on payments is not: it is a log,
 * not money.
 *
 * Keep the owner account for migrations and backups only.
 */

return [
    'host' => '127.0.0.1',
    'port' => 3306,
    'name' => 'mpc_db',
    'user' => 'mpc_app',
    'pass' => '',

    // -----------------------------------------------------------------------
    // Google sign-in. OPTIONAL — leave it out and the button says so.
    // -----------------------------------------------------------------------
    // Omitting this key, or leaving any value blank, is a supported state:
    // api/auth/google/start.php answers with "not switched on for this site
    // yet" and the phone number. That is why it is absent rather than empty in
    // most deployments, and why nothing here throws when it is missing.
    //
    // THE SECRET LIVES HERE AND NOWHERE ELSE. This file sits above
    // public_html, so it cannot be requested over HTTP whatever Apache does.
    // Never put the client secret in .html, in .js, or anywhere in the repo —
    // the repo IS the deployment (README), so a secret committed is a secret
    // published, and rotating it means a new one in the Google console.
    //
    // redirect_uri must match the "Authorised redirect URI" registered in
    // Google Cloud Console CHARACTER FOR CHARACTER, including scheme, host,
    // any www., and the path. A mismatch fails at Google with
    // `redirect_uri_mismatch` and never reaches this server, so nothing in the
    // MPC error log explains it — check the console first when sign-in dies
    // immediately.
    //
    // Note mpc.so and www.mpc.so serve the same site. Register whichever one
    // students actually land on, or register both; the browser arrives at the
    // host it was on, not the host you prefer.
    //
    //   Production:  https://mpc.so/api/auth/google/callback.php
    //   Local XAMPP: http://localhost/mpc/api/auth/google/callback.php
    //
    // 'google' => [
    //     'client_id'     => '....apps.googleusercontent.com',
    //     'client_secret' => '',
    //     'redirect_uri'  => 'https://mpc.so/api/auth/google/callback.php',
    // ],
];
