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

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: ./intakes.php');
    exit;
}

mpc_csrf_check();
mpc_logout();

header('Location: ./login.php');
exit;
