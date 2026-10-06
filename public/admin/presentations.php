<?php
declare(strict_types=1);
require_once __DIR__ . '/../../includes/bootstrap.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/presentations_service.php';
require_once __DIR__ . '/../../includes/students_service.php';
require_once __DIR__ . '/../../includes/presentation_ai_prompt.php';

require_login();

// Export a lesson as a downloadable .json (re-importable here or on another site).
if (isset($_GET['export'])) {
    $lesson = find_lesson((string) $_GET['export']);
    if ($lesson === null) { http_response_code(404); echo 'Not found'; exit; }
    header('Content-Type: application/json; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $lesson['id'] . '.json"');
    echo json_encode($lesson, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $action = (string) ($_POST['action'] ?? '');
    $message = '';
    $id = (string) ($_POST['id'] ?? '');
    if ($action === 'schedule') {
        $message = add_session([
            'lesson_id' => (string) ($_POST['lesson_id'] ?? ''),
            'date'      => (string) ($_POST['date'] ?? ''),
            'label'     => (string) ($_POST['label'] ?? ''),
        ]) ? 'Scheduled.' : 'Could not schedule — pick a lesson and a date.';
    } elseif ($action === 'session_reset') {
        // reset_at (ms) lets the presenter discard browser copies saved before the reset.
        update_session($id, ['state' => null, 'result' => null, 'reset_at' => (int) (microtime(true) * 1000)]);
        $message = 'Session reset to the beginning.';
    } elseif ($action === 'session_delete') {
        delete_session($id);
        $message = 'Session removed.';
    } elseif ($action === 'lesson_create') {
        $lesson = create_lesson([
            'title'  => (string) ($_POST['title'] ?? ''),
            'type'   => (string) ($_POST['type'] ?? ''),
            'course' => (string) ($_POST['course'] ?? ''),
        ]);
        if ($lesson) { header('Location: /admin/presentation_edit.php?id=' . urlencode($lesson['id'])); exit; }
        $message = 'Could not create — a title is required.';
    } elseif ($action === 'lesson_import') {
        $raw = (string) ($_POST['json'] ?? '');
        if (!empty($_FILES['file']['tmp_name']) && is_uploaded_file($_FILES['file']['tmp_name'])) {
            $raw = (string) file_get_contents($_FILES['file']['tmp_name']);
        }
        $data = json_decode($raw, true);
        if (!is_array($data)) {
            $message = 'Import failed: that isn\'t valid JSON.';
        } else {
            [$lesson, $errors] = import_lesson($data);
            $message = $lesson ? 'Imported "' . $lesson['title'] . '".' : 'Import failed: ' . implode(' ', array_slice($errors, 0, 3));
        }
    } elseif ($action === 'lesson_duplicate') {
        $copy = duplicate_lesson($id);
        $message = $copy ? 'Copied — edit "' . $copy['title'] . '" to make this year\'s version.' : 'Could not copy.';
    } elseif ($action === 'lesson_delete') {
        $message = delete_lesson($id) ? 'Lesson deleted (a backup copy was kept).' : 'Could not delete.';
    }
    header('Location: /admin/presentations.php?msg=' . urlencode($message));
    exit;
}

$lessons = load_lessons();
$lessonsById = array_column($lessons, null, 'id');
$sessions = load_sessions();
$today = date('Y-m-d');
$todaySessions    = array_filter($sessions, fn($s) => $s['date'] === $today);
$upcomingSessions = array_filter($sessions, fn($s) => $s['date'] > $today);
$pastSessions     = array_reverse(array_filter($sessions, fn($s) => $s['date'] < $today));
$cohortLabels = array_column(all_cohorts(), 'label');
$types = presentation_types();
$msg = (string) ($_GET['msg'] ?? '');

function session_status_html(array $s, ?array $lesson): string
{
    if (!empty($s['result'])) {
        $r = $s['result'];
        return '<span class="badge text-bg-success">Finished</span> <span class="small">'
            . (int) ($r['score'] ?? 0) . ' pts' . (!empty($r['band']) ? ' · ' . htmlspecialchars((string) $r['band']) : '') . '</span>';
    }
    if (!empty($s['state']['node'])) {
        $node = $lesson['content']['nodes'][$s['state']['node']] ?? [];
        $where = trim(($node['round'] ?? '') . ' ' . ($node['title'] ?? ''));
        return '<span class="badge text-bg-warning">In progress</span> <span class="small text-muted">' . htmlspecialchars($where) . '</span>';
    }
    return '<span class="badge text-bg-light border">Not started</span>';
}

function session_rows(array $list, array $lessonsById, bool $highlight = false): void
{
    foreach ($list as $s):
        $lesson = $lessonsById[$s['lesson_id']] ?? null; ?>
        <tr class="<?= $highlight ? 'table-warning' : '' ?>">
            <td class="text-nowrap"><?= htmlspecialchars(date('D M j, Y', strtotime((string) $s['date']))) ?></td>
            <td><?= $lesson ? htmlspecialchars((string) $lesson['title']) : '<span class="text-muted">(deleted lesson)</span>' ?>
                <?php if (($s['label'] ?? '') !== ''): ?><div class="small text-muted"><?= htmlspecialchars((string) $s['label']) ?></div><?php endif; ?></td>
            <td><?= session_status_html($s, $lesson) ?></td>
            <td class="text-end text-nowrap">
                <?php if ($lesson): ?>
                    <a class="btn btn-sm btn-dark" href="/admin/present.php?session=<?= urlencode($s['id']) ?>" target="_blank">▶ Present</a>
                <?php endif; ?>
                <form method="post" class="d-inline" onsubmit="return confirm('Reset this session back to the beginning?');">
                    <?php csrf_field(); ?><input type="hidden" name="action" value="session_reset"><input type="hidden" name="id" value="<?= htmlspecialchars($s['id']) ?>">
                    <button class="btn btn-sm btn-outline-secondary" type="submit">Reset</button>
                </form>
                <form method="post" class="d-inline" onsubmit="return confirm('Remove this session?');">
                    <?php csrf_field(); ?><input type="hidden" name="action" value="session_delete"><input type="hidden" name="id" value="<?= htmlspecialchars($s['id']) ?>">
                    <button class="btn btn-sm btn-outline-danger" type="submit">✕</button>
                </form>
            </td>
        </tr>
    <?php endforeach;
}

$pageTitle = 'Presentations';
$activeTopNav = 'presentations';
require_once __DIR__ . '/../../includes/admin_header.php';
?>
<div class="container py-3">
    <h1 class="h4 mb-1">Presentations</h1>
    <p class="text-muted small">
        <strong>Lessons</strong> are reusable from year to year. <strong>Schedule</strong> a lesson for a class day,
        then hit <strong>Present</strong> — it opens full screen for the projector and remembers where you are.
    </p>

    <?php if ($msg !== ''): ?>
        <div class="alert alert-info alert-dismissible fade show"><?= htmlspecialchars($msg) ?><button type="button" class="btn-close" data-bs-dismiss="alert"></button></div>
    <?php endif; ?>

    <div class="card mb-4">
        <div class="card-header d-flex justify-content-between align-items-center">
            <strong>Scheduled</strong>
        </div>
        <div class="card-body border-bottom">
            <form method="post" class="row g-2 align-items-end">
                <?php csrf_field(); ?>
                <input type="hidden" name="action" value="schedule">
                <div class="col-md-5">
                    <label class="form-label small mb-1">Lesson</label>
                    <select class="form-select" name="lesson_id" required>
                        <?php foreach ($lessons as $l): ?>
                            <option value="<?= htmlspecialchars($l['id']) ?>"><?= htmlspecialchars((string) $l['title']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-6 col-md-2">
                    <label class="form-label small mb-1">Date</label>
                    <input class="form-control" type="date" name="date" value="<?= htmlspecialchars($today) ?>" required>
                </div>
                <div class="col-6 col-md-3">
                    <label class="form-label small mb-1">Class <span class="text-muted">(optional)</span></label>
                    <input class="form-control" type="text" name="label" list="cohort-labels" placeholder="e.g. Fall 2026" autocomplete="off">
                    <datalist id="cohort-labels"><?php foreach ($cohortLabels as $c): ?><option value="<?= htmlspecialchars($c) ?>"><?php endforeach; ?></datalist>
                </div>
                <div class="col-md-2 d-grid"><button class="btn btn-dark" type="submit">Schedule</button></div>
            </form>
        </div>
        <?php if ($sessions === []): ?>
            <div class="card-body text-muted small">Nothing scheduled yet.</div>
        <?php else: ?>
            <div class="table-responsive">
                <table class="table mb-0 align-middle">
                    <tbody>
                    <?php if ($todaySessions): ?>
                        <tr class="table-light"><th colspan="4" class="small text-uppercase">Today</th></tr>
                        <?php session_rows($todaySessions, $lessonsById, true); ?>
                    <?php endif; ?>
                    <?php if ($upcomingSessions): ?>
                        <tr class="table-light"><th colspan="4" class="small text-uppercase">Upcoming</th></tr>
                        <?php session_rows($upcomingSessions, $lessonsById); ?>
                    <?php endif; ?>
                    <?php if ($pastSessions): ?>
                        <tr class="table-light"><th colspan="4" class="small text-uppercase">Past</th></tr>
                        <?php session_rows($pastSessions, $lessonsById); ?>
                    <?php endif; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>

    <div class="card mb-4 border-primary-subtle">
        <div class="card-header bg-primary-subtle">
            <a class="text-decoration-none text-reset d-flex justify-content-between" data-bs-toggle="collapse" href="#chatgptSteps">
                <strong>💡 Want a new lesson or game? Plan it with ChatGPT</strong><span>▾</span>
            </a>
        </div>
        <div class="collapse show" id="chatgptSteps"><div class="card-body">
            <ol class="mb-0 ps-3">
                <li class="mb-3">
                    <strong>Copy the starter prompt</strong> and paste it into a <a href="https://chatgpt.com/" target="_blank" rel="noopener">new ChatGPT chat ↗</a>.
                    It explains how this site works so ChatGPT designs something Jeff can build.
                    <div class="mt-1 d-flex gap-2 flex-wrap">
                        <button class="btn btn-sm btn-dark" type="button" data-copy="#chatgptPrompt">📋 Copy starter prompt</button>
                        <button class="btn btn-sm btn-link" type="button" data-bs-toggle="collapse" data-bs-target="#chatgptPromptWrap">Show it</button>
                    </div>
                    <div class="collapse mt-2" id="chatgptPromptWrap">
                        <textarea id="chatgptPrompt" class="form-control font-monospace small" rows="12" readonly><?= htmlspecialchars(presentation_chatgpt_prompt()) ?></textarea>
                    </div>
                </li>
                <li class="mb-3">
                    <strong>Talk it through.</strong> Tell ChatGPT the topic, the unit/objectives, and the kind of experience you want.
                    Paste in slide content if it helps. ChatGPT will ask questions and propose an outline. Go back and forth until you love it.
                </li>
                <li class="mb-3">
                    <strong>When it's final, send ChatGPT exactly this:</strong>
                    <div class="mt-1 d-flex gap-2 align-items-center flex-wrap">
                        <code class="fs-6 bg-light border rounded px-2 py-1" id="chatgptFinalize"><?= htmlspecialchars(PRESENTATION_FINALIZE_PHRASE) ?></code>
                        <button class="btn btn-sm btn-outline-dark" type="button" data-copy="#chatgptFinalize">📋 Copy</button>
                    </div>
                    <div class="small text-muted mt-1">It replies with one gray code box. Use that box's <em>Copy</em> button (top-right corner of the box).</div>
                </li>
                <li>
                    <strong>Send it to Jeff:</strong>
                    <a class="btn btn-sm btn-dark ms-1" href="/admin/feedback.php?title=<?= urlencode('New lesson: ') ?>&page=<?= urlencode('Presentations') ?>#new-request">Create the request →</a>
                    <div class="small text-muted mt-1">Finish the title, then paste ChatGPT's answer into the description and hit Send.</div>
                </li>
            </ol>
        </div></div>
    </div>

    <div class="card mb-4">
        <div class="card-header d-flex justify-content-between align-items-center flex-wrap gap-2">
            <strong>Lesson library <small class="text-muted">(<?= count($lessons) ?>)</small></strong>
            <div class="d-flex gap-2">
                <button class="btn btn-sm btn-outline-dark" type="button" data-bs-toggle="collapse" data-bs-target="#importLesson">Import</button>
                <button class="btn btn-sm btn-dark" type="button" data-bs-toggle="collapse" data-bs-target="#newLesson">+ New lesson</button>
            </div>
        </div>
        <div class="collapse" id="newLesson"><div class="card-body border-bottom">
            <form method="post" class="row g-2 align-items-end">
                <?php csrf_field(); ?>
                <input type="hidden" name="action" value="lesson_create">
                <div class="col-md-4"><label class="form-label small mb-1">Title</label><input class="form-control" name="title" required></div>
                <div class="col-md-3"><label class="form-label small mb-1">Course / unit</label><input class="form-control" name="course"></div>
                <div class="col-md-3"><label class="form-label small mb-1">Type</label>
                    <select class="form-select" name="type">
                        <?php foreach ($types as $key => $t): ?><option value="<?= htmlspecialchars($key) ?>"><?= htmlspecialchars($t['label']) ?></option><?php endforeach; ?>
                    </select></div>
                <div class="col-md-2 d-grid"><button class="btn btn-dark" type="submit">Create</button></div>
            </form>
        </div></div>
        <div class="collapse" id="importLesson"><div class="card-body border-bottom">
            <form method="post" enctype="multipart/form-data">
                <?php csrf_field(); ?>
                <input type="hidden" name="action" value="lesson_import">
                <p class="small text-muted mb-2">Upload a lesson <code>.json</code> (from Export) or paste its contents. Imports always become a new lesson — nothing is overwritten.</p>
                <input class="form-control mb-2" type="file" name="file" accept=".json,application/json">
                <textarea class="form-control font-monospace small mb-2" name="json" rows="4" placeholder="…or paste JSON here"></textarea>
                <button class="btn btn-dark btn-sm" type="submit">Import</button>
            </form>
        </div></div>
        <?php if ($lessons === []): ?>
            <div class="card-body text-muted small">No lessons yet.</div>
        <?php else: ?>
            <div class="table-responsive">
                <table class="table table-striped mb-0 align-middle">
                    <thead><tr><th>Lesson</th><th>Type</th><th>Last edited</th><th class="text-end">Actions</th></tr></thead>
                    <tbody>
                    <?php foreach ($lessons as $l): ?>
                        <tr>
                            <td>
                                <a href="/admin/presentation_edit.php?id=<?= urlencode($l['id']) ?>" class="fw-semibold text-decoration-none"><?= htmlspecialchars((string) $l['title']) ?></a>
                                <?php if (($l['course'] ?? '') !== ''): ?><div class="small text-muted"><?= htmlspecialchars((string) $l['course']) ?></div><?php endif; ?>
                            </td>
                            <td class="small"><?= htmlspecialchars($types[$l['type']]['label'] ?? (string) $l['type']) ?></td>
                            <td class="small text-nowrap"><?= htmlspecialchars(date('M j, Y', strtotime((string) ($l['updated_at'] ?? 'now')))) ?></td>
                            <td class="text-end text-nowrap">
                                <a class="btn btn-sm btn-outline-dark" href="/admin/present.php?lesson=<?= urlencode($l['id']) ?>" target="_blank" title="Practice run — not tied to a class">Rehearse</a>
                                <a class="btn btn-sm btn-outline-dark" href="/admin/presentation_edit.php?id=<?= urlencode($l['id']) ?>">Edit</a>
                                <div class="btn-group">
                                    <button class="btn btn-sm btn-outline-secondary dropdown-toggle" type="button" data-bs-toggle="dropdown">More</button>
                                    <ul class="dropdown-menu dropdown-menu-end">
                                        <li><a class="dropdown-item" href="?export=<?= urlencode($l['id']) ?>">Export .json</a></li>
                                        <li><form method="post"><?php csrf_field(); ?><input type="hidden" name="action" value="lesson_duplicate"><input type="hidden" name="id" value="<?= htmlspecialchars($l['id']) ?>"><button class="dropdown-item" type="submit" data-no-spinner>Duplicate</button></form></li>
                                        <li><hr class="dropdown-divider"></li>
                                        <li><form method="post" onsubmit="return confirm('Delete this lesson? Scheduled sessions for it will stop working.');"><?php csrf_field(); ?><input type="hidden" name="action" value="lesson_delete"><input type="hidden" name="id" value="<?= htmlspecialchars($l['id']) ?>"><button class="dropdown-item text-danger" type="submit" data-no-spinner>Delete</button></form></li>
                                    </ul>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>
</div>
<?php
$extraScripts = <<<'JS'
<script>
// Copy buttons: data-copy="#selector" copies that element's value/text.
document.addEventListener('click', async (e) => {
    const btn = e.target.closest('[data-copy]');
    if (!btn) return;
    const src = document.querySelector(btn.dataset.copy);
    const text = 'value' in src ? src.value : src.textContent;
    try {
        await navigator.clipboard.writeText(text);
    } catch (err) {
        // Older browsers / non-HTTPS: select-and-copy fallback.
        const t = document.createElement('textarea');
        t.value = text; document.body.appendChild(t); t.select();
        document.execCommand('copy'); t.remove();
    }
    const orig = btn.innerHTML;
    btn.innerHTML = '✓ Copied!';
    setTimeout(() => { btn.innerHTML = orig; }, 1800);
});
</script>
JS;
require_once __DIR__ . '/../../includes/admin_footer.php';
?>
