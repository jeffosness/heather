<?php
declare(strict_types=1);
require_once __DIR__ . '/../../includes/bootstrap.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/students_service.php';
require_once __DIR__ . '/../../includes/cases_service.php';
require_once __DIR__ . '/../../includes/specialties_service.php';
require_once __DIR__ . '/../../includes/case_progress.php';
require_once __DIR__ . '/../../includes/requirements_service.php';

require_login();

$id = (string) ($_GET['id'] ?? '');
$student = $id !== '' ? find_student($id) : null;
if (!$student) {
    header('Location: /admin/students.php');
    exit;
}

$prog        = progress_for_student((string) $student['id']);
$reqReport   = requirements_for_student((string) $student['id']);
$cases       = cases_for_student((string) $student['id']);
$specialties = load_specialties();
$specById = [];
foreach ($specialties as $s) $specById[(string) $s['id']] = $s;
$doctorById    = doctors_by_id();
$preceptorById = preceptors_by_id();

// Per-specialty breakdown table: total, first_scrub, second_scrub for each specialty.
$breakdown = [];
foreach ($specialties as $s) {
    $breakdown[(string) $s['id']] = [
        'specialty'    => $s,
        'total'        => 0,
        'first_scrub'  => 0,
        'second_scrub' => 0,
        'observer'     => 0,
    ];
}
foreach ($cases as $c) {
    $sid = (string) ($c['specialty_id'] ?? '');
    if (!isset($breakdown[$sid])) continue;
    $role = (string) ($c['role'] ?? '');
    if ($role === 'observer') $breakdown[$sid]['observer']++;
    else {
        $breakdown[$sid]['total']++;
        if ($role === 'first_scrub')  $breakdown[$sid]['first_scrub']++;
        if ($role === 'second_scrub') $breakdown[$sid]['second_scrub']++;
    }
}
// Sort breakdown by total desc so busiest specialties float up
usort($breakdown, fn($a, $b) => $b['total'] <=> $a['total']);

$pageTitle = (string) $student['name'];
$activeTopNav = 'students';
require_once __DIR__ . '/../../includes/admin_header.php';

function mini_wheel(int $have, int $need, string $label): string
{
    $pct = $need > 0 ? min(100, ($have / $need) * 100) : 0;
    $done = $have >= $need;
    $barCls = $done ? 'bg-success' : 'bg-primary';
    return sprintf(
        '<div class="mb-2">
            <div class="d-flex justify-content-between small">
                <span>%s</span>
                <span class="text-muted">%d / %d %s</span>
            </div>
            <div class="progress" style="height:6px;">
                <div class="progress-bar %s" style="width:%f%%;"></div>
            </div>
        </div>',
        htmlspecialchars($label), $have, $need, $done ? '✓' : '', $barCls, $pct
    );
}
?>
<div class="container py-3" style="max-width: 1100px;">
    <a href="/admin/students.php" class="text-muted small">← All students</a>
    <div class="d-flex justify-content-between align-items-start flex-wrap gap-2 mt-1 mb-3">
        <div>
            <h1 class="h3 mb-1"><?= htmlspecialchars((string) $student['name']) ?></h1>
            <div class="text-muted small">
                <?= htmlspecialchars((string) ($student['username'] ?? '')) ?>
                · <?= htmlspecialchars((string) ($student['email'] ?? '')) ?>
                <?php $c = student_cohort_label($student); if ($c !== ''): ?>
                    · <span class="badge bg-light text-dark border"><?= htmlspecialchars($c) ?></span>
                <?php endif; ?>
                <?php if (!student_has_password($student)): ?>
                    · <span class="badge bg-warning text-dark">Pending invite</span>
                <?php elseif (!empty($student['last_login_at'])): ?>
                    · last login <?= htmlspecialchars(substr((string) $student['last_login_at'], 0, 10)) ?>
                <?php endif; ?>
            </div>
        </div>
        <div class="text-end">
            <div class="h2 mb-0"><?= (int) $prog['overall_percent'] ?>%</div>
            <div class="small text-muted"><?= (int) $prog['total_cases_logged'] ?> case<?= (int) $prog['total_cases_logged'] === 1 ? '' : 's' ?> logged</div>
        </div>
    </div>

    <div class="row g-3 mb-3">
        <div class="col-md-6">
            <div class="card h-100">
                <div class="card-header"><strong>CST 7e minimums</strong></div>
                <div class="card-body">
                    <?php foreach ($prog['meters'] as $m): ?>
                        <?= mini_wheel((int) $m['have'], (int) $m['need'], (string) $m['label']) ?>
                    <?php endforeach; ?>
                    <hr>
                    <div class="small text-muted">
                        Specialty distribution:
                        <strong><?= (int) $prog['distribution']['qualifying_count'] ?></strong>
                        of <?= (int) $prog['distribution']['need_count'] ?> specialties at
                        <?= (int) $prog['distribution']['per_specialty'] ?>+ first-scrub cases
                        <?= $prog['distribution']['complete'] ? '<span class="text-success">✓</span>' : '' ?>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-md-6">
            <div class="card h-100">
                <div class="card-header d-flex justify-content-between align-items-center">
                    <strong>Program requirements</strong>
                    <?php if ($reqReport !== []):
                        $metCount = 0; foreach ($reqReport as $r) if ($r['met']) $metCount++;
                    ?>
                        <span class="badge <?= $metCount === count($reqReport) ? 'bg-success' : 'bg-warning text-dark' ?>">
                            <?= $metCount ?> / <?= count($reqReport) ?> met
                        </span>
                    <?php endif; ?>
                </div>
                <div class="card-body">
                    <?php if ($reqReport === []): ?>
                        <p class="text-muted small mb-0">
                            No program-specific requirements defined yet. Add them under
                            <a href="/admin/requirements.php">Settings → Requirements</a>.
                        </p>
                    <?php else: ?>
                        <?php foreach ($reqReport as $r): ?>
                            <?= mini_wheel((int) $r['have'], (int) $r['need'], $r['label']) ?>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>

    <div class="card mb-3">
        <div class="card-header"><strong>Specialty breakdown</strong></div>
        <div class="table-responsive">
            <table class="table table-sm mb-0 align-middle">
                <thead>
                    <tr>
                        <th>Specialty</th>
                        <th class="text-end" style="width:110px;">First scrub</th>
                        <th class="text-end" style="width:110px;">Second scrub</th>
                        <th class="text-end" style="width:100px;">Total counted</th>
                        <th class="text-end" style="width:100px;">Observer</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($breakdown as $row):
                    $sp = $row['specialty'];
                    $isEmpty = $row['total'] + $row['observer'] === 0;
                    if ($isEmpty) continue;
                ?>
                    <tr>
                        <td>
                            <?= htmlspecialchars((string) $sp['name']) ?>
                            <?php if (!empty($sp['is_general'])): ?>
                                <span class="badge bg-light text-dark border small">general</span>
                            <?php endif; ?>
                        </td>
                        <td class="text-end"><?= (int) $row['first_scrub'] ?></td>
                        <td class="text-end"><?= (int) $row['second_scrub'] ?></td>
                        <td class="text-end fw-semibold"><?= (int) $row['total'] ?></td>
                        <td class="text-end text-muted"><?= (int) $row['observer'] ?></td>
                    </tr>
                <?php endforeach; ?>
                <?php
                    $anyEmpty = false;
                    foreach ($breakdown as $row) if ($row['total'] + $row['observer'] === 0) { $anyEmpty = true; break; }
                    if ($anyEmpty):
                ?>
                    <tr>
                        <td colspan="5" class="text-muted small fst-italic">
                            Not shown: specialties with zero cases logged yet.
                        </td>
                    </tr>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>

    <div class="card mb-4">
        <div class="card-header d-flex justify-content-between align-items-center">
            <strong>Recent cases</strong>
            <span class="small text-muted">Last 15 of <?= count($cases) ?></span>
        </div>
        <?php if ($cases === []): ?>
            <div class="card-body text-muted">No cases logged yet.</div>
        <?php else: ?>
            <div class="table-responsive">
                <table class="table table-sm mb-0 align-middle">
                    <thead>
                        <tr>
                            <th>Date</th>
                            <th>Procedure</th>
                            <th>Specialty</th>
                            <th>Role</th>
                            <th>Doctor</th>
                            <th>Preceptor</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach (array_slice($cases, 0, 15) as $c):
                        $sp = $specById[(string) ($c['specialty_id'] ?? '')] ?? null;
                        $dName = ($doctorById[(string) ($c['doctor_id']    ?? '')]['name'] ?? '');
                        $pName = ($preceptorById[(string) ($c['preceptor_id'] ?? '')]['name'] ?? '');
                        $dRating = (int) ($c['doctor_rating']    ?? 0);
                        $pRating = (int) ($c['preceptor_rating'] ?? 0);
                    ?>
                        <tr>
                            <td class="small text-muted"><?= htmlspecialchars((string) $c['case_date']) ?></td>
                            <td><?= htmlspecialchars((string) $c['procedure']) ?></td>
                            <td class="small"><?= $sp ? htmlspecialchars((string) $sp['name']) : '<span class="text-danger">unset</span>' ?></td>
                            <td class="small"><span class="badge bg-light text-dark border"><?= htmlspecialchars(role_label((string) $c['role'])) ?></span></td>
                            <td class="small text-muted">
                                <?= htmlspecialchars($dName) ?>
                                <?php if ($dRating > 0): ?>
                                    <span style="color:#f0a500;" title="<?= $dRating ?> stars">
                                        <?= str_repeat('★', $dRating) ?><?= str_repeat('☆', 5 - $dRating) ?>
                                    </span>
                                <?php endif; ?>
                            </td>
                            <td class="small text-muted">
                                <?= htmlspecialchars($pName) ?>
                                <?php if ($pRating > 0): ?>
                                    <span style="color:#f0a500;" title="<?= $pRating ?> stars">
                                        <?= str_repeat('★', $pRating) ?><?= str_repeat('☆', 5 - $pRating) ?>
                                    </span>
                                <?php endif; ?>
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
