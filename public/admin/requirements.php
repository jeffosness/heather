<?php
declare(strict_types=1);
require_once __DIR__ . '/../../includes/bootstrap.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/requirements_service.php';
require_once __DIR__ . '/../../includes/specialties_service.php';

require_login();

$message = '';
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $action = (string) ($_POST['action'] ?? '');
    if ($action === 'add') {
        $created = add_requirement([
            'type'           => (string) ($_POST['type']           ?? ''),
            'specialty_id'   => (string) ($_POST['specialty_id']   ?? ''),
            'procedure_name' => (string) ($_POST['procedure_name'] ?? ''),
            'role'           => (string) ($_POST['role']           ?? 'any'),
            'count_required' => (int)    ($_POST['count_required'] ?? 0),
            'label'          => (string) ($_POST['label']          ?? ''),
            'sort_order'     => (int)    ($_POST['sort_order']     ?? 100),
        ]);
        if (!$created) $error = 'Fill in the type, target, and count (min 1).';
        else $message = 'Added.';
    } elseif ($action === 'update') {
        $id = (string) ($_POST['id'] ?? '');
        update_requirement($id, [
            'role'           => (string) ($_POST['role']           ?? 'any'),
            'count_required' => (int)    ($_POST['count_required'] ?? 0),
            'label'          => (string) ($_POST['label']          ?? ''),
            'sort_order'     => (int)    ($_POST['sort_order']     ?? 100),
            'specialty_id'   => (string) ($_POST['specialty_id']   ?? ''),
            'procedure_name' => (string) ($_POST['procedure_name'] ?? ''),
        ]);
        $message = 'Updated.';
    } elseif ($action === 'delete') {
        delete_requirement((string) ($_POST['id'] ?? ''));
        $message = 'Removed.';
    }
    header('Location: /admin/requirements.php?msg=' . urlencode($message ?: $error));
    exit;
}

$reqs = load_requirements();
$specialties = load_specialties();
$specById = [];
foreach ($specialties as $s) $specById[(string) $s['id']] = $s;
$msg = (string) ($_GET['msg'] ?? '');

$pageTitle = 'Program Requirements';
$activeTopNav = 'settings';
require_once __DIR__ . '/../../includes/admin_header.php';
?>
<div class="container py-3">
<div class="row g-4">
<div class="col-md-3"><?= admin_subnav_html('settings', 'requirements') ?></div>
<div class="col-md-9">
    <h1 class="h4 mb-2">Program requirements</h1>
    <p class="text-muted small">
        The "merit badges" of the program — the specific case types every student needs before graduation,
        on top of the CST 7e minimums. Two kinds: <strong>specialty</strong> requirements (e.g. "20 first-scrub
        Orthopedic") and <strong>procedure</strong> requirements (e.g. "5 Laparoscopic Cholecystectomy" —
        matched case-insensitively as a substring so "Lap Chole" catches variants).
    </p>

    <?php if ($msg !== ''): ?>
        <div class="alert alert-info alert-dismissible fade show"><?= htmlspecialchars($msg) ?><button type="button" class="btn-close" data-bs-dismiss="alert"></button></div>
    <?php endif; ?>

    <div class="card mb-3">
        <div class="card-header"><strong>Add requirement</strong></div>
        <div class="card-body">
            <form method="post" id="addReqForm" class="row g-2 align-items-end">
                <?php csrf_field(); ?>
                <input type="hidden" name="action" value="add">
                <div class="col-md-2">
                    <label class="form-label">Type</label>
                    <select class="form-select" name="type" id="reqTypeSel" required>
                        <option value="specialty">Specialty</option>
                        <option value="procedure">Procedure</option>
                    </select>
                </div>
                <div class="col-md-4" id="reqSpecialtyWrap">
                    <label class="form-label">Specialty</label>
                    <select class="form-select" name="specialty_id">
                        <?php foreach ($specialties as $s): ?>
                            <option value="<?= htmlspecialchars((string) $s['id']) ?>"><?= htmlspecialchars((string) $s['name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-4" id="reqProcedureWrap" style="display:none;">
                    <label class="form-label">Procedure contains</label>
                    <input class="form-control" type="text" name="procedure_name" placeholder="e.g. Lap Chole">
                </div>
                <div class="col-md-2">
                    <label class="form-label">Role</label>
                    <select class="form-select" name="role">
                        <?php foreach (REQ_ROLES as $r): ?>
                            <option value="<?= $r ?>"><?= htmlspecialchars(role_short_label($r)) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-1">
                    <label class="form-label">Count</label>
                    <input class="form-control" type="number" name="count_required" min="1" value="5" required>
                </div>
                <div class="col-md-2 d-grid">
                    <button type="submit" class="btn btn-dark">Add</button>
                </div>
                <div class="col-md-8">
                    <label class="form-label small">Label <small class="text-muted">(optional — auto-generated if blank)</small></label>
                    <input class="form-control form-control-sm" type="text" name="label" placeholder="e.g. Ortho — TKR / THA / rotator cuff">
                </div>
                <div class="col-md-2">
                    <label class="form-label small">Sort</label>
                    <input class="form-control form-control-sm" type="number" name="sort_order" value="100">
                </div>
            </form>
            <script>
                (() => {
                    const sel = document.getElementById('reqTypeSel');
                    const specWrap = document.getElementById('reqSpecialtyWrap');
                    const procWrap = document.getElementById('reqProcedureWrap');
                    sel.addEventListener('change', () => {
                        const isSpec = sel.value === 'specialty';
                        specWrap.style.display = isSpec ? '' : 'none';
                        procWrap.style.display = isSpec ? 'none' : '';
                    });
                })();
            </script>
        </div>
    </div>

    <div class="card">
        <div class="card-header"><strong>All requirements</strong> <small class="text-muted">(<?= count($reqs) ?>)</small></div>
        <?php if ($reqs === []): ?>
            <div class="card-body text-muted">No requirements yet. The CST 7e minimums still apply — this list is for extras your program requires on top.</div>
        <?php else: ?>
            <div class="table-responsive">
                <table class="table table-striped mb-0 align-middle">
                    <thead>
                        <tr>
                            <th>Requirement</th>
                            <th>Type</th>
                            <th>Role</th>
                            <th style="width:100px;">Count</th>
                            <th style="width:80px;">Sort</th>
                            <th class="text-end">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($reqs as $r):
                        $rid = htmlspecialchars((string) $r['id']);
                        $type = (string) ($r['type'] ?? 'specialty');
                    ?>
                        <tr>
                            <form method="post" id="editReq-<?= $rid ?>"></form>
                            <td>
                                <input form="editReq-<?= $rid ?>" name="label" class="form-control form-control-sm mb-1" value="<?= htmlspecialchars((string) ($r['label'] ?? '')) ?>" placeholder="Auto: <?= htmlspecialchars(requirement_label($r, $specById)) ?>">
                                <?php if ($type === 'specialty'): ?>
                                    <select form="editReq-<?= $rid ?>" name="specialty_id" class="form-select form-select-sm">
                                        <?php foreach ($specialties as $s):
                                            $sel = (string) ($r['specialty_id'] ?? '') === (string) $s['id'] ? 'selected' : '';
                                        ?>
                                            <option value="<?= htmlspecialchars((string) $s['id']) ?>" <?= $sel ?>><?= htmlspecialchars((string) $s['name']) ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                <?php else: ?>
                                    <input form="editReq-<?= $rid ?>" name="procedure_name" class="form-control form-control-sm" value="<?= htmlspecialchars((string) ($r['procedure_name'] ?? '')) ?>" placeholder="Procedure contains…">
                                <?php endif; ?>
                            </td>
                            <td class="text-muted small"><?= $type === 'specialty' ? 'Specialty' : 'Procedure' ?></td>
                            <td>
                                <select form="editReq-<?= $rid ?>" name="role" class="form-select form-select-sm" style="width:100px;">
                                    <?php foreach (REQ_ROLES as $ro):
                                        $sel = (string) ($r['role'] ?? 'any') === $ro ? 'selected' : '';
                                    ?>
                                        <option value="<?= $ro ?>" <?= $sel ?>><?= htmlspecialchars(role_short_label($ro)) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </td>
                            <td><input form="editReq-<?= $rid ?>" name="count_required" type="number" min="1" class="form-control form-control-sm" value="<?= (int) ($r['count_required'] ?? 1) ?>"></td>
                            <td><input form="editReq-<?= $rid ?>" name="sort_order" type="number" class="form-control form-control-sm" value="<?= (int) ($r['sort_order'] ?? 100) ?>"></td>
                            <td class="text-end text-nowrap">
                                <input form="editReq-<?= $rid ?>" name="csrf" type="hidden" value="<?= htmlspecialchars(csrf_token()) ?>">
                                <input form="editReq-<?= $rid ?>" name="action" type="hidden" value="update">
                                <input form="editReq-<?= $rid ?>" name="id" type="hidden" value="<?= $rid ?>">
                                <button form="editReq-<?= $rid ?>" type="submit" class="btn btn-sm btn-outline-dark">Save</button>
                                <form method="post" class="d-inline" onsubmit="return confirm('Remove this requirement?')">
                                    <?php csrf_field(); ?>
                                    <input type="hidden" name="action" value="delete">
                                    <input type="hidden" name="id" value="<?= $rid ?>">
                                    <button type="submit" class="btn btn-sm btn-outline-danger">Remove</button>
                                </form>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>
</div>
</div>
</div>
<?php require_once __DIR__ . '/../../includes/admin_footer.php'; ?>
