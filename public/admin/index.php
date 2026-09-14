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
require_once __DIR__ . '/../../includes/cohort_report.php';

$settings = app_settings();
$me = current_user();
$feedback = load_feedback();
$allStudents = load_students();
$pendingStudents = count(array_filter($allStudents, fn($s) => !student_has_password($s)));
$totalCases = count(load_cases());

$cohorts = all_cohorts();
// Selected cohort: from query string, defaulting to the most recent.
$cohortSel = trim((string) ($_GET['cohort'] ?? ''));
if ($cohortSel === '' && $cohorts !== []) $cohortSel = $cohorts[0]['label'];
$cohortAll = $cohortSel === '__all__';
if ($cohortAll) {
    $students = $allStudents;
    $currentLabel = 'All students';
} elseif ($cohortSel !== '') {
    $students = array_values(array_filter($allStudents, fn($s) => student_cohort_label($s) === $cohortSel));
    $currentLabel = $cohortSel;
} else {
    $students = [];
    $currentLabel = '';
}
$report = $students !== [] ? cohort_report($students) : null;

$pageTitle = 'Dashboard';
$activeTopNav = 'dashboard';
require_once __DIR__ . '/../../includes/admin_header.php';
?>
<div class="container py-4">
    <h1 class="h3 mb-2">Welcome, <?= htmlspecialchars((string) ($me['name'] ?? '')) ?></h1>

    <div class="row g-3 mb-4">
        <div class="col-md-4">
            <div class="card h-100 <?= $pendingStudents > 0 ? 'border-warning' : '' ?>">
                <div class="card-body">
                    <div class="text-muted small">Students</div>
                    <div class="h2 mb-0"><?= count($allStudents) ?></div>
                    <?php if ($pendingStudents > 0): ?>
                        <div class="small text-warning-emphasis"><?= $pendingStudents ?> pending invite<?= $pendingStudents === 1 ? '' : 's' ?></div>
                    <?php elseif ($totalCases > 0): ?>
                        <div class="small text-muted"><?= $totalCases ?> case<?= $totalCases === 1 ? '' : 's' ?> across all cohorts</div>
                    <?php endif; ?>
                    <a href="/admin/students.php" class="stretched-link"></a>
                </div>
            </div>
        </div>
        <div class="col-md-4">
            <div class="card h-100">
                <div class="card-body">
                    <div class="text-muted small">Change requests</div>
                    <div class="h2 mb-0"><?= count($feedback) ?></div>
                    <a href="/admin/feedback.php" class="stretched-link"></a>
                </div>
            </div>
        </div>
        <div class="col-md-4">
            <div class="card h-100">
                <div class="card-body">
                    <div class="text-muted small">Settings</div>
                    <div class="h5 mb-0 mt-1">Requirements, specialties, admins</div>
                    <a href="/admin/settings.php" class="stretched-link"></a>
                </div>
            </div>
        </div>
    </div>

    <?php if ($cohorts === []): ?>
        <div class="card">
            <div class="card-body">
                <h2 class="h5">Cohort report</h2>
                <p class="text-muted small mb-0">
                    No cohorts yet. Invite a student on the <a href="/admin/students.php">Students</a> page
                    to start tracking a cohort.
                </p>
            </div>
        </div>
    <?php else: ?>
        <div class="d-flex justify-content-between align-items-end flex-wrap gap-2 mb-2">
            <h2 class="h4 mb-0">Cohort: <?= htmlspecialchars($currentLabel) ?></h2>
            <div class="d-flex flex-wrap gap-1 small">
                <span class="text-muted align-self-center me-1">Switch:</span>
                <?php foreach ($cohorts as $c): ?>
                    <a href="?cohort=<?= urlencode($c['label']) ?>" class="btn btn-sm <?= $cohortSel === $c['label'] ? 'btn-dark' : 'btn-outline-dark' ?>">
                        <?= htmlspecialchars($c['label']) ?>
                    </a>
                <?php endforeach; ?>
                <a href="?cohort=__all__" class="btn btn-sm <?= $cohortAll ? 'btn-dark' : 'btn-outline-dark' ?>">All</a>
            </div>
        </div>

        <?php if ($report === null || $report['summary']['student_count'] === 0): ?>
            <div class="card"><div class="card-body text-muted small">No students in this cohort yet.</div></div>
        <?php else: $sm = $report['summary']; ?>
            <div class="row g-3 mb-3">
                <div class="col-6 col-md-3">
                    <div class="card h-100"><div class="card-body">
                        <div class="text-muted small">Students</div>
                        <div class="h3 mb-0"><?= (int) $sm['student_count'] ?></div>
                    </div></div>
                </div>
                <div class="col-6 col-md-3">
                    <div class="card h-100"><div class="card-body">
                        <div class="text-muted small">Cases logged</div>
                        <div class="h3 mb-0"><?= (int) $sm['total_cases'] ?></div>
                        <div class="small text-muted"><?= (int) $sm['avg_cases_per_student'] ?> avg/student</div>
                    </div></div>
                </div>
                <div class="col-6 col-md-3">
                    <div class="card h-100"><div class="card-body">
                        <div class="text-muted small">Avg completion</div>
                        <div class="h3 mb-0"><?= (int) $sm['avg_completion_percent'] ?>%</div>
                    </div></div>
                </div>
                <div class="col-6 col-md-3">
                    <div class="card h-100"><div class="card-body">
                        <div class="text-muted small">Fully met CST 7e</div>
                        <div class="h3 mb-0"><?= (int) $sm['fully_met_cst_count'] ?><span class="fs-6 text-muted">/<?= (int) $sm['student_count'] ?></span></div>
                        <?php if (!empty($report['requirement_stats'])): ?>
                            <div class="small text-muted"><?= (int) $sm['fully_met_reqs_count'] ?> also met all program reqs</div>
                        <?php endif; ?>
                    </div></div>
                </div>
            </div>

            <div class="card mb-3">
                <div class="card-header"><strong>Completion distribution</strong></div>
                <div class="card-body">
                    <?php
                        $maxBucket = 1;
                        foreach ($report['completion_buckets'] as $b) $maxBucket = max($maxBucket, (int) $b['count']);
                    ?>
                    <?php foreach ($report['completion_buckets'] as $b):
                        $w = $maxBucket > 0 ? ((int) $b['count'] / $maxBucket) * 100 : 0;
                        $barCls = $b['label'] === '100% (done)' ? 'bg-success' : ($b['label'] === 'Not started' ? 'bg-secondary' : 'bg-primary');
                    ?>
                        <div class="d-flex align-items-center gap-2 mb-2">
                            <div class="small text-muted text-end" style="width:110px;"><?= htmlspecialchars($b['label']) ?></div>
                            <div class="flex-grow-1">
                                <div class="progress" style="height:22px;">
                                    <div class="progress-bar <?= $barCls ?>" style="width: <?= $w ?>%;"></div>
                                </div>
                            </div>
                            <div class="fw-semibold" style="width:40px;"><?= (int) $b['count'] ?></div>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>

            <div class="row g-3 mb-3">
                <div class="col-lg-6">
                    <div class="card h-100">
                        <div class="card-header"><strong>CST 7e — % of cohort at 100%</strong></div>
                        <div class="card-body">
                            <?php foreach ($report['cst_meter_stats'] as $m):
                                $pct = (int) $m['met_percent'];
                                $barCls = $pct === 100 ? 'bg-success' : ($pct >= 50 ? 'bg-primary' : 'bg-warning');
                            ?>
                                <div class="mb-2">
                                    <div class="d-flex justify-content-between small">
                                        <span><?= htmlspecialchars($m['label']) ?> <span class="text-muted">(<?= (int) $m['target'] ?>)</span></span>
                                        <span class="text-muted"><?= (int) $m['met_count'] ?> / <?= (int) $m['total'] ?> · <?= $pct ?>%</span>
                                    </div>
                                    <div class="progress" style="height:8px;">
                                        <div class="progress-bar <?= $barCls ?>" style="width: <?= $pct ?>%;"></div>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                </div>
                <div class="col-lg-6">
                    <div class="card h-100">
                        <div class="card-header"><strong>Program requirements — % of cohort met</strong></div>
                        <div class="card-body">
                            <?php if ($report['requirement_stats'] === []): ?>
                                <p class="text-muted small mb-0">
                                    No program requirements defined yet. Add them under
                                    <a href="/admin/requirements.php">Settings → Requirements</a>.
                                </p>
                            <?php else: ?>
                                <?php foreach ($report['requirement_stats'] as $r):
                                    $pct = (int) $r['met_percent'];
                                    $barCls = $pct === 100 ? 'bg-success' : ($pct >= 50 ? 'bg-primary' : 'bg-warning');
                                ?>
                                    <div class="mb-2">
                                        <div class="d-flex justify-content-between small">
                                            <span><?= htmlspecialchars($r['label']) ?> <span class="text-muted">(<?= (int) $r['target'] ?>)</span></span>
                                            <span class="text-muted"><?= (int) $r['met_count'] ?> / <?= (int) $r['total'] ?> · <?= $pct ?>%</span>
                                        </div>
                                        <div class="progress" style="height:8px;">
                                            <div class="progress-bar <?= $barCls ?>" style="width: <?= $pct ?>%;"></div>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>

            <div class="card mb-3">
                <div class="card-header"><strong>Specialty coverage (total cases across cohort)</strong></div>
                <div class="card-body">
                    <?php
                        $maxSpec = 1;
                        foreach ($report['specialty_coverage'] as $c) $maxSpec = max($maxSpec, (int) $c['total']);
                        $anyCases = $maxSpec > 1 || (int) ($report['specialty_coverage'][0]['total'] ?? 0) > 0;
                    ?>
                    <?php if (!$anyCases): ?>
                        <p class="text-muted small mb-0">No cases logged yet in this cohort.</p>
                    <?php else: ?>
                        <?php foreach ($report['specialty_coverage'] as $c):
                            if ((int) $c['total'] === 0) continue;
                            $w = ((int) $c['total'] / $maxSpec) * 100;
                        ?>
                            <div class="d-flex align-items-center gap-2 mb-2">
                                <div class="small text-end" style="width:170px;">
                                    <?= htmlspecialchars((string) $c['specialty']['name']) ?>
                                    <?php if (!empty($c['specialty']['is_general'])): ?><span class="badge bg-light text-dark border small">gen</span><?php endif; ?>
                                </div>
                                <div class="flex-grow-1">
                                    <div class="progress" style="height:18px;">
                                        <div class="progress-bar bg-primary" style="width: <?= $w ?>%;"></div>
                                    </div>
                                </div>
                                <div class="small text-muted text-nowrap" style="width:170px;">
                                    <?= (int) $c['total'] ?> counted
                                    <span class="text-muted">(<?= (int) $c['first_scrub'] ?> 1st / <?= (int) $c['second_scrub'] ?> 2nd)</span>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
            </div>

            <div class="card mb-4">
                <div class="card-header"><strong>Roster</strong></div>
                <div class="table-responsive">
                    <table class="table table-sm table-striped mb-0 align-middle">
                        <thead>
                            <tr>
                                <th>Name</th>
                                <th>Cases</th>
                                <th style="width:250px;">Overall</th>
                                <th style="width:110px;">CST 7e</th>
                                <?php if ($report['requirement_stats'] !== []): ?>
                                    <th style="width:110px;">Program</th>
                                <?php endif; ?>
                            </tr>
                        </thead>
                        <tbody>
                        <?php
                            // Sort roster by overall percent desc so top performers surface.
                            $roster = $report['students'];
                            usort($roster, fn($a, $b) => (int) $b['progress']['overall_percent'] <=> (int) $a['progress']['overall_percent']);
                        ?>
                        <?php foreach ($roster as $row):
                            $s = $row['student'];
                            $pct = (int) $row['progress']['overall_percent'];
                            $sid = htmlspecialchars((string) $s['id']);
                            // How many CST meters met vs total
                            $cstMetN = 0; $cstTotal = count($row['progress']['meters']);
                            foreach ($row['progress']['meters'] as $m) if ((int) $m['have'] >= (int) $m['need']) $cstMetN++;
                            $cstAllMet = $cstMetN === $cstTotal && $row['progress']['distribution']['complete'];
                            // Program reqs met
                            $reqReport = requirements_for_student((string) $s['id']);
                            $reqMetN = 0; $reqTotal = count($reqReport);
                            foreach ($reqReport as $r) if ($r['met']) $reqMetN++;
                        ?>
                            <tr>
                                <td>
                                    <a href="/admin/student.php?id=<?= $sid ?>" class="fw-semibold text-decoration-none"><?= htmlspecialchars((string) $s['name']) ?></a>
                                    <?php if (!student_has_password($s)): ?>
                                        <span class="badge bg-warning text-dark ms-1">pending</span>
                                    <?php endif; ?>
                                </td>
                                <td class="text-muted small"><?= (int) $row['cases_count'] ?></td>
                                <td>
                                    <div class="d-flex align-items-center gap-2">
                                        <div class="progress flex-grow-1" style="height:6px;">
                                            <div class="progress-bar <?= $pct === 100 ? 'bg-success' : '' ?>" style="width: <?= $pct ?>%;"></div>
                                        </div>
                                        <span class="small text-muted" style="width:40px;"><?= $pct ?>%</span>
                                    </div>
                                </td>
                                <td class="small">
                                    <span class="badge <?= $cstAllMet ? 'bg-success' : 'bg-light text-dark border' ?>">
                                        <?= $cstMetN ?> / <?= $cstTotal ?>
                                    </span>
                                </td>
                                <?php if ($report['requirement_stats'] !== []): ?>
                                    <td class="small">
                                        <span class="badge <?= ($reqTotal > 0 && $reqMetN === $reqTotal) ? 'bg-success' : 'bg-light text-dark border' ?>">
                                            <?= $reqMetN ?> / <?= $reqTotal ?>
                                        </span>
                                    </td>
                                <?php endif; ?>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        <?php endif; ?>
    <?php endif; ?>
</div>
<?php require_once __DIR__ . '/../../includes/admin_footer.php'; ?>
