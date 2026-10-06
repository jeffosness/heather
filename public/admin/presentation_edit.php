<?php
declare(strict_types=1);
require_once __DIR__ . '/../../includes/bootstrap.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/presentations_service.php';

require_login();

$id = (string) ($_GET['id'] ?? $_POST['id'] ?? '');
$lesson = find_lesson($id);
if ($lesson === null) {
    header('Location: /admin/presentations.php?msg=' . urlencode('Lesson not found.'));
    exit;
}

$errors = [];
$draftJson = null; // re-shown when a save is rejected so nothing typed is lost

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $action = (string) ($_POST['action'] ?? '');
    if ($action === 'restore') {
        $ok = restore_lesson_version($id, (string) ($_POST['file'] ?? ''));
        header('Location: /admin/presentation_edit.php?id=' . urlencode($id) . '&msg=' . urlencode($ok ? 'Restored that version (the current one was backed up first).' : 'Could not restore.'));
        exit;
    }
    if ($action === 'apply_seed') {
        $ok = apply_seed_update($id);
        header('Location: /admin/presentation_edit.php?id=' . urlencode($id) . '&msg=' . urlencode($ok ? 'Updated to the newest built-in version. Your previous version is under Previous versions.' : 'Could not update.'));
        exit;
    }
    if ($action === 'save') {
        $draftJson = (string) ($_POST['content'] ?? '');
        $content = json_decode($draftJson, true);
        if (!is_array($content)) {
            $errors[] = 'Content isn\'t valid JSON: ' . json_last_error_msg() . '. Nothing was saved.';
        } else {
            [$errors] = validate_presentation_content((string) $lesson['type'], $content);
        }
        $title = trim((string) ($_POST['title'] ?? ''));
        if ($title === '') $errors[] = 'Title is required.';
        if ($errors === []) {
            $lesson['title']       = $title;
            $lesson['course']      = trim((string) ($_POST['course'] ?? ''));
            $lesson['description'] = trim((string) ($_POST['description'] ?? ''));
            $lesson['content']     = $content;
            save_lesson($lesson);
            header('Location: /admin/presentation_edit.php?id=' . urlencode($id) . '&msg=' . urlencode('Saved.'));
            exit;
        }
        // Keep what she typed in the details fields too.
        $lesson['title'] = $title;
        $lesson['course'] = trim((string) ($_POST['course'] ?? ''));
        $lesson['description'] = trim((string) ($_POST['description'] ?? ''));
    }
}

$type = presentation_type((string) $lesson['type']);
[, $warnings] = validate_presentation_content((string) $lesson['type'], $lesson['content'] ?? null);
$outline = presentation_outline((string) $lesson['type'], $lesson['content'] ?? null);
$history = lesson_history($id);
$contentJson = $draftJson ?? json_encode($lesson['content'] ?? new stdClass(), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
$msg = (string) ($_GET['msg'] ?? '');

$pageTitle = 'Edit · ' . (string) $lesson['title'];
$activeTopNav = 'presentations';
require_once __DIR__ . '/../../includes/admin_header.php';
?>
<div class="container-fluid px-lg-4 py-3">
    <div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-2">
        <div>
            <a href="/admin/presentations.php" class="small text-decoration-none">← Presentations</a>
            <h1 class="h4 mb-0"><?= htmlspecialchars((string) $lesson['title']) ?></h1>
            <div class="small text-muted"><?= htmlspecialchars($type['label'] ?? (string) $lesson['type']) ?></div>
        </div>
        <div class="d-flex gap-2">
            <a class="btn btn-outline-dark" href="/admin/present.php?lesson=<?= urlencode($id) ?>" target="_blank">Rehearse ▶</a>
            <a class="btn btn-outline-secondary" href="/admin/presentations.php?export=<?= urlencode($id) ?>">Export</a>
        </div>
    </div>

    <?php if ($msg !== ''): ?>
        <div class="alert alert-info alert-dismissible fade show"><?= htmlspecialchars($msg) ?><button type="button" class="btn-close" data-bs-dismiss="alert"></button></div>
    <?php endif; ?>
    <?php if (lesson_seed_update_available($lesson)): ?>
        <div class="alert alert-primary d-flex justify-content-between align-items-center flex-wrap gap-2">
            <span><strong>A newer built-in version of this lesson is available.</strong> You've edited this one, so it wasn't updated automatically.</span>
            <form method="post" onsubmit="return confirm('Replace this lesson with the newest built-in version? Your current version is kept under Previous versions.');">
                <?php csrf_field(); ?>
                <input type="hidden" name="action" value="apply_seed">
                <input type="hidden" name="id" value="<?= htmlspecialchars($id) ?>">
                <button class="btn btn-sm btn-primary" type="submit">Update to the new version</button>
            </form>
        </div>
    <?php endif; ?>
    <?php if ($errors): ?>
        <div class="alert alert-danger"><strong>Not saved — fix these first:</strong><ul class="mb-0">
            <?php foreach ($errors as $e): ?><li><?= htmlspecialchars($e) ?></li><?php endforeach; ?>
        </ul></div>
    <?php elseif ($warnings): ?>
        <div class="alert alert-warning"><strong>Heads up:</strong><ul class="mb-0">
            <?php foreach ($warnings as $w): ?><li><?= htmlspecialchars($w) ?></li><?php endforeach; ?>
        </ul></div>
    <?php endif; ?>

    <div class="row g-4">
        <div class="col-lg-8">
            <form method="post">
                <?php csrf_field(); ?>
                <input type="hidden" name="action" value="save">
                <input type="hidden" name="id" value="<?= htmlspecialchars($id) ?>">
                <div class="card mb-3"><div class="card-body row g-2">
                    <div class="col-md-6"><label class="form-label small mb-1">Title</label><input class="form-control" name="title" value="<?= htmlspecialchars((string) $lesson['title']) ?>" required></div>
                    <div class="col-md-6"><label class="form-label small mb-1">Course / unit</label><input class="form-control" name="course" value="<?= htmlspecialchars((string) ($lesson['course'] ?? '')) ?>"></div>
                    <div class="col-12"><label class="form-label small mb-1">Description</label><textarea class="form-control" name="description" rows="2"><?= htmlspecialchars((string) ($lesson['description'] ?? '')) ?></textarea></div>
                </div></div>
                <div class="card">
                    <div class="card-header d-flex justify-content-between align-items-center">
                        <strong>Content</strong>
                        <span class="small text-muted">Every save keeps a backup of the previous version.</span>
                    </div>
                    <textarea class="form-control font-monospace border-0 rounded-0" name="content" rows="32" spellcheck="false" style="font-size:13px; tab-size:4;"><?= htmlspecialchars((string) $contentJson) ?></textarea>
                    <div class="card-footer d-flex justify-content-end"><button class="btn btn-dark" type="submit">Save</button></div>
                </div>
            </form>
        </div>

        <div class="col-lg-4">
            <div class="card mb-3">
                <div class="card-header"><strong>Outline</strong> <small class="text-muted">(<?= count($outline) ?> slides)</small></div>
                <ul class="list-group list-group-flush small">
                    <?php foreach ($outline as $row): ?>
                        <li class="list-group-item">
                            <div class="d-flex justify-content-between gap-2">
                                <span><code><?= htmlspecialchars($row['id']) ?></code><?= $row['start'] ? ' <span class="badge text-bg-dark">start</span>' : '' ?></span>
                                <span class="badge text-bg-light border"><?= htmlspecialchars($row['kind']) ?></span>
                            </div>
                            <?php if ($row['title'] !== ''): ?><div><?= htmlspecialchars($row['title']) ?></div><?php endif; ?>
                            <?php foreach ($row['links'] as [$label, $to]): ?>
                                <div class="text-muted">↳ <?= htmlspecialchars((string) $label) ?> → <code><?= htmlspecialchars((string) $to) ?></code></div>
                            <?php endforeach; ?>
                        </li>
                    <?php endforeach; ?>
                </ul>
            </div>

            <?php if ($lesson['type'] === 'adventure'): ?>
            <div class="card mb-3">
                <div class="card-header"><a class="text-decoration-none text-reset" data-bs-toggle="collapse" href="#kindRef"><strong>Slide kinds — quick reference ▾</strong></a></div>
                <div class="collapse" id="kindRef"><div class="card-body small">
                    <p class="mb-2">Every slide can have <code>round</code>, <code>eyebrow</code>, <code>time</code>, <code>title</code>, <code>body</code>, <code>prompt</code>, <code>notes</code> (only you see these), <code>tone</code> (<code>danger</code> / <code>success</code> / <code>shake</code>), and <code>enter</code>: <code>{"points": -5, "skull": true}</code>.</p>
                    <ul class="ps-3 mb-2">
                        <li><code>scene</code> — text + Continue → <code>next</code></li>
                        <li><code>choice</code> — <code>options</code>: <code>key</code>, <code>text</code>, <code>points</code>, <code>feedback</code>, <code>next</code>, optional <code>what_if</code> (shown when exploring other answers after the first pick, not scored)</li>
                        <li><code>chance</code> — die roll: <code>sides</code>, <code>outcomes</code>: <code>min</code>, <code>max</code>, <code>title</code>, <code>text</code>, <code>points</code>, <code>next</code></li>
                        <li><code>activity</code> — <code>timer</code> (seconds), <code>teams</code>, <code>cards</code> + <code>printable</code>, <code>reveal</code>: <code>{title, body}</code> or <code>{title, steps: [...]}</code> (one per R press), <code>timer_sound</code> (<code>full</code> / <code>end</code> / <code>off</code>), <code>per_team: true</code> (one turn per team), <code>sortable: true</code> (drag cards to rank) → <code>next</code></li>
                        <li><code>ending</code> — final score + matching <code>scoring.bands</code></li>
                    </ul>
                    <p class="mb-0">Text: <code>**bold**</code>, <code>*italic*</code>, lines starting <code>- </code> become bullets, blank line = new paragraph.</p>
                </div></div>
            </div>
            <?php endif; ?>

            <div class="card">
                <div class="card-header"><strong>Previous versions</strong></div>
                <?php if ($history === []): ?>
                    <div class="card-body small text-muted">None yet — they appear here after each save.</div>
                <?php else: ?>
                    <ul class="list-group list-group-flush small">
                        <?php foreach (array_slice($history, 0, 25) as $h): ?>
                            <li class="list-group-item d-flex justify-content-between align-items-center">
                                <span><?= htmlspecialchars(date('M j, Y g:ia', strtotime($h['saved_at']))) ?><?= str_starts_with($h['file'], 'deleted-') ? ' <span class="text-muted">(before delete)</span>' : '' ?></span>
                                <form method="post" onsubmit="return confirm('Restore this version? The current version is backed up first.');">
                                    <?php csrf_field(); ?>
                                    <input type="hidden" name="action" value="restore">
                                    <input type="hidden" name="id" value="<?= htmlspecialchars($id) ?>">
                                    <input type="hidden" name="file" value="<?= htmlspecialchars($h['file']) ?>">
                                    <button class="btn btn-sm btn-outline-secondary" type="submit">Restore</button>
                                </form>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>
<?php require_once __DIR__ . '/../../includes/admin_footer.php'; ?>
