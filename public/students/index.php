<?php
declare(strict_types=1);
require_once __DIR__ . '/../../includes/bootstrap.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/student_auth.php';
require_once __DIR__ . '/../../includes/case_progress.php';

require_student();

$student = current_student();
$progress = progress_for_student((string) $student['id']);

$pageTitle = 'Progress';
$activeNav = 'progress';
require_once __DIR__ . '/../../includes/student_header.php';

/**
 * Render one progress wheel. Uses SVG stroke-dashoffset so the fill
 * animates when the page loads — pure CSS, no library.
 */
function wheel_html(int $have, int $need, string $labelLine): string
{
    $pct = $need > 0 ? min(100, ($have / $need) * 100) : 0;
    $circumference = 2 * M_PI * 50; // radius 50 (viewBox 0 0 120 120)
    $offset = $circumference * (1 - $pct / 100);
    $classes = $have >= $need ? 'wheel complete' : 'wheel';
    return sprintf(
        '<div class="d-flex flex-column align-items-center">
            <div class="%s">
                <svg viewBox="0 0 120 120">
                    <circle class="track" cx="60" cy="60" r="50"></circle>
                    <circle class="fill"  cx="60" cy="60" r="50"
                            stroke-dasharray="%f" stroke-dashoffset="%f" stroke-linecap="round"></circle>
                </svg>
                <div class="wheel-label">
                    <div class="wheel-num">%d<span style="font-size:.9rem; color:#94a3b8; font-weight:500;">/%d</span></div>
                    <div class="wheel-need">%s</div>
                </div>
            </div>
            <div class="wheel-caption">%s</div>
        </div>',
        htmlspecialchars($classes),
        $circumference,
        $offset,
        $have, $need,
        $have >= $need ? '✓ done' : 'to go: ' . ($need - $have),
        htmlspecialchars($labelLine)
    );
}
?>
<div class="container py-4">
    <div class="d-flex justify-content-between align-items-end flex-wrap gap-2 mb-3">
        <div>
            <h1 class="h3 mb-1">Hi, <?= htmlspecialchars((string) $student['name']) ?></h1>
            <div class="text-muted">
                <?= (int) $progress['total_cases_logged'] ?> case<?= (int) $progress['total_cases_logged'] === 1 ? '' : 's' ?> logged
                <?php if ((int) $progress['observer'] > 0): ?>
                    <span class="small">(<?= (int) $progress['observer'] ?> as observer — not counted toward requirements)</span>
                <?php endif; ?>
                · Overall: <strong><?= (int) $progress['overall_percent'] ?>%</strong>
            </div>
        </div>
        <a href="/students/cases.php?add=1" class="btn btn-scrub">+ Log a case</a>
    </div>

    <?php
        // Group meters for display
        $groups = ['Overall' => [], 'First scrub' => [], 'Additional' => []];
        foreach ($progress['meters'] as $m) $groups[$m['group']][] = $m;
    ?>
    <?php foreach ($groups as $groupName => $meters): ?>
        <div class="card mb-3">
            <div class="card-header"><strong><?= htmlspecialchars($groupName) ?></strong></div>
            <div class="card-body">
                <div class="d-flex flex-wrap justify-content-center gap-4">
                    <?php foreach ($meters as $m): ?>
                        <?= wheel_html((int) $m['have'], (int) $m['need'], (string) $m['label']) ?>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>
    <?php endforeach; ?>

    <div class="card mb-3">
        <div class="card-header d-flex justify-content-between align-items-center flex-wrap gap-2">
            <strong>Specialty distribution</strong>
            <?php $d = $progress['distribution']; ?>
            <span class="badge <?= $d['complete'] ? 'bg-success' : 'bg-warning text-dark' ?>">
                <?= (int) $d['qualifying_count'] ?> of <?= (int) $d['need_count'] ?> specialties at <?= (int) $d['per_specialty'] ?>+ first-scrub cases
            </span>
        </div>
        <div class="card-body">
            <p class="small text-muted mb-3">
                The CST rule: at least <?= (int) $d['need_count'] ?> specialties with at least
                <?= (int) $d['per_specialty'] ?> first-scrub cases each. The remaining first-scrub
                specialty cases can be in any specialty.
            </p>
            <div class="row g-2">
                <?php foreach ($progress['specialties'] as $s):
                    $pct = min(100, (int) round(($s['first_scrub'] / max(1, (int) $d['per_specialty'])) * 100));
                ?>
                    <div class="col-12 col-md-6">
                        <div class="d-flex justify-content-between align-items-center">
                            <span class="small"><?= htmlspecialchars($s['name']) ?></span>
                            <span class="small text-muted"><?= (int) $s['first_scrub'] ?> / <?= (int) $d['per_specialty'] ?> <?= $s['qualifies'] ? '✓' : '' ?></span>
                        </div>
                        <div class="progress" style="height:6px;">
                            <div class="progress-bar <?= $s['qualifies'] ? 'bg-success' : '' ?>" style="width: <?= $pct ?>%;"></div>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>
    </div>
</div>

<script>
// Animate wheel fills after paint so they visibly draw in.
(() => {
    document.querySelectorAll('.wheel .fill').forEach(el => {
        const finalOffset = el.getAttribute('stroke-dashoffset');
        const circ = parseFloat(el.getAttribute('stroke-dasharray'));
        el.setAttribute('stroke-dashoffset', String(circ));
        requestAnimationFrame(() => requestAnimationFrame(() => {
            el.setAttribute('stroke-dashoffset', finalOffset);
        }));
    });
})();
</script>
<?php require_once __DIR__ . '/../../includes/student_footer.php'; ?>
