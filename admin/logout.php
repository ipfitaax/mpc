<?php
/**
 * Sign out.
 *
 * POST only. A GET logout can be triggered by any image tag or link on any
 * page a staff member happens to open, which is a nuisance rather than a
 * breach — but it is a nuisance in the middle of recording a payment.
 */

declare(strict_types=1);

require_once dirname(__DIR__) . '/lib/auth.php';

// A GET lands on the login page, NOT on ./intakes.php as it used to. That page
// admits staff and admin only, so for an instructor the old destination was a
// bounce straight back here — and login.php already knows how to send each role
// somewhere it can actually open.
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: ./login.php');
    exit;
}

mpc_csrf_check();
mpc_logout();

header('Location: ./login.php');
exit;
