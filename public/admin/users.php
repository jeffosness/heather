<?php
declare(strict_types=1);
require_once __DIR__ . '/../../includes/bootstrap.php';
require_once __DIR__ . '/../../includes/auth.php';

require_login();

$me = current_user();
$myId = (string) ($me['id'] ?? '');
$message = '';
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $action = (string) ($_POST['action'] ?? '');
    if ($action === 'add') {
        $created = add_user([
            'username' => (string) ($_POST['username'] ?? ''),
            'name'     => (string) ($_POST['name']     ?? ''),
            'email'    => (string) ($_POST['email']    ?? ''),
            'password' => (string) ($_POST['password'] ?? ''),
        ]);
        if ($created) $message = 'Added ' . $created['name'] . '.';
        else $error = 'Fill in every field and use a unique username.';
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
$activeTopNav = 'users';
require_once __DIR__ . '/../../includes/admin_header.php';
?>
<div class="container py-3">
    <h1 class="h4 mb-3">Users</h1>
    <?php if ($msg !== ''): ?>
        <div class="alert alert-info alert-dismissible fade show"><?= htmlspecialchars($msg) ?><button type="button" class="btn-close" data-bs-dismiss="alert"></button></div>
    <?php endif; ?>

    <div class="card mb-3">
        <div class="card-header"><strong>Add user</strong></div>
        <div class="card-body">
            <form method="post" class="row g-2 align-items-end">
                <?php csrf_field(); ?>
                <input type="hidden" name="action" value="add">
                <div class="col-md-3"><label class="form-label">Name</label><input class="form-control" type="text" name="name" required></div>
                <div class="col-md-3"><label class="form-label">Username</label><input class="form-control" type="text" name="username" required></div>
                <div class="col-md-3"><label class="form-label">Email</label><input class="form-control" type="email" name="email"></div>
                <div class="col-md-2"><label class="form-label">Password</label><input class="form-control" type="text" name="password" required minlength="8"></div>
                <div class="col-md-1 d-grid"><button type="submit" class="btn btn-dark">Add</button></div>
            </form>
        </div>
    </div>

    <div class="card">
        <div class="card-header"><strong>All users</strong> <small class="text-muted">(<?= count($users) ?>)</small></div>
        <div class="table-responsive">
            <table class="table table-striped mb-0 align-middle">
                <thead><tr><th>Name</th><th>Username</th><th>Email</th><th>Last login</th><th class="text-end">Actions</th></tr></thead>
                <tbody>
                <?php foreach ($users as $u): $uid = htmlspecialchars((string) $u['id']); ?>
                    <tr>
                        <form method="post" id="editUserForm-<?= $uid ?>"></form>
                        <td><input form="editUserForm-<?= $uid ?>" name="name" class="form-control form-control-sm" value="<?= htmlspecialchars((string) $u['name']) ?>" required></td>
                        <td class="text-muted"><?= htmlspecialchars((string) ($u['username'] ?? '')) ?></td>
                        <td><input form="editUserForm-<?= $uid ?>" name="email" type="email" class="form-control form-control-sm" value="<?= htmlspecialchars((string) ($u['email'] ?? '')) ?>"></td>
                        <td class="small text-muted"><?= htmlspecialchars((string) ($u['last_login_at'] ?? '—')) ?></td>
                        <td class="text-end text-nowrap">
                            <input form="editUserForm-<?= $uid ?>" name="csrf" type="hidden" value="<?= htmlspecialchars(csrf_token()) ?>">
                            <input form="editUserForm-<?= $uid ?>" name="action" type="hidden" value="update">
                            <input form="editUserForm-<?= $uid ?>" name="id" type="hidden" value="<?= $uid ?>">
                            <input form="editUserForm-<?= $uid ?>" name="password" type="text" class="form-control form-control-sm d-inline-block" style="width:130px;" placeholder="New pw (opt.)">
                            <button form="editUserForm-<?= $uid ?>" type="submit" class="btn btn-sm btn-outline-dark">Save</button>
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
<?php require_once __DIR__ . '/../../includes/admin_footer.php'; ?>
