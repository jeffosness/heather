<?php
declare(strict_types=1);
require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/user_reset.php';

$sent = false;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    request_password_reset((string) ($_POST['who'] ?? ''));
    // We always claim success — never reveal whether the input matched a
    // real user. Makes bulk-guessing usernames pointless.
    header('Location: /forgot_password.php?sent=1');
    exit;
}
$sent = isset($_GET['sent']) && $_GET['sent'] === '1';

$settings = app_settings();
$pageTitle = 'Forgot password';
$hideNav = true;
require_once __DIR__ . '/../includes/admin_header.php';
?>
<div class="container py-5" style="max-width: 460px;">
    <h1 class="h4 mb-3 text-center">Reset your password</h1>

    <?php if ($sent): ?>
        <div class="alert alert-success">
            <strong>Check your email.</strong> If an account with that name or email exists, a reset link is on its way.
            The link expires in one hour.
        </div>
        <div class="text-center mt-3">
            <a href="/admin/login.php" class="btn btn-outline-dark">← Back to sign in</a>
        </div>
    <?php else: ?>
        <div class="card">
            <div class="card-body">
                <p class="small text-muted mb-3">
                    Enter your username or the email on your account. If it matches, we'll send a reset link.
                </p>
                <form method="post">
                    <?php csrf_field(); ?>
                    <div class="mb-3">
                        <label class="form-label">Username or email</label>
                        <input class="form-control" type="text" name="who" required autofocus>
                    </div>
                    <button type="submit" class="btn btn-dark w-100">Send reset link</button>
                </form>
            </div>
        </div>
        <p class="text-center mt-3">
            <a href="/admin/login.php" class="text-muted small">← Back to sign in</a>
        </p>
    <?php endif; ?>
</div>
<?php require_once __DIR__ . '/../includes/admin_footer.php'; ?>
