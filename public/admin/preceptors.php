<?php
declare(strict_types=1);
require_once __DIR__ . '/../../includes/bootstrap.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/preceptors_service.php';
require_once __DIR__ . '/../../includes/cases_service.php';
require_once __DIR__ . '/../../includes/students_service.php';

require_login();

$message = '';
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $action = (string) ($_POST['action'] ?? '');
    if ($action === 'add') {
        $name = trim((string) ($_POST['name'] ?? ''));
        if ($name === '') $error = 'Name is required.';
        elseif (find_preceptor_by_name($name)) $error = 'A preceptor with that name already exists.';
        else {
            find_or_create_preceptor_by_name($name);
            $message = 'Added ' . $name . '.';
        }
    } elseif ($action === 'update') {
        $id = (string) ($_POST['id'] ?? '');
        update_preceptor($id, ['name' => (string) ($_POST['name'] ?? '')]);
        $message = 'Renamed.';
    } elseif ($action === 'delete') {
        $id = (string) ($_POST['id'] ?? '');
        if (preceptor_case_count($id) > 0) {
            $error = "Can't delete — this preceptor is on one or more logged cases. Rename or leave in place.";
        } else {
            delete_preceptor($id);
            $message = 'Removed.';
        }
    }
    header('Location: /admin/preceptors.php?msg=' . urlencode($message ?: $error));
    exit;
}

// Cohort scope — see admin/doctors.php for the same reasoning: keep
// year-over-year ratings separate so recognition candidates don't
// inherit last cohort's numbers.
$cohorts = all_cohorts();
$cohortSel = trim((string) ($_GET['cohort'] ?? ''));
if ($cohortSel === '' && $cohorts !== []) $cohortSel = $cohorts[0]['label'];
$cohortAll = $cohortSel === '__all__';
if ($cohortAll) {
    $filteredCases = load_cases();
    $scopeLabel = 'All cohorts';
    $scopeQuery = 'cohort=__all__';
} elseif ($cohortSel !== '') {
    $filteredCases = cases_for_cohort($cohortSel);
    $scopeLabel = $cohortSel;
    $scopeQuery = 'cohort=' . urlencode($cohortSel);
} else {
    $filteredCases = load_cases();
    $scopeLabel = 'All cohorts';
    $scopeQuery = 'cohort=__all__';
}

$counts  = case_count_by_person($filteredCases, 'preceptor_id');
$ratings = rating_stats_by_person($filteredCases, 'preceptor_rating', 'preceptor_id');
$preceptors = load_preceptors();

usort($preceptors, function ($a, $b) use ($counts, $ratings) {
    $ra = $ratings[(string) $a['id']] ?? ['avg' => 0.0, 'count' => 0];
    $rb = $ratings[(string) $b['id']] ?? ['avg' => 0.0, 'count' => 0];
    $keyA = $ra['count'] > 0 ? $ra['avg'] : -1;
    $keyB = $rb['count'] > 0 ? $rb['avg'] : -1;
    if ($keyA !== $keyB) return $keyB <=> $keyA;
    $ca = $counts[(string) $a['id']] ?? 0;
    $cb = $counts[(string) $b['id']] ?? 0;
    if ($ca !== $cb) return $cb <=> $ca;
    return strcasecmp((string) ($a['name'] ?? ''), (string) ($b['name'] ?? ''));
});

$msg = (string) ($_GET['msg'] ?? '');
$pageTitle = 'Preceptors';
$activeTopNav = 'settings';
require_once __DIR__ . '/../../includes/admin_header.php';
?>
<div class="container py-3">
<div class="row g-4">
<div class="col-md-3"><?= admin_subnav_html('settings', 'preceptors') ?></div>
<div class="col-md-9">
    <h1 class="h4 mb-3">Preceptors</h1>
    <p class="text-muted small">
        Supervising CSTs. Auto-populated when students type new names; rename here to normalize.
        Ratings + case counts below reflect the <strong>selected cohort</strong> only, so
        year-over-year data stays separate.
    </p>

    <?php if ($cohorts !== []): ?>
        <div class="mb-3 d-flex flex-wrap gap-2 small align-items-center">
            <span class="text-muted">Cohort:</span>
            <?php foreach ($cohorts as $c): ?>
                <a href="?cohort=<?= urlencode($c['label']) ?>" class="btn btn-sm <?= $cohortSel === $c['label'] ? 'btn-dark' : 'btn-outline-dark' ?>">
                    <?= htmlspecialchars($c['label']) ?>
                </a>
            <?php endforeach; ?>
            <a href="?cohort=__all__" class="btn btn-sm <?= $cohortAll ? 'btn-dark' : 'btn-outline-dark' ?>">All time</a>
        </div>
    <?php endif; ?>

    <div class="mb-3 d-flex gap-2 flex-wrap align-items-center">
        <a href="/admin/preceptor_ratings_export.php?<?= htmlspecialchars($scopeQuery) ?>" class="btn btn-sm btn-outline-dark">
            ⬇ Export ratings + comments (CSV)
        </a>
        <span class="small text-muted">Scope: <strong><?= htmlspecialchars($scopeLabel) ?></strong></span>
    </div>

    <?php if ($msg !== ''): ?>
        <div class="alert alert-info alert-dismissible fade show"><?= htmlspecialchars($msg) ?><button type="button" class="btn-close" data-bs-dismiss="alert"></button></div>
    <?php endif; ?>

    <div class="card mb-3">
        <div class="card-header"><strong>Add preceptor</strong></div>
        <div class="card-body">
            <form method="post" class="row g-2 align-items-end">
                <?php csrf_field(); ?>
                <input type="hidden" name="action" value="add">
                <div class="col-md-9"><label class="form-label">Name</label><input class="form-control" type="text" name="name" required placeholder="e.g. CST Mike Smith"></div>
                <div class="col-md-3 d-grid"><button type="submit" class="btn btn-dark">Add</button></div>
            </form>
        </div>
    </div>

    <div class="card">
        <div class="card-header"><strong>All preceptors</strong> <small class="text-muted">(<?= count($preceptors) ?>)</small></div>
        <?php if ($preceptors === []): ?>
            <div class="card-body text-muted">No preceptors yet — the list fills up as students log cases.</div>
        <?php else: ?>
            <div class="table-responsive">
                <table class="table table-striped mb-0 align-middle">
                    <thead><tr><th>Name</th><th style="width:150px;">Avg rating</th><th style="width:110px;">Cases</th><th class="text-end">Actions</th></tr></thead>
                    <tbody>
                    <?php foreach ($preceptors as $p):
                        $pid = htmlspecialchars((string) $p['id']);
                        $n = (int) ($counts[(string) $p['id']] ?? 0);
                        $r = $ratings[(string) $p['id']];
                    ?>
                        <tr>
                            <form method="post" id="editPre-<?= $pid ?>"></form>
                            <td><input form="editPre-<?= $pid ?>" name="name" class="form-control form-control-sm" value="<?= htmlspecialchars((string) $p['name']) ?>" required></td>
                            <td class="small">
                                <?php if ($r['count'] > 0): ?>
                                    <span style="color:#f0a500;">★</span> <strong><?= number_format($r['avg'], 2) ?></strong>
                                    <span class="text-muted">from <?= (int) $r['count'] ?></span>
                                <?php else: ?>
                                    <span class="text-muted">no ratings</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <span class="badge <?= $n > 0 ? 'bg-primary' : 'bg-light text-dark border' ?>"><?= $n ?></span>
                            </td>
                            <td class="text-end text-nowrap">
                                <input form="editPre-<?= $pid ?>" name="csrf" type="hidden" value="<?= htmlspecialchars(csrf_token()) ?>">
                                <input form="editPre-<?= $pid ?>" name="action" type="hidden" value="update">
                                <input form="editPre-<?= $pid ?>" name="id" type="hidden" value="<?= $pid ?>">
                                <button form="editPre-<?= $pid ?>" type="submit" class="btn btn-sm btn-outline-dark">Save</button>
                                <form method="post" class="d-inline" onsubmit="return confirm('Remove <?= htmlspecialchars($p['name']) ?>?')">
                                    <?php csrf_field(); ?>
                                    <input type="hidden" name="action" value="delete">
                                    <input type="hidden" name="id" value="<?= $pid ?>">
                                    <button type="submit" class="btn btn-sm btn-outline-danger" <?= $n > 0 ? 'title="In use — can\'t delete"' : '' ?>>Remove</button>
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
