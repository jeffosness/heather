<?php
declare(strict_types=1);
require_once __DIR__ . '/../../includes/bootstrap.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/student_auth.php';
require_once __DIR__ . '/../../includes/cases_service.php';
require_once __DIR__ . '/../../includes/specialties_service.php';

require_student();
$student = current_student();
$studentId = (string) $student['id'];

$message = '';
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $action = (string) ($_POST['action'] ?? '');
    if ($action === 'add') {
        $created = add_case($studentId, [
            'case_date'    => (string) ($_POST['case_date']    ?? ''),
            'specialty_id' => (string) ($_POST['specialty_id'] ?? ''),
            'procedure'    => (string) ($_POST['procedure']    ?? ''),
            'doctor'       => (string) ($_POST['doctor']       ?? ''),
            'preceptor'    => (string) ($_POST['preceptor']    ?? ''),
            'role'         => (string) ($_POST['role']         ?? ''),
            'notes'        => (string) ($_POST['notes']        ?? ''),
        ]);
        $message = $created ? 'Case logged.' : '';
        if (!$created) $error = 'Fill in date, specialty, procedure, and role.';
    } elseif ($action === 'update') {
        $id = (string) ($_POST['id'] ?? '');
        $existing = find_case($id);
        if (!$existing || (string) ($existing['student_id'] ?? '') !== $studentId) {
            $error = 'Case not found.';
        } else {
            update_case($id, [
                'case_date'    => (string) ($_POST['case_date']    ?? ''),
                'specialty_id' => (string) ($_POST['specialty_id'] ?? ''),
                'procedure'    => (string) ($_POST['procedure']    ?? ''),
                'doctor'       => (string) ($_POST['doctor']       ?? ''),
                'preceptor'    => (string) ($_POST['preceptor']    ?? ''),
                'role'         => (string) ($_POST['role']         ?? ''),
                'notes'        => (string) ($_POST['notes']        ?? ''),
            ]);
            $message = 'Case updated.';
        }
    } elseif ($action === 'delete') {
        $id = (string) ($_POST['id'] ?? '');
        $existing = find_case($id);
        if ($existing && (string) ($existing['student_id'] ?? '') === $studentId) {
            delete_case($id);
            $message = 'Case removed.';
        }
    }
    header('Location: /students/cases.php?msg=' . urlencode($message ?: $error));
    exit;
}

$cases = cases_for_student($studentId);
$specialties = load_specialties();
$specById = [];
foreach ($specialties as $s) $specById[(string) $s['id']] = $s;

// Site-wide doctor + preceptor lists (auto-populated as students type new
// names — Heather can normalize in admin). Every student's autocomplete
// draws from the same set, so 'Dr. Chen' stays 'Dr. Chen' across the cohort.
$doctorsList = load_doctors();
$preceptorsList = load_preceptors();
$doctorById = doctors_by_id();
$preceptorById = preceptors_by_id();

$msg = (string) ($_GET['msg'] ?? '');
$showAdd = isset($_GET['add']) || isset($_GET['edit']);
$editingCase = null;
if (isset($_GET['edit'])) {
    $editingCase = find_case((string) $_GET['edit']);
    if ($editingCase && (string) ($editingCase['student_id'] ?? '') !== $studentId) $editingCase = null;
    $showAdd = $editingCase !== null;
}
$formDefaultDate = date('Y-m-d');

$pageTitle = 'My cases';
$activeNav = 'cases';
require_once __DIR__ . '/../../includes/student_header.php';
?>
<div class="container py-4">
    <div class="d-flex justify-content-between align-items-end flex-wrap gap-2 mb-3">
        <h1 class="h4 mb-0">My cases <small class="text-muted">(<?= count($cases) ?>)</small></h1>
        <?php if (!$showAdd): ?>
            <a href="/students/cases.php?add=1" class="btn btn-scrub">+ Log a new case</a>
        <?php endif; ?>
    </div>

    <?php if ($msg !== ''): ?>
        <div class="alert alert-info alert-dismissible fade show"><?= htmlspecialchars($msg) ?><button type="button" class="btn-close" data-bs-dismiss="alert"></button></div>
    <?php endif; ?>

    <?php if ($showAdd): ?>
        <div class="card mb-4">
            <div class="card-header"><strong><?= $editingCase ? 'Edit case' : 'Log a new case' ?></strong></div>
            <div class="card-body">
                <form method="post">
                    <?php csrf_field(); ?>
                    <input type="hidden" name="action" value="<?= $editingCase ? 'update' : 'add' ?>">
                    <?php if ($editingCase): ?>
                        <input type="hidden" name="id" value="<?= htmlspecialchars((string) $editingCase['id']) ?>">
                    <?php endif; ?>
                    <div class="row g-3">
                        <div class="col-md-3">
                            <label class="form-label">Date <span class="text-danger">*</span></label>
                            <input type="date" name="case_date" class="form-control" required
                                value="<?= htmlspecialchars($editingCase['case_date'] ?? $formDefaultDate) ?>">
                        </div>
                        <div class="col-md-5">
                            <label class="form-label">Specialty <span class="text-danger">*</span></label>
                            <select name="specialty_id" class="form-select" required>
                                <option value="">Select a specialty…</option>
                                <?php foreach ($specialties as $s):
                                    $sel = ($editingCase && (string) $editingCase['specialty_id'] === (string) $s['id']) ? 'selected' : '';
                                ?>
                                    <option value="<?= htmlspecialchars((string) $s['id']) ?>" <?= $sel ?>>
                                        <?= htmlspecialchars((string) $s['name']) ?><?= !empty($s['is_general']) ? ' (general)' : '' ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">Role <span class="text-danger">*</span></label>
                            <select name="role" class="form-select" required>
                                <?php foreach (CASE_ROLES as $r):
                                    $sel = ($editingCase && (string) $editingCase['role'] === $r) ? 'selected' : '';
                                ?>
                                    <option value="<?= $r ?>" <?= $sel ?>><?= htmlspecialchars(role_label($r)) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-12">
                            <label class="form-label">Procedure <span class="text-danger">*</span></label>
                            <input type="text" name="procedure" class="form-control" required
                                value="<?= htmlspecialchars((string) ($editingCase['procedure'] ?? '')) ?>"
                                placeholder="e.g. Laparoscopic Cholecystectomy">
                        </div>
                        <?php
                            $editingDoctorName = $editingCase && !empty($editingCase['doctor_id'])
                                ? (string) ($doctorById[(string) $editingCase['doctor_id']]['name'] ?? '')
                                : '';
                            $editingPreceptorName = $editingCase && !empty($editingCase['preceptor_id'])
                                ? (string) ($preceptorById[(string) $editingCase['preceptor_id']]['name'] ?? '')
                                : '';
                        ?>
                        <div class="col-md-6">
                            <label class="form-label">Doctor</label>
                            <input type="text" name="doctor" class="form-control" list="doctors-list"
                                value="<?= htmlspecialchars($editingDoctorName) ?>"
                                placeholder="Pick from the list or type a new one">
                            <datalist id="doctors-list">
                                <?php foreach ($doctorsList as $d): ?>
                                    <option value="<?= htmlspecialchars((string) $d['name']) ?>"></option>
                                <?php endforeach; ?>
                            </datalist>
                            <small class="text-muted">New names get added to Heather's list automatically.</small>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Preceptor</label>
                            <input type="text" name="preceptor" class="form-control" list="preceptors-list"
                                value="<?= htmlspecialchars($editingPreceptorName) ?>"
                                placeholder="Pick from the list or type a new one">
                            <datalist id="preceptors-list">
                                <?php foreach ($preceptorsList as $p): ?>
                                    <option value="<?= htmlspecialchars((string) $p['name']) ?>"></option>
                                <?php endforeach; ?>
                            </datalist>
                        </div>
                        <div class="col-12">
                            <label class="form-label">Notes <small class="text-muted">(optional — no patient info please)</small></label>
                            <textarea name="notes" class="form-control" rows="2"><?= htmlspecialchars((string) ($editingCase['notes'] ?? '')) ?></textarea>
                        </div>
                    </div>
                    <div class="mt-3 d-flex gap-2 flex-wrap">
                        <button type="submit" class="btn btn-scrub"><?= $editingCase ? 'Save changes' : 'Log case' ?></button>
                        <a href="/students/cases.php" class="btn btn-outline-secondary">Cancel</a>
                    </div>
                </form>
            </div>
        </div>
    <?php endif; ?>

    <?php if ($cases === []): ?>
        <div class="card"><div class="card-body text-muted">No cases logged yet. Hit <strong>+ Log a new case</strong> to add your first.</div></div>
    <?php else: ?>
        <div class="card">
            <div class="table-responsive">
                <table class="table table-striped mb-0 align-middle">
                    <thead>
                        <tr>
                            <th>Date</th>
                            <th>Procedure</th>
                            <th>Specialty</th>
                            <th>Role</th>
                            <th>Doctor</th>
                            <th>Preceptor</th>
                            <th class="text-end">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($cases as $c):
                            $sp = $specById[(string) ($c['specialty_id'] ?? '')] ?? null;
                            $cid = htmlspecialchars((string) $c['id']);
                        ?>
                            <tr>
                                <td><?= htmlspecialchars((string) $c['case_date']) ?></td>
                                <td>
                                    <strong><?= htmlspecialchars((string) $c['procedure']) ?></strong>
                                    <?php if (!empty($c['notes'])): ?>
                                        <div class="small text-muted"><?= nl2br(htmlspecialchars((string) $c['notes'])) ?></div>
                                    <?php endif; ?>
                                </td>
                                <td><?= $sp ? htmlspecialchars((string) $sp['name']) : '<span class="text-danger">unset</span>' ?></td>
                                <td><span class="badge bg-light text-dark border"><?= htmlspecialchars(role_label((string) $c['role'])) ?></span></td>
                                <td class="small text-muted">
                                    <?php
                                        $dId = (string) ($c['doctor_id'] ?? '');
                                        $dName = $dId !== '' ? (string) ($doctorById[$dId]['name'] ?? '(deleted)') : '';
                                        echo htmlspecialchars($dName);
                                    ?>
                                </td>
                                <td class="small text-muted">
                                    <?php
                                        $pId = (string) ($c['preceptor_id'] ?? '');
                                        $pName = $pId !== '' ? (string) ($preceptorById[$pId]['name'] ?? '(deleted)') : '';
                                        echo htmlspecialchars($pName);
                                    ?>
                                </td>
                                <td class="text-end text-nowrap">
                                    <a href="/students/cases.php?edit=<?= $cid ?>" class="btn btn-sm btn-outline-secondary">Edit</a>
                                    <form method="post" class="d-inline" onsubmit="return confirm('Delete this case?')">
                                        <?php csrf_field(); ?>
                                        <input type="hidden" name="action" value="delete">
                                        <input type="hidden" name="id" value="<?= $cid ?>">
                                        <button type="submit" class="btn btn-sm btn-outline-danger">×</button>
                                    </form>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    <?php endif; ?>
</div>
<?php require_once __DIR__ . '/../../includes/student_footer.php'; ?>
