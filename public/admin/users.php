<?php
declare(strict_types=1);
require_once __DIR__ . '/../../includes/bootstrap.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/user_reset.php';

require_login();

$me = current_user();
$myId = (string) ($me['id'] ?? '');
$message = '';
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $action = (string) ($_POST['action'] ?? '');
    if ($action === 'invite') {
        $created = invite_user([
            'username' => (string) ($_POST['username'] ?? ''),
            'name'     => (string) ($_POST['name']     ?? ''),
            'email'    => (string) ($_POST['email']    ?? ''),
        ]);
        if (!$created) {
            $error = 'Fill in name, username, and email. Username must be unique.';
        } else {
            $sent = send_invite_email($created);
            $message = $sent
                ? 'Invited ' . $created['name'] . ' — an invite link is on its way to ' . $created['email'] . '.'
                : 'Created ' . $created['name'] . ' but the invite email failed to send. Check Settings → Notifications.';
        }
    } elseif ($action === 'resend_invite') {
        $id = (string) ($_POST['id'] ?? '');
        $u = find_user($id);
        if ($u) {
            $sent = send_invite_email($u);
            $message = $sent
                ? 'Fresh invite link sent to ' . $u['email'] . '.'
                : 'Invite email failed to send. Check Settings → Notifications.';
        }
    } elseif ($action === 'update') {
        $id = (string) ($_POST['id'] ?? '');
        $fields = [
            'name'  => (string) ($_POST['name']  ?? ''),
            'email' => (string) ($_POST['email'] ?? ''),
        ];
        if (!empty($_POST['password'])) $fields['password'] = (string) $_POST['password'];
        if (update_user($id, $fields)) $message = 'Updated.';
    } elseif ($action === 'delete') {
        $id = (string) ($_POST['id'] ?? '');
        if ($id === $myId) {
            $error = "You can't delete your own account while signed in.";
        } else {
            $users = array_values(array_filter(load_users(), fn($u) => ($u['id'] ?? '') !== $id));
            if (save_users($users)) $message = 'Removed.';
        }
    }
    header('Location: /admin/users.php?msg=' . urlencode($message ?: $error));
    exit;
}

$users = load_users();
$msg = (string) ($_GET['msg'] ?? '');
$pageTitle = 'Users';
$activeTopNav = 'settings';
require_once __DIR__ . '/../../includes/admin_header.php';
?>
<div class="container py-3">
<div class="row g-4">
<div class="col-md-3"><?= admin_subnav_html('settings', 'users') ?></div>
<div class="col-md-9">
    <h1 class="h4 mb-3">Users</h1>
    <?php if ($msg !== ''): ?>
        <div class="alert alert-info alert-dismissible fade show"><?= htmlspecialchars($msg) ?><button type="button" class="btn-close" data-bs-dismiss="alert"></button></div>
    <?php endif; ?>

    <div class="card mb-3">
        <div class="card-header"><strong>Invite a new admin</strong></div>
        <div class="card-body">
            <p class="small text-muted mb-3">
                We'll email them a one-time link to set their own password. No temp password to
                pass around. The link works for 7 days.
            </p>
            <form method="post" class="row g-2 align-items-end">
                <?php csrf_field(); ?>
                <input type="hidden" name="action" value="invite">
                <div class="col-md-4"><label class="form-label">Name</label><input class="form-control" type="text" name="name" required></div>
                <div class="col-md-3"><label class="form-label">Username</label><input class="form-control" type="text" name="username" required></div>
                <div class="col-md-4"><label class="form-label">Email <span class="text-danger">*</span></label><input class="form-control" type="email" name="email" required></div>
                <div class="col-md-1 d-grid"><button type="submit" class="btn btn-dark">Invite</button></div>
            </form>
        </div>
    </div>

    <div class="card">
        <div class="card-header"><strong>All users</strong> <small class="text-muted">(<?= count($users) ?>)</small></div>
        <div class="table-responsive">
            <table class="table table-striped mb-0 align-middle">
                <thead><tr><th>Name</th><th>Username</th><th>Email</th><th>Status</th><th class="text-end">Actions</th></tr></thead>
                <tbody>
                <?php foreach ($users as $u):
                    $uid = htmlspecialchars((string) $u['id']);
                    $hasPassword = user_has_password($u);
                ?>
                    <tr>
                        <form method="post" id="editUserForm-<?= $uid ?>"></form>
                        <td><input form="editUserForm-<?= $uid ?>" name="name" class="form-control form-control-sm" value="<?= htmlspecialchars((string) $u['name']) ?>" required></td>
                        <td class="text-muted"><?= htmlspecialchars((string) ($u['username'] ?? '')) ?></td>
                        <td><input form="editUserForm-<?= $uid ?>" name="email" type="email" class="form-control form-control-sm" value="<?= htmlspecialchars((string) ($u['email'] ?? '')) ?>"></td>
                        <td class="small">
                            <?php if (!$hasPassword): ?>
                                <span class="badge bg-warning text-dark">Pending invite</span>
                            <?php elseif (!empty($u['last_login_at'])): ?>
                                <span class="text-muted">last: <?= htmlspecialchars(substr((string) $u['last_login_at'], 0, 10)) ?></span>
                            <?php else: ?>
                                <span class="text-muted">Never signed in</span>
                            <?php endif; ?>
                        </td>
                        <td class="text-end text-nowrap">
                            <input form="editUserForm-<?= $uid ?>" name="csrf" type="hidden" value="<?= htmlspecialchars(csrf_token()) ?>">
                            <input form="editUserForm-<?= $uid ?>" name="action" type="hidden" value="update">
                            <input form="editUserForm-<?= $uid ?>" name="id" type="hidden" value="<?= $uid ?>">
                            <input form="editUserForm-<?= $uid ?>" name="password" type="text" class="form-control form-control-sm d-inline-block" style="width:130px;" placeholder="New pw (opt.)">
                            <button form="editUserForm-<?= $uid ?>" type="submit" class="btn btn-sm btn-outline-dark">Save</button>
                            <?php if (!$hasPassword): ?>
                                <form method="post" class="d-inline">
                                    <?php csrf_field(); ?>
                                    <input type="hidden" name="action" value="resend_invite">
                                    <input type="hidden" name="id" value="<?= $uid ?>">
                                    <button type="submit" class="btn btn-sm btn-outline-primary">Resend invite</button>
                                </form>
                            <?php endif; ?>
                            <?php if ($uid !== htmlspecialchars($myId)): ?>
                                <form method="post" class="d-inline" onsubmit="return confirm('Remove <?= htmlspecialchars($u['name']) ?>?')">
                                    <?php csrf_field(); ?>
                                    <input type="hidden" name="action" value="delete">
                                    <input type="hidden" name="id" value="<?= $uid ?>">
                                    <button type="submit" class="btn btn-sm btn-outline-danger">Remove</button>
                                </form>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>
</div>
</div>
<?php require_once __DIR__ . '/../../includes/admin_footer.php'; ?>
