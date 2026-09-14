<?php
declare(strict_types=1);
require_once __DIR__ . '/../../includes/bootstrap.php';
require_once __DIR__ . '/../../includes/auth.php';

if (no_users_yet()) {
    header('Location: /admin/setup.php');
    exit;
}

require_login();
require_once __DIR__ . '/../../includes/feedback_service.php';
require_once __DIR__ . '/../../includes/students_service.php';
require_once __DIR__ . '/../../includes/cases_service.php';

$settings = app_settings();
$me = current_user();
$feedback = load_feedback();
$students = load_students();
$pendingStudents = count(array_filter($students, fn($s) => !student_has_password($s)));
$totalCases = count(load_cases());

$pageTitle = 'Dashboard';
$activeTopNav = 'dashboard';
require_once __DIR__ . '/../../includes/admin_header.php';
?>
<div class="container py-4">
    <h1 class="h3 mb-2">Welcome, <?= htmlspecialchars((string) ($me['name'] ?? '')) ?></h1>
    <p class="text-muted mb-4">This is your playground. Fresh, quiet — ready for whatever you want to build here.</p>

    <div class="row g-3">
        <div class="col-md-6 col-lg-4">
            <div class="card h-100 <?= $pendingStudents > 0 ? 'border-warning' : '' ?>">
                <div class="card-body">
                    <div class="text-muted small">Students</div>
                    <div class="h2 mb-0"><?= count($students) ?></div>
                    <?php if ($pendingStudents > 0): ?>
                        <div class="small text-warning-emphasis"><?= $pendingStudents ?> pending invite<?= $pendingStudents === 1 ? '' : 's' ?></div>
                    <?php elseif ($totalCases > 0): ?>
                        <div class="small text-muted"><?= $totalCases ?> case<?= $totalCases === 1 ? '' : 's' ?> logged</div>
                    <?php endif; ?>
                    <a href="/admin/students.php" class="stretched-link"></a>
                </div>
            </div>
        </div>
        <div class="col-md-6 col-lg-4">
            <div class="card h-100">
                <div class="card-body">
                    <div class="text-muted small">Change requests sent</div>
                    <div class="h2 mb-0"><?= count($feedback) ?></div>
                    <a href="/admin/feedback.php" class="stretched-link"></a>
                </div>
            </div>
        </div>
        <div class="col-md-6 col-lg-4">
            <div class="card h-100">
                <div class="card-body">
                    <div class="text-muted small">Settings</div>
                    <div class="h5 mb-0 mt-1">Notifications, specialties, admins</div>
                    <a href="/admin/settings.php" class="stretched-link"></a>
                </div>
            </div>
        </div>
    </div>

    <div class="card mt-4">
        <div class="card-header"><strong>How this works</strong></div>
        <div class="card-body">
            <p class="mb-2">
                Use <a href="/admin/feedback.php">Change requests</a> to describe anything you want added or changed
                — a new page, a new tool, a color tweak, whatever. Each one becomes a GitHub issue Jeff sees and can act on.
                Status flows back here, so you'll know when it's under review, done, or set aside.
            </p>
            <p class="small text-muted mb-0">
                If you're not sure what to build first, that's fine — just describe what you wish existed. We'll figure it out from there.
            </p>
        </div>
    </div>
</div>
<?php require_once __DIR__ . '/../../includes/admin_footer.php'; ?>
