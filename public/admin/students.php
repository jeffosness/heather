<?php
declare(strict_types=1);
require_once __DIR__ . '/../../includes/bootstrap.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/students_service.php';
require_once __DIR__ . '/../../includes/user_reset.php';
require_once __DIR__ . '/../../includes/case_progress.php';

require_login();

$message = '';
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $action = (string) ($_POST['action'] ?? '');
    if ($action === 'invite') {
        $created = invite_student([
            'name'          => (string) ($_POST['name']          ?? ''),
            'username'      => (string) ($_POST['username']      ?? ''),
            'email'         => (string) ($_POST['email']         ?? ''),
            'cohort_season' => (string) ($_POST['cohort_season'] ?? ''),
            'cohort_year'   => (int)    ($_POST['cohort_year']   ?? 0),
        ]);
        if (!$created) {
            $error = 'Fill in every field. Username must be unique. Cohort season + year required.';
        } else {
            $sent = send_student_invite_email($created);
            $message = $sent
                ? 'Invited ' . $created['name'] . ' — invite email on its way to ' . $created['email'] . '.'
                : 'Created ' . $created['name'] . ' but the invite email failed. Check Settings → Notifications.';
        }
    } elseif ($action === 'resend_invite') {
        $id = (string) ($_POST['id'] ?? '');
        $s = find_student($id);
        if ($s) {
            $sent = send_student_invite_email($s);
            $message = $sent ? 'Fresh invite sent to ' . $s['email'] . '.' : 'Invite email failed. Check Settings → Notifications.';
        }
    } elseif ($action === 'update') {
        $id = (string) ($_POST['id'] ?? '');
        update_student($id, [
            'name'          => (string) ($_POST['name']          ?? ''),
            'email'         => (string) ($_POST['email']         ?? ''),
            'cohort_season' => (string) ($_POST['cohort_season'] ?? ''),
            'cohort_year'   => (int)    ($_POST['cohort_year']   ?? 0),
        ]);
        $message = 'Updated.';
    } elseif ($action === 'delete') {
        $id = (string) ($_POST['id'] ?? '');
        delete_student($id);
        $message = 'Removed.';
    }
    header('Location: /admin/students.php?msg=' . urlencode($message ?: $error));
    exit;
}

$students = load_students();
$cohorts  = all_cohorts();
$filterCohort = trim((string) ($_GET['cohort'] ?? ''));  // "Fall 2026" or ""
if ($filterCohort !== '') {
    $students = array_values(array_filter($students, fn($s) => student_cohort_label($s) === $filterCohort));
}
$msg = (string) ($_GET['msg'] ?? '');
$defaultYear = (int) date('Y');

$pageTitle = 'Students';
$activeTopNav = 'students';
require_once __DIR__ . '/../../includes/admin_header.php';
?>
<div class="container py-3">
    <h1 class="h4 mb-3">Students</h1>
    <p class="text-muted small">Surgical Tech students who log their cases at <a href="/students/">/students/</a>. Invite new students by email; they set their own password on first click.</p>

    <?php if ($msg !== ''): ?>
        <div class="alert alert-info alert-dismissible fade show"><?= htmlspecialchars($msg) ?><button type="button" class="btn-close" data-bs-dismiss="alert"></button></div>
    <?php endif; ?>

    <div class="card mb-3">
        <div class="card-header"><strong>Invite a new student</strong></div>
        <div class="card-body">
            <form method="post" class="row g-2 align-items-end">
                <?php csrf_field(); ?>
                <input type="hidden" name="action" value="invite">
                <div class="col-md-3"><label class="form-label">Name</label><input class="form-control" type="text" name="name" required></div>
                <div class="col-md-2"><label class="form-label">Username</label><input class="form-control" type="text" name="username" required></div>
                <div class="col-md-3"><label class="form-label">Email <span class="text-danger">*</span></label><input class="form-control" type="email" name="email" required></div>
                <div class="col-md-2">
                    <label class="form-label">Cohort <span class="text-danger">*</span></label>
                    <select class="form-select" name="cohort_season" required>
                        <?php foreach (COHORT_SEASONS as $s): ?>
                            <option value="<?= $s ?>" <?= $s === 'Fall' ? 'selected' : '' ?>><?= $s ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-1">
                    <label class="form-label">Year</label>
                    <input class="form-control" type="number" name="cohort_year" min="2000" max="2100" value="<?= $defaultYear ?>" required>
                </div>
                <div class="col-md-1 d-grid"><button type="submit" class="btn btn-dark">Invite</button></div>
            </form>
        </div>
    </div>

    <?php if ($cohorts !== []): ?>
        <div class="mb-3 d-flex flex-wrap gap-2 small align-items-center">
            <span class="text-muted">Filter by cohort:</span>
            <a href="/admin/students.php" class="btn btn-sm <?= $filterCohort === '' ? 'btn-dark' : 'btn-outline-dark' ?>">All</a>
            <?php foreach ($cohorts as $c): ?>
                <a href="/admin/students.php?cohort=<?= urlencode($c['label']) ?>" class="btn btn-sm <?= $filterCohort === $c['label'] ? 'btn-dark' : 'btn-outline-dark' ?>">
                    <?= htmlspecialchars($c['label']) ?>
                </a>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>

    <div class="card">
        <div class="card-header"><strong>Roster</strong> <small class="text-muted">(<?= count($students) ?>)</small></div>
        <?php if ($students === []): ?>
            <div class="card-body text-muted">No students yet.</div>
        <?php else: ?>
            <div class="table-responsive">
                <table class="table table-striped mb-0 align-middle">
                    <thead><tr><th>Name</th><th>Username</th><th>Email</th><th>Cohort</th><th>Progress</th><th>Status</th><th class="text-end">Actions</th></tr></thead>
                    <tbody>
                    <?php foreach ($students as $s):
                        $sid = htmlspecialchars((string) $s['id']);
                        $hasPw = student_has_password($s);
                        $prog = progress_for_student((string) $s['id']);
                        $sSeason = (string) ($s['cohort_season'] ?? '');
                        $sYear   = (int)    ($s['cohort_year']   ?? 0);
                    ?>
                        <tr>
                            <form method="post" id="editStu-<?= $sid ?>"></form>
                            <td>
                                <a href="/admin/student.php?id=<?= $sid ?>" class="fw-semibold text-decoration-none"><?= htmlspecialchars((string) $s['name']) ?></a>
                                <input form="editStu-<?= $sid ?>" name="name" type="hidden" value="<?= htmlspecialchars((string) $s['name']) ?>">
                            </td>
                            <td class="text-muted"><?= htmlspecialchars((string) ($s['username'] ?? '')) ?></td>
                            <td><input form="editStu-<?= $sid ?>" name="email" type="email" class="form-control form-control-sm" value="<?= htmlspecialchars((string) ($s['email'] ?? '')) ?>"></td>
                            <td>
                                <div class="d-flex gap-1">
                                    <select form="editStu-<?= $sid ?>" name="cohort_season" class="form-select form-select-sm" style="width:90px;">
                                        <?php foreach (COHORT_SEASONS as $season): ?>
                                            <option value="<?= $season ?>" <?= $sSeason === $season ? 'selected' : '' ?>><?= $season ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                    <input form="editStu-<?= $sid ?>" name="cohort_year" type="number" class="form-control form-control-sm" style="width:80px;" min="2000" max="2100" value="<?= $sYear ?: $defaultYear ?>">
                                </div>
                            </td>
                            <td class="small">
                                <div class="d-flex align-items-center gap-2">
                                    <div class="progress flex-grow-1" style="height:6px; min-width:100px;">
                                        <div class="progress-bar <?= (int) $prog['overall_percent'] >= 100 ? 'bg-success' : '' ?>" style="width: <?= (int) $prog['overall_percent'] ?>%"></div>
                                    </div>
                                    <span class="text-muted"><?= (int) $prog['overall_percent'] ?>%</span>
                                </div>
                                <div class="text-muted mt-1"><?= (int) $prog['total_cases_logged'] ?> cases</div>
                            </td>
                            <td class="small">
                                <?php if (!$hasPw): ?>
                                    <span class="badge bg-warning text-dark">Pending invite</span>
                                <?php elseif (!empty($s['last_login_at'])): ?>
                                    <span class="text-muted">last: <?= htmlspecialchars(substr((string) $s['last_login_at'], 0, 10)) ?></span>
                                <?php else: ?>
                                    <span class="text-muted">Never signed in</span>
                                <?php endif; ?>
                            </td>
                            <td class="text-end text-nowrap">
                                <input form="editStu-<?= $sid ?>" name="csrf" type="hidden" value="<?= htmlspecialchars(csrf_token()) ?>">
                                <input form="editStu-<?= $sid ?>" name="action" type="hidden" value="update">
                                <input form="editStu-<?= $sid ?>" name="id" type="hidden" value="<?= $sid ?>">
                                <button form="editStu-<?= $sid ?>" type="submit" class="btn btn-sm btn-outline-dark">Save</button>
                                <?php if (!$hasPw): ?>
                                    <form method="post" class="d-inline">
                                        <?php csrf_field(); ?>
                                        <input type="hidden" name="action" value="resend_invite">
                                        <input type="hidden" name="id" value="<?= $sid ?>">
                                        <button type="submit" class="btn btn-sm btn-outline-primary">Resend invite</button>
                                    </form>
                                <?php endif; ?>
                                <form method="post" class="d-inline" onsubmit="return confirm('Remove <?= htmlspecialchars($s['name']) ?>? Their case log will be deleted too — this can\'t be undone.')">
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
        <?php endif; ?>
    </div>
</div>
<?php require_once __DIR__ . '/../../includes/admin_footer.php'; ?>
