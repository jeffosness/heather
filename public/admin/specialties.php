<?php
declare(strict_types=1);
require_once __DIR__ . '/../../includes/bootstrap.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/specialties_service.php';

require_login();

$message = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $action = (string) ($_POST['action'] ?? '');
    if ($action === 'add') {
        add_specialty([
            'name'       => (string) ($_POST['name'] ?? ''),
            'is_general' => !empty($_POST['is_general']),
            'sort_order' => (int) ($_POST['sort_order'] ?? 100),
        ]);
        $message = 'Added.';
    } elseif ($action === 'update') {
        $id = (string) ($_POST['id'] ?? '');
        update_specialty($id, [
            'name'       => (string) ($_POST['name'] ?? ''),
            'is_general' => !empty($_POST['is_general']),
            'sort_order' => (int) ($_POST['sort_order'] ?? 100),
        ]);
        $message = 'Updated.';
    } elseif ($action === 'delete') {
        delete_specialty((string) ($_POST['id'] ?? ''));
        $message = 'Removed.';
    }
    header('Location: /admin/specialties.php?msg=' . urlencode($message));
    exit;
}

$items = load_specialties();
$msg = (string) ($_GET['msg'] ?? '');
$pageTitle = 'Specialties';
$activeTopNav = 'settings';
require_once __DIR__ . '/../../includes/admin_header.php';
?>
<div class="container py-3">
<div class="row g-4">
<div class="col-md-3"><?= admin_subnav_html('settings', 'specialties') ?></div>
<div class="col-md-9">
    <h1 class="h4 mb-3">Surgical specialties</h1>
    <p class="text-muted small">
        Used by the case log to sort each case as general surgery or a specialty (CST 7e distinction).
        Exactly one specialty should be flagged <strong>General</strong> — everything else is a "specialty".
        Pre-seeded with the CST 7e list on first load; edit / add / remove as needed.
    </p>

    <?php if ($msg !== ''): ?>
        <div class="alert alert-info alert-dismissible fade show"><?= htmlspecialchars($msg) ?><button type="button" class="btn-close" data-bs-dismiss="alert"></button></div>
    <?php endif; ?>

    <div class="card mb-3">
        <div class="card-header"><strong>Add specialty</strong></div>
        <div class="card-body">
            <form method="post" class="row g-2 align-items-end">
                <?php csrf_field(); ?>
                <input type="hidden" name="action" value="add">
                <div class="col-md-6"><label class="form-label">Name</label><input class="form-control" type="text" name="name" required></div>
                <div class="col-md-2"><label class="form-label">Sort order</label><input class="form-control" type="number" name="sort_order" value="100"></div>
                <div class="col-md-2 pt-4">
                    <div class="form-check"><input class="form-check-input" type="checkbox" name="is_general" id="is_general_add">
                    <label class="form-check-label" for="is_general_add">General</label></div>
                </div>
                <div class="col-md-2 d-grid"><button type="submit" class="btn btn-dark">Add</button></div>
            </form>
        </div>
    </div>

    <div class="card">
        <div class="card-header"><strong>All specialties</strong> <small class="text-muted">(<?= count($items) ?>)</small></div>
        <div class="table-responsive">
            <table class="table table-striped mb-0 align-middle">
                <thead><tr><th>Name</th><th style="width:100px;">Sort</th><th style="width:110px;">General?</th><th class="text-end">Actions</th></tr></thead>
                <tbody>
                <?php foreach ($items as $s): $sid = htmlspecialchars((string) $s['id']); ?>
                    <tr>
                        <form method="post" id="editSpec-<?= $sid ?>"></form>
                        <td><input form="editSpec-<?= $sid ?>" name="name" class="form-control form-control-sm" value="<?= htmlspecialchars((string) $s['name']) ?>" required></td>
                        <td><input form="editSpec-<?= $sid ?>" name="sort_order" type="number" class="form-control form-control-sm" value="<?= (int) $s['sort_order'] ?>"></td>
                        <td>
                            <div class="form-check">
                                <input form="editSpec-<?= $sid ?>" class="form-check-input" type="checkbox" name="is_general" <?= !empty($s['is_general']) ? 'checked' : '' ?>>
                            </div>
                        </td>
                        <td class="text-end text-nowrap">
                            <input form="editSpec-<?= $sid ?>" name="csrf" type="hidden" value="<?= htmlspecialchars(csrf_token()) ?>">
                            <input form="editSpec-<?= $sid ?>" name="action" type="hidden" value="update">
                            <input form="editSpec-<?= $sid ?>" name="id" type="hidden" value="<?= $sid ?>">
                            <button form="editSpec-<?= $sid ?>" type="submit" class="btn btn-sm btn-outline-dark">Save</button>
                            <form method="post" class="d-inline" onsubmit="return confirm('Remove <?= htmlspecialchars($s['name']) ?>?')">
                                <?php csrf_field(); ?>
                                <input type="hidden" name="action" value="delete">
                                <input type="hidden" name="id" value="<?= $sid ?>">
                                <button type="submit" class="btn btn-sm btn-outline-danger">Remove</button>
                            </form>
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
