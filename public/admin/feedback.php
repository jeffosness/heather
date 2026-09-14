<?php
declare(strict_types=1);
require_once __DIR__ . '/../../includes/bootstrap.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/feedback_service.php';

require_login();

$me = current_user();
$myName = (string) ($me['name'] ?? 'Admin');

$message = '';
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $action = (string) ($_POST['action'] ?? '');
    if ($action === 'add') {
        $created = add_feedback([
            'title' => (string) ($_POST['title'] ?? ''),
            'body'  => (string) ($_POST['body']  ?? ''),
            'page'  => (string) ($_POST['page']  ?? ''),
        ], $myName);
        if (!$created) {
            $error = 'Please add both a short title and a description.';
        } elseif (!empty($created['github_issue_number'])) {
            $message = 'Sent — Jeff has been notified (issue #' . (int) $created['github_issue_number'] . ').';
        } elseif (($created['local_status'] ?? '') === 'send_failed') {
            $message = 'Saved locally. GitHub notification failed — Jeff will still see it next time he reviews.';
        } else {
            $message = 'Saved locally.';
        }
    }
    header('Location: /admin/feedback.php?msg=' . urlencode($message ?: $error));
    exit;
}

$viewId = (string) ($_GET['id'] ?? '');
$msg = (string) ($_GET['msg'] ?? '');

if ($viewId !== '') {
    // ----- Detail view -----
    $item = find_feedback($viewId);
    if (!$item) { header('Location: /admin/feedback.php'); exit; }

    $ghIssue = null;
    $ghComments = [];
    if (!empty($item['github_issue_number'])) {
        $n = (int) $item['github_issue_number'];
        $ghIssue = github_fetch_issue($n);
        $ghComments = github_fetch_issue_comments($n);
    }
    $statusInfo = feedback_status_label($ghIssue);

    $pageTitle = $item['title'];
    $activeTopNav = 'feedback';
    require_once __DIR__ . '/../../includes/admin_header.php';
    ?>
    <div class="container py-3" style="max-width: 780px;">
        <a href="/admin/feedback.php" class="text-muted small">← All change requests</a>
        <?php if ($msg !== ''): ?>
            <div class="alert alert-info alert-dismissible fade show mt-2"><?= htmlspecialchars($msg) ?><button type="button" class="btn-close" data-bs-dismiss="alert"></button></div>
        <?php endif; ?>

        <div class="d-flex justify-content-between align-items-start flex-wrap gap-2 mt-2 mb-3">
            <div>
                <h1 class="h4 mb-1"><?= htmlspecialchars((string) $item['title']) ?></h1>
                <div class="small text-muted">
                    Submitted <?= htmlspecialchars((string) $item['submitted_at']) ?> by <?= htmlspecialchars((string) $item['submitted_by']) ?>
                    <?php if (!empty($item['page'])): ?> · re: <?= htmlspecialchars((string) $item['page']) ?><?php endif; ?>
                </div>
            </div>
            <div class="text-end">
                <span class="badge <?= $statusInfo['badge'] ?> fs-6"><?= htmlspecialchars($statusInfo['label']) ?></span>
                <?php if (!empty($item['github_issue_url'])): ?>
                    <div class="small mt-1"><a href="<?= htmlspecialchars((string) $item['github_issue_url']) ?>" target="_blank">Open on GitHub ↗</a></div>
                <?php endif; ?>
            </div>
        </div>

        <div class="card mb-3">
            <div class="card-body">
                <div style="white-space: pre-wrap;"><?= htmlspecialchars((string) $item['body']) ?></div>
            </div>
        </div>

        <h5 class="text-muted mt-4 mb-2">Discussion</h5>
        <?php if (!github_configured()): ?>
            <p class="text-muted small">GitHub integration isn't set up — discussion happens by DM/text.</p>
        <?php elseif (empty($item['github_issue_number'])): ?>
            <p class="text-muted small">This request was saved locally but no GitHub issue exists (send may have failed at submit time).</p>
        <?php elseif ($ghComments === []): ?>
            <p class="text-muted small">No responses yet.</p>
        <?php else: ?>
            <?php foreach ($ghComments as $c): ?>
                <div class="card mb-2">
                    <div class="card-body py-2 px-3">
                        <div class="small text-muted mb-1">
                            <strong><?= htmlspecialchars((string) ($c['user']['login'] ?? 'Unknown')) ?></strong>
                            · <?= htmlspecialchars(substr((string) ($c['created_at'] ?? ''), 0, 16)) ?>
                        </div>
                        <div style="white-space: pre-wrap;"><?= htmlspecialchars((string) ($c['body'] ?? '')) ?></div>
                    </div>
                </div>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>
    <?php
    require_once __DIR__ . '/../../includes/admin_footer.php';
    exit;
}

// ----- List view -----
$feedback = load_feedback();
$ghByNumber = github_fetch_feedback_issues();

$pageTitle = 'Change Requests';
$activeTopNav = 'feedback';
require_once __DIR__ . '/../../includes/admin_header.php';
?>
<div class="container py-3" style="max-width: 780px;">
    <h1 class="h4 mb-3">Change Requests</h1>
    <?php if ($msg !== ''): ?>
        <div class="alert alert-info alert-dismissible fade show"><?= htmlspecialchars($msg) ?><button type="button" class="btn-close" data-bs-dismiss="alert"></button></div>
    <?php endif; ?>

    <?php if (!github_configured()): ?>
        <div class="alert alert-warning small">
            <strong>Heads up:</strong> the GitHub integration isn't set up yet. Requests still save here, but Jeff won't get a notification until a token is added in <a href="/admin/settings.php">Settings</a>.
        </div>
    <?php endif; ?>

    <div class="card mb-4">
        <div class="card-header"><strong>Send a new request</strong></div>
        <div class="card-body">
            <p class="small text-muted mb-3">Anything you want changed, added, or fixed — a new page, a tool you wish existed, text you want tweaked, whatever. Jeff will get notified.</p>
            <form method="post">
                <?php csrf_field(); ?>
                <input type="hidden" name="action" value="add">
                <div class="mb-3">
                    <label class="form-label">Short title <span class="text-danger">*</span></label>
                    <input class="form-control" type="text" name="title" placeholder="e.g. Add a student resources page" required>
                </div>
                <div class="mb-3">
                    <label class="form-label">Which part of the site? <small class="text-muted">(optional)</small></label>
                    <input class="form-control" type="text" name="page" placeholder="e.g. home, admin, or free text">
                </div>
                <div class="mb-3">
                    <label class="form-label">What would you like changed? <span class="text-danger">*</span></label>
                    <textarea class="form-control" name="body" rows="5" placeholder="Describe what you'd like. The more context, the faster it gets done." required></textarea>
                </div>
                <button type="submit" class="btn btn-dark">Send</button>
            </form>
        </div>
    </div>

    <h5 class="text-muted mb-2">Past requests</h5>
    <?php if ($feedback === []): ?>
        <div class="card"><div class="card-body text-muted">No requests yet.</div></div>
    <?php else: ?>
        <div class="list-group">
            <?php foreach ($feedback as $f):
                $n = (int) ($f['github_issue_number'] ?? 0);
                $issue = $n > 0 ? ($ghByNumber[$n] ?? null) : null;
                $statusInfo = feedback_status_label($issue);
                $fid = htmlspecialchars((string) $f['id']);
            ?>
                <a href="/admin/feedback.php?id=<?= $fid ?>" class="list-group-item list-group-item-action">
                    <div class="d-flex justify-content-between align-items-start gap-2">
                        <div>
                            <strong><?= htmlspecialchars((string) $f['title']) ?></strong>
                            <?php if ($n > 0): ?><small class="text-muted ms-1">#<?= $n ?></small><?php endif; ?>
                            <div class="small text-muted mt-1">
                                <?= htmlspecialchars(substr((string) $f['submitted_at'], 0, 16)) ?>
                                · <?= htmlspecialchars((string) $f['submitted_by']) ?>
                                <?php if (!empty($f['page'])): ?> · <?= htmlspecialchars((string) $f['page']) ?><?php endif; ?>
                            </div>
                            <?php if ($issue && isset($issue['comments']) && (int) $issue['comments'] > 0): ?>
                                <div class="small text-primary mt-1">💬 <?= (int) $issue['comments'] ?> comment<?= (int) $issue['comments'] === 1 ? '' : 's' ?></div>
                            <?php endif; ?>
                        </div>
                        <span class="badge <?= $statusInfo['badge'] ?>"><?= htmlspecialchars($statusInfo['label']) ?></span>
                    </div>
                </a>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</div>
<?php require_once __DIR__ . '/../../includes/admin_footer.php'; ?>
