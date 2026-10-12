<?php
/**
 * The bare address — office.mpc.so/ — goes to the sign-in page.
 *
 * This directory is the document root of the office's own host, so whatever
 * answers "/" is the first thing anyone typing the address sees. Without this
 * file that is either a directory listing of every screen or the host's 403
 * page, and both read as "the system is broken" to someone who did exactly
 * what they were told.
 *
 * login.php already sends a signed-in person on to their first screen, so one
 * redirect covers both cases.
 */

header('Location: ./login.php', true, 302);
exit;
