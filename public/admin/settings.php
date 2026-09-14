<?php
declare(strict_types=1);
require_once __DIR__ . '/../../includes/bootstrap.php';
require_once __DIR__ . '/../../includes/auth.php';

require_login();

$message = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $settings = app_settings();
    $settings['site_name']    = trim((string) ($_POST['site_name']    ?? $settings['site_name']));
    $settings['site_tagline'] = trim((string) ($_POST['site_tagline'] ?? ''));
    $settings['contact_email']= trim((string) ($_POST['contact_email']?? ''));
    // GitHub — only overwrite the token when a new value is provided.
    $newToken = (string) ($_POST['github_token'] ?? '');
    if ($newToken !== '') $settings['github_token'] = trim($newToken);
    $settings['github_repo_owner'] = trim((string) ($_POST['github_repo_owner'] ?? $settings['github_repo_owner']));
    $settings['github_repo_name']  = trim((string) ($_POST['github_repo_name']  ?? $settings['github_repo_name']));
    if (write_json_file(APP_SETTINGS_FILE, $settings)) $message = 'Saved.';
    header('Location: /admin/settings.php?msg=' . urlencode($message));
    exit;
}

$settings = app_settings();
$msg = (string) ($_GET['msg'] ?? '');
$pageTitle = 'Settings';
$activeTopNav = 'settings';
require_once __DIR__ . '/../../includes/admin_header.php';
?>
<div class="container py-3" style="max-width: 720px;">
    <h1 class="h4 mb-3">Site Settings</h1>
    <?php if ($msg !== ''): ?>
        <div class="alert alert-info alert-dismissible fade show"><?= htmlspecialchars($msg) ?><button type="button" class="btn-close" data-bs-dismiss="alert"></button></div>
    <?php endif; ?>

    <div class="card">
        <div class="card-body">
            <form method="post">
                <?php csrf_field(); ?>
                <div class="mb-3">
                    <label class="form-label">Site name</label>
                    <input class="form-control" type="text" name="site_name" value="<?= htmlspecialchars((string) $settings['site_name']) ?>" required>
                </div>
                <div class="mb-3">
                    <label class="form-label">Tagline <small class="text-muted">(shown under the site name on the homepage)</small></label>
                    <input class="form-control" type="text" name="site_tagline" value="<?= htmlspecialchars((string) $settings['site_tagline']) ?>">
                </div>
                <div class="mb-3">
                    <label class="form-label">Contact email <small class="text-muted">(optional)</small></label>
                    <input class="form-control" type="email" name="contact_email" value="<?= htmlspecialchars((string) $settings['contact_email']) ?>">
                </div>

                <hr>
                <h6>GitHub Issues integration <small class="text-muted">(powers Change Requests)</small></h6>
                <p class="small text-muted mb-2">
                    Generate a <strong>fine-grained</strong> personal access token limited to <code>Issues: read + write</code>
                    on the target repo, then paste below. Empty = feedback still saves locally, just doesn't create GH issues.
                </p>
                <div class="row g-2 mb-3">
                    <div class="col-md-6">
                        <label class="form-label">Repo owner</label>
                        <input class="form-control" type="text" name="github_repo_owner" value="<?= htmlspecialchars((string) $settings['github_repo_owner']) ?>" placeholder="jeffosness">
                    </div>
                    <div class="col-md-6">
                        <label class="form-label">Repo name</label>
                        <input class="form-control" type="text" name="github_repo_name" value="<?= htmlspecialchars((string) $settings['github_repo_name']) ?>" placeholder="heather">
                    </div>
                    <div class="col-12">
                        <label class="form-label">Personal access token</label>
                        <input class="form-control" type="password" name="github_token" placeholder="<?= !empty($settings['github_token']) ? '••••••• (set — leave blank to keep, paste new to replace)' : 'ghp_... or github_pat_...' ?>" autocomplete="off">
                        <small class="text-muted">Token is stored in <code>protected/heather/settings.json</code> — outside the webroot, admins-only.</small>
                    </div>
                </div>

                <button type="submit" class="btn btn-dark">Save settings</button>
            </form>
        </div>
    </div>
</div>
<?php require_once __DIR__ . '/../../includes/admin_footer.php'; ?>
