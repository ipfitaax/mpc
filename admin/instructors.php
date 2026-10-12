<?php
/**
 * Instructors — the accounts that write quizzes and read grades.
 *
 * WHY THIS SCREEN EXISTS
 * Until now the only way to create an instructor was `php bin/adduser.php` over
 * SSH, and the only way to reset one's forgotten password was nothing at all:
 * adduser's --reset refuses any role but staff and admin. So an instructor who
 * forgot their password was locked out until a developer ran SQL. That is the
 * gap this closes, and the rules that keep it narrow are in lib/instructors.php
 * — chiefly that nothing here can touch a staff or admin account.
 *
 * WHY A NEW PASSWORD IS SHOWN ON THE POST RESPONSE, NOT AFTER A REDIRECT
 * Every other screen here redirects after a POST. This one deliberately does
 * not when it has a password to show, because the only way to carry it across
 * a redirect is the URL or the session. The URL puts it in browser history and
 * the server's access log; the session keeps it on the server after it has been
 * read. Rendering it once, with no-store, is the version that forgets it.
 * The cost is that a refresh offers to resubmit — which for a reset just hands
 * out another password, and for a create is refused as a duplicate address.
 */

declare(strict_types=1);

require_once dirname(__DIR__) . '/lib/auth.php';
require_once dirname(__DIR__) . '/lib/page.php';
require_once dirname(__DIR__) . '/lib/instructors.php';

$user = mpc_require_admin();
$db   = mpc_db();

$error   = null;
$notice  = null;
$handout = null;   // [name, email, password] — shown once, on this response only

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    mpc_csrf_check();

    $action = (string) ($_POST['action'] ?? '');
    $id     = (int) ($_POST['user_id'] ?? 0);

    if ($action === 'create') {
        [$newId, $password, $error] = mpc_instructor_create(
            $db,
            (string) ($_POST['full_name'] ?? ''),
            (string) ($_POST['email'] ?? ''),
            (string) ($_POST['phone'] ?? '')
        );
        if ($error === null) {
            $handout = [trim((string) $_POST['full_name']), strtolower(trim((string) $_POST['email'])), $password];
        }
    } elseif ($action === 'reset') {
        $password = mpc_instructor_reset_password($db, $id);
        if ($password === null) {
            $error = 'That is not an instructor account.';
        } else {
            $stmt = $db->prepare('SELECT full_name, email FROM users WHERE id = ?');
            $stmt->execute([$id]);
            $who = $stmt->fetch();
            $handout = [$who['full_name'], $who['email'], $password];
        }
    } elseif ($action === 'suspend' || $action === 'reactivate') {
        $ok = mpc_instructor_set_status($db, $id, $action === 'suspend' ? 'suspended' : 'active');
        if (! $ok) {
            $error = 'That is not an instructor account.';
        } else {
            header('Location: ./instructors.php?done=' . $action);
            exit;
        }
    }
}

if (($_GET['done'] ?? '') === 'suspend') {
    $notice = 'Suspended. They were signed out on the spot and cannot sign back in. Their papers and grades are untouched.';
} elseif (($_GET['done'] ?? '') === 'reactivate') {
    $notice = 'Reactivated. They can sign in again with their existing password.';
}

$instructors = $db->query(
    "SELECT id, full_name, email, phone, status, last_login_at, created_at
       FROM users WHERE role = 'instructor'
      ORDER BY status = 'suspended', full_name"
)->fetchAll();

$teaching = [];
foreach ($db->query(
    'SELECT ii.user_id, i.name AS intake_name, c.title AS course_title
       FROM intake_instructors ii
       JOIN intakes i ON i.id = ii.intake_id
       JOIN courses c ON c.id = i.course_id
      ORDER BY i.starts_on DESC'
)->fetchAll() as $row) {
    $teaching[(int) $row['user_id']][] = $row;
}

if ($handout !== null) {
    header('Cache-Control: no-store');
}

mpc_page_head('Instructors', $user);
mpc_message($notice, true);
?>

<?php if ($handout !== null): [$hName, $hEmail, $hPassword] = $handout; ?>
  <div class="card" style="border:2px solid <?= MPC_GREEN ?>">
    <h2 style="font-size:1.05rem">Password for <?= e($hName) ?></h2>
    <p style="font-family:monospace;font-size:1.4rem;font-weight:700;color:<?= MPC_HEADING ?>;margin:8px 0">
      <?= e($hPassword) ?>
    </p>
    <p style="margin-bottom:0">
      They sign in with <strong><?= e($hEmail) ?></strong> at
      <strong><?= e(mpc_office_login_url()) ?></strong>.
      <br>This is shown <strong>once</strong>. Write it down and hand it over in
      person &mdash; do not send it by WhatsApp or email. Leaving this page forgets it.
    </p>
  </div>
<?php endif; ?>

<div class="card">
  <h2 style="font-size:1.05rem">Add an instructor</h2>
  <p class="muted" style="margin-top:0">
    An instructor can write quizzes and read grades for the classes they are
    assigned to on the Intakes screen, and nothing else &mdash; not payments,
    not the student list. Staff and administrator accounts are created on the
    server with <code>bin/adduser.php</code>, not here.
  </p>
  <form method="post" action="./instructors.php">
    <?php mpc_csrf_field(); ?>
    <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:16px;margin-bottom:20px">
      <div>
        <label for="full_name">Full name</label>
        <input type="text" id="full_name" name="full_name" required maxlength="160"
               value="<?= $handout === null ? e($_POST['full_name'] ?? '') : '' ?>">
      </div>
      <div>
        <label for="email">Email they sign in with</label>
        <input type="email" id="email" name="email" required maxlength="190"
               value="<?= $handout === null ? e($_POST['email'] ?? '') : '' ?>">
      </div>
      <div>
        <label for="phone">Phone</label>
        <input type="tel" id="phone" name="phone" maxlength="32"
               value="<?= $handout === null ? e($_POST['phone'] ?? '') : '' ?>">
      </div>
    </div>
    <button type="submit" name="action" value="create">Create account</button>
    <?php mpc_message($error, false); ?>
  </form>
</div>

<div class="card">
  <h2 style="font-size:1.05rem">Instructors</h2>
  <?php if (! $instructors): ?>
    <p class="muted">None yet.</p>
  <?php else: ?>
    <table>
      <thead>
        <tr><th>Instructor</th><th>Teaching</th><th>Last signed in</th><th></th></tr>
      </thead>
      <tbody>
        <?php foreach ($instructors as $t): $suspended = $t['status'] !== 'active'; ?>
          <tr style="<?= $suspended ? 'opacity:.6' : '' ?>">
            <td>
              <strong><?= e($t['full_name']) ?></strong>
              <?php if ($suspended): ?><span class="msg-bad" style="font-size:.8rem"> &middot; suspended</span><?php endif; ?>
              <div class="muted"><?= e($t['email']) ?><?= $t['phone'] ? ' &middot; ' . e($t['phone']) : '' ?></div>
            </td>
            <td>
              <?php if (empty($teaching[(int) $t['id']])): ?>
                <span class="muted">No class yet &mdash; assign one on
                  <a href="./intakes.php">Intakes</a>, or they will see nothing.</span>
              <?php else: foreach ($teaching[(int) $t['id']] as $c): ?>
                <div><?= e($c['intake_name']) ?> <span class="muted">&mdash; <?= e($c['course_title']) ?></span></div>
              <?php endforeach; endif; ?>
            </td>
            <td><?= $t['last_login_at'] ? e($t['last_login_at']) : '<span class="muted">never</span>' ?></td>
            <td style="white-space:nowrap">
              <form method="post" action="./instructors.php" style="margin:0;display:flex;gap:12px">
                <?php mpc_csrf_field(); ?>
                <input type="hidden" name="user_id" value="<?= (int) $t['id'] ?>">
                <?php if (! $suspended): ?>
                  <button type="submit" name="action" value="reset"
                          onclick="return confirm(<?= e(json_encode('Give ' . $t['full_name'] . ' a new password? Their old one stops working.')) ?>)"
                          style="background:none;border:none;padding:0;color:<?= MPC_GREEN ?>;font-size:.85rem">New password</button>
                  <button type="submit" name="action" value="suspend"
                          onclick="return confirm(<?= e(json_encode('Suspend ' . $t['full_name'] . '? They are signed out immediately.')) ?>)"
                          style="background:none;border:none;padding:0;color:<?= MPC_RED ?>;font-size:.85rem">Suspend</button>
                <?php else: ?>
                  <button type="submit" name="action" value="reactivate"
                          style="background:none;border:none;padding:0;color:<?= MPC_GREEN ?>;font-size:.85rem">Reactivate</button>
                <?php endif; ?>
              </form>
            </td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  <?php endif; ?>
</div>

<?php
mpc_page_foot();
