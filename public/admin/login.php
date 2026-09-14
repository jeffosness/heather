<?php
declare(strict_types=1);
require_once __DIR__ . '/../../includes/bootstrap.php';
require_once __DIR__ . '/../../includes/auth.php';

if (no_users_yet()) {
    header('Location: /admin/setup.php');
    exit;
}
if (is_logged_in()) {
    header('Location: /admin/');
    exit;
}

$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    if (attempt_login((string) ($_POST['username'] ?? ''), (string) ($_POST['password'] ?? ''))) {
        header('Location: /admin/');
        exit;
    }
    $error = 'Sign in failed. Check your username and password.';
}

$settings = app_settings();
$pageTitle = 'Sign in';
$hideNav = true;
require_once __DIR__ . '/../../includes/admin_header.php';
?>
<div class="container py-5" style="max-width: 420px;">
    <h1 class="h4 mb-3 text-center">✿ <?= htmlspecialchars((string) $settings['site_name']) ?></h1>
    <?php if ($error !== ''): ?>
        <div class="alert alert-danger"><?= htmlspecialchars($error) ?></div>
    <?php endif; ?>
    <div class="card">
        <div class="card-body">
            <form method="post">
                <?php csrf_field(); ?>
                <div class="mb-3">
                    <label class="form-label">Username</label>
                    <input class="form-control" type="text" name="username" autocomplete="username" required autofocus>
                </div>
                <div class="mb-3">
                    <label class="form-label">Password</label>
                    <input class="form-control" type="password" name="password" autocomplete="current-password" required>
                </div>
                <button class="btn btn-dark w-100" type="submit">Sign in</button>
            </form>
        </div>
    </div>
    <p class="text-center mt-3"><a href="/" class="text-muted small">← Back to site</a></p>
</div>
<?php require_once __DIR__ . '/../../includes/admin_footer.php'; ?>
