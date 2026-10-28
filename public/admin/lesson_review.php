<?php
declare(strict_types=1);
require_once __DIR__ . '/../../includes/bootstrap.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/lesson_reviews_service.php';

require_login();

$id = (string) ($_GET['id'] ?? '');
$review = find_lesson_review($id);
if (!$review) { header('Location: /admin/'); exit; }

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $answers = lesson_review_answers_from_post($review, $_POST, lesson_review_answers($id));
    $msg = 'Saved. Come back any time to finish.';
    if (($_POST['action'] ?? '') === 'send') {
        $me = current_user();
        $sent = github_add_issue_comment((int) ($review['issue'] ?? 0),
            lesson_review_comment_markdown($review, $answers, (string) ($me['name'] ?? 'Heather')));
        if ($sent) {
            $answers['sent_at'] = date('Y-m-d H:i:s');
            $msg = 'Sent to Jeff, thank you! You can still change answers and send again.';
        } else {
            $msg = "Saved, but sending didn't go through. Jeff will still see your answers.";
        }
    }
    save_lesson_review_answers($id, $answers);
    header('Location: /admin/lesson_review.php?id=' . urlencode($id) . '&msg=' . urlencode($msg));
    exit;
}

$answers = lesson_review_answers($id);
$msg = (string) ($_GET['msg'] ?? '');

/** **bold**, *italic*, paragraphs; everything escaped first. */
function lr_md(string $s): string
{
    $h = htmlspecialchars($s);
    $h = preg_replace('/\*\*(.+?)\*\*/s', '<strong>$1</strong>', $h);
    $h = preg_replace('/\*(.+?)\*/s', '<em>$1</em>', $h);
    return '<p>' . str_replace("\n\n", '</p><p>', $h) . '</p>';
}

$field = preg_replace('/[^a-z0-9_-]/', '', (string) ($review['field'] ?? ''));
$pageTitle = 'Review: ' . $review['title'];
$activeTopNav = 'presentations';
$extraHead = <<<HTML
<style>
    .lr-field svg { width: 100%; height: auto; display: block; border-radius: 10px; }
    .lr-field-cap { font-size: .75rem; text-transform: uppercase; letter-spacing: .05em; color: #6c757d; margin: .25rem 0 .35rem; }
    .lr-tool svg { width: 100%; max-width: 120px; height: auto; display: block; margin: 0 auto; }
    .lr-choice { border: 2px solid #dee2e6; border-radius: 10px; padding: .5rem; height: 100%; background: #fff; }
    .lr-choice.right { border-color: #4a9d5b; background: #f1f8f2; }
    .lr-choice .lr-label { font-weight: 600; font-size: .9rem; text-align: center; margin-top: .25rem; }
    .lr-choice .lr-why { font-size: .8rem; color: #555; margin-top: .25rem; }
    .lr-badge { font-size: .7rem; text-transform: uppercase; letter-spacing: .04em; }
    .lr-confirm { background: #fff8e1; border-left: 4px solid #f0b429; border-radius: 6px; padding: .5rem .75rem; font-size: .88rem; }
    .lr-stage { font-size: .78rem; color: var(--plum); font-weight: 600; }
    .lr-q { font-size: 1.05rem; font-weight: 700; color: var(--char); }
    .lr-bar { position: sticky; bottom: 0; background: rgba(244,240,234,.96); border-top: 1px solid #ddd; z-index: 10; }
    #lr-watch-field svg { max-width: 380px; margin: 0 auto; }
</style>
HTML;
$extraScripts = '<script src="/admin/assets/present/instruments.js"></script>'
    . ($field !== '' ? '<script src="/admin/assets/present/fields/' . $field . '.js"></script>' : '');
require_once __DIR__ . '/../../includes/admin_header.php';
$steps = $review['steps'] ?? [];
?>
<div class="container py-4" style="max-width: 1000px;">
    <div class="small text-muted mb-1">Lesson draft for your review</div>
    <h1 class="h3 mb-3"><?= htmlspecialchars((string) $review['title']) ?></h1>

    <?php if ($msg !== ''): ?>
        <div class="alert alert-success alert-dismissible fade show"><?= htmlspecialchars($msg) ?><button type="button" class="btn-close" data-bs-dismiss="alert"></button></div>
    <?php elseif (!empty($answers['sent_at'])): ?>
        <div class="alert alert-success">✓ You sent this back on <?= htmlspecialchars(date('M j, g:ia', strtotime((string) $answers['sent_at']))) ?>. Feel free to change anything and send again.</div>
    <?php endif; ?>

    <div class="card mb-3"><div class="card-body"><?= lr_md((string) ($review['intro'] ?? '')) ?></div></div>

    <div class="card mb-3">
        <div class="card-body text-center">
            <h2 class="h5 text-start">Watch the whole case</h2>
            <p class="small text-muted text-start">How the picture changes as students get each step right.</p>
            <div id="lr-watch-field" class="lr-field mb-2"></div>
            <div id="lr-watch-cap" class="fw-semibold mb-2"></div>
            <div class="d-flex justify-content-center gap-2">
                <button type="button" class="btn btn-outline-dark" id="lr-prev">‹ Back</button>
                <button type="button" class="btn btn-dark" id="lr-play">▶ Play</button>
                <button type="button" class="btn btn-outline-dark" id="lr-next">Next ›</button>
            </div>
        </div>
    </div>

    <?php if (!empty($review['outline_notes'])): ?>
        <div class="card mb-4">
            <div class="card-body">
                <h2 class="h5">A few things about the outline</h2>
                <ul class="mb-0">
                    <?php foreach ($review['outline_notes'] as $n): ?><li class="mb-1"><?= substr(lr_md((string) $n), 3, -4) ?></li><?php endforeach; ?>
                </ul>
            </div>
        </div>
    <?php endif; ?>

    <form method="post">
        <?php csrf_field(); ?>

        <h2 class="h4 mb-3">The steps</h2>
        <?php foreach ($steps as $i => $s): $sid = (string) $s['id']; $ans = $answers['steps'][$sid] ?? []; $v = (string) ($ans['verdict'] ?? ''); ?>
            <div class="card mb-3" id="step-<?= htmlspecialchars($sid) ?>">
                <div class="card-body">
                    <div class="lr-stage"><?= htmlspecialchars((string) $s['stage']) ?></div>
                    <h3 class="h5 mb-3"><?= $i + 1 ?>. <?= htmlspecialchars((string) $s['title']) ?></h3>
                    <div class="row g-3">
                        <div class="col-md-4">
                            <div class="row g-2">
                                <div class="col-6 col-md-12">
                                    <div class="lr-field-cap">Students see</div>
                                    <div class="lr-field" data-stage="<?= htmlspecialchars((string) $s['field']) ?>"></div>
                                </div>
                                <div class="col-6 col-md-12">
                                    <div class="lr-field-cap">After they get it right</div>
                                    <div class="lr-field" data-stage="<?= htmlspecialchars((string) $s['field_after']) ?>"></div>
                                    <div class="small text-muted mt-1"><?= htmlspecialchars((string) $s['after_text']) ?></div>
                                </div>
                            </div>
                        </div>
                        <div class="col-md-8">
                            <p class="mb-1"><?= htmlspecialchars((string) $s['sees']) ?></p>
                            <?php if (($s['cue'] ?? '') !== ''): ?><p class="mb-2"><span class="text-muted">Surgeon:</span> <em><?= htmlspecialchars((string) $s['cue']) ?></em></p><?php endif; ?>
                            <div class="lr-q mb-2">What happens next?</div>
                            <div class="row g-2 mb-2">
                                <div class="col-6 col-lg-3">
                                    <div class="lr-choice right">
                                        <div class="lr-badge text-success text-center">✓ Proposed answer</div>
                                        <?php foreach ($s['pass'] as $p): ?>
                                            <div class="lr-tool" data-tool="<?= htmlspecialchars((string) $p['tool']) ?>"></div>
                                            <div class="lr-label"><?= htmlspecialchars((string) $p['label']) ?></div>
                                        <?php endforeach; ?>
                                        <div class="lr-why"><?= htmlspecialchars((string) $s['action']) ?></div>
                                    </div>
                                </div>
                                <?php foreach ($s['wrong'] as $w): ?>
                                    <div class="col-6 col-lg-3">
                                        <div class="lr-choice">
                                            <div class="lr-badge text-danger text-center">✗ Wrong choice</div>
                                            <div class="lr-tool" data-tool="<?= htmlspecialchars((string) $w['tool']) ?>"></div>
                                            <div class="lr-label"><?= htmlspecialchars((string) $w['label']) ?></div>
                                            <div class="lr-why"><?= htmlspecialchars((string) $w['why']) ?></div>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                            <p class="small mb-1"><strong>Why:</strong> <?= htmlspecialchars((string) $s['why_right']) ?></p>
                            <p class="small text-muted mb-2"><strong>Also accept:</strong> <?= htmlspecialchars((string) $s['alternatives']) ?></p>
                            <?php if (!empty($s['confirm'])): ?>
                                <div class="lr-confirm mb-2">
                                    <strong>Instructor confirmation needed:</strong>
                                    <ul class="mb-0 ps-3"><?php foreach ($s['confirm'] as $c): ?><li><?= htmlspecialchars((string) $c) ?></li><?php endforeach; ?></ul>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>
                    <hr>
                    <div class="d-flex flex-wrap gap-2 align-items-center">
                        <input type="radio" class="btn-check" name="verdict[<?= htmlspecialchars($sid) ?>]" id="ok-<?= htmlspecialchars($sid) ?>" value="ok" <?= $v === 'ok' ? 'checked' : '' ?>>
                        <label class="btn btn-outline-success" for="ok-<?= htmlspecialchars($sid) ?>">👍 Looks right</label>
                        <input type="radio" class="btn-check" name="verdict[<?= htmlspecialchars($sid) ?>]" id="ch-<?= htmlspecialchars($sid) ?>" value="change" <?= $v === 'change' ? 'checked' : '' ?>>
                        <label class="btn btn-outline-warning" for="ch-<?= htmlspecialchars($sid) ?>">✏️ Needs a change</label>
                        <input type="text" class="form-control flex-grow-1" style="min-width: 220px; flex-basis: 260px;" name="note[<?= htmlspecialchars($sid) ?>]"
                               value="<?= htmlspecialchars((string) ($ans['note'] ?? '')) ?>" placeholder="Note (optional), e.g. &quot;we call these Senn rakes&quot;">
                    </div>
                </div>
            </div>
        <?php endforeach; ?>

        <h2 class="h4 mt-4 mb-3" id="questions">Questions for you</h2>
        <div class="card mb-3">
            <div class="card-body">
                <?php foreach ($review['questions'] ?? [] as $qi => $q): $qid = (string) $q['id']; $qa = $answers['questions'][$qid] ?? []; ?>
                    <div class="<?= $qi > 0 ? 'border-top pt-3' : '' ?> mb-3">
                        <div class="fw-semibold mb-2"><?= htmlspecialchars((string) $q['text']) ?></div>
                        <div class="d-flex flex-wrap gap-2 mb-2">
                            <?php foreach ($q['options'] as $oi => $opt): $oid = 'q-' . $qid . '-' . $oi; ?>
                                <input type="radio" class="btn-check" name="q[<?= htmlspecialchars($qid) ?>]" id="<?= htmlspecialchars($oid) ?>" value="<?= htmlspecialchars((string) $opt) ?>" <?= ($qa['choice'] ?? '') === $opt ? 'checked' : '' ?>>
                                <label class="btn btn-outline-dark btn-sm" for="<?= htmlspecialchars($oid) ?>"><?= htmlspecialchars((string) $opt) ?></label>
                            <?php endforeach; ?>
                        </div>
                        <input type="text" class="form-control form-control-sm" name="q_other[<?= htmlspecialchars($qid) ?>]" value="<?= htmlspecialchars((string) ($qa['other'] ?? '')) ?>" placeholder="Something else / a note (optional)">
                    </div>
                <?php endforeach; ?>
                <div class="border-top pt-3">
                    <div class="fw-semibold mb-2">Anything else?</div>
                    <textarea class="form-control" name="general" rows="3" placeholder="Optional"><?= htmlspecialchars((string) ($answers['general'] ?? '')) ?></textarea>
                </div>
            </div>
        </div>

        <div class="lr-bar py-2">
            <div class="d-flex justify-content-end gap-2">
                <button type="submit" name="action" value="save" class="btn btn-outline-dark">Save for later</button>
                <button type="submit" name="action" value="send" class="btn btn-dark">Send to Jeff ✓</button>
            </div>
        </div>
    </form>
</div>
<script>
document.addEventListener('DOMContentLoaded', () => {
    Instruments.paint();
    const draw = window.ProcedureFields && ProcedureFields[<?= json_encode($field) ?>];
    if (!draw) return;
    document.querySelectorAll('.lr-field[data-stage]').forEach((n) => { n.innerHTML = draw(n.dataset.stage); });

    // "Watch the whole case": steps through each step's after-picture.
    const frames = [{ stage: 'prepped', cap: 'Start: prepped and draped' }].concat(
        <?= json_encode(array_map(fn($s, $i) => ['stage' => $s['field_after'], 'cap' => ($i + 1) . '. ' . $s['title']], $steps, array_keys($steps)), JSON_UNESCAPED_UNICODE) ?>);
    const box = document.getElementById('lr-watch-field'), cap = document.getElementById('lr-watch-cap');
    const playBtn = document.getElementById('lr-play');
    let at = 0, timer = null;
    const show = (i) => { at = (i + frames.length) % frames.length; box.innerHTML = draw(frames[at].stage); cap.textContent = frames[at].cap; };
    const stop = () => { clearInterval(timer); timer = null; playBtn.textContent = '▶ Play'; };
    document.getElementById('lr-prev').onclick = () => { stop(); show(at - 1); };
    document.getElementById('lr-next').onclick = () => { stop(); show(at + 1); };
    playBtn.onclick = () => {
        if (timer) return stop();
        playBtn.textContent = '❚❚ Pause';
        timer = setInterval(() => { if (at === frames.length - 1) return stop(); show(at + 1); }, 1400);
    };
    show(0);
});
</script>
<?php require_once __DIR__ . '/../../includes/admin_footer.php'; ?>
