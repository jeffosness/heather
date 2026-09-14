<?php
declare(strict_types=1);
require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/user_reset.php';

$token = (string) ($_GET['token'] ?? $_POST['token'] ?? '');
$user  = $token !== '' ? consume_password_reset_token($token) : null;
$error = '';
$done  = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $user) {
    csrf_check();
    $newPw = (string) ($_POST['password'] ?? '');
    $confirm = (string) ($_POST['password_confirm'] ?? '');
    if ($newPw !== $confirm) {
        $error = "Passwords don't match.";
    } elseif (strlen($newPw) < 8) {
        $error = 'Password must be at least 8 characters.';
    } else {
        if (apply_password_reset($token, $newPw)) {
            $done = true;
        } else {
            $error = 'Something went wrong saving the password. Try requesting a fresh reset link.';
        }
    }
}

$settings = app_settings();
$pageTitle = 'Reset password';
$hideNav = true;
require_once __DIR__ . '/../includes/admin_header.php';
?>
<div class="container py-5" style="max-width: 460px;">
    <h1 class="h4 mb-3 text-center">Choose a new password</h1>

    <?php if ($done): ?>
        <div class="alert alert-success">
            <strong>Password saved.</strong> You can sign in with your new password now.
        </div>
        <div class="text-center mt-3">
            <a href="/admin/login.php" class="btn btn-dark">Sign in →</a>
        </div>
    <?php elseif (!$user): ?>
        <div class="alert alert-danger">
            This reset link is invalid or has expired. Request a new one.
        </div>
        <div class="text-center mt-3">
            <a href="/forgot_password.php" class="btn btn-outline-dark">← Request a new link</a>
        </div>
    <?php else: ?>
        <?php if ($error !== ''): ?>
            <div class="alert alert-danger"><?= htmlspecialchars($error) ?></div>
        <?php endif; ?>
        <div class="card">
            <div class="card-body">
                <p class="small text-muted mb-3">
                    Resetting password for <strong><?= htmlspecialchars((string) $user['name']) ?></strong>
                    (<code><?= htmlspecialchars((string) ($user['username'] ?? '')) ?></code>).
                </p>
                <form method="post">
                    <?php csrf_field(); ?>
                    <input type="hidden" name="token" value="<?= htmlspecialchars($token) ?>">
                    <div class="mb-3">
                        <label class="form-label">New password</label>
                        <input class="form-control" type="password" name="password" minlength="8" required autofocus autocomplete="new-password">
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Confirm new password</label>
                        <input class="form-control" type="password" name="password_confirm" minlength="8" required autocomplete="new-password">
                    </div>
                    <button type="submit" class="btn btn-dark w-100">Save new password</button>
                </form>
            </div>
        </div>
    <?php endif; ?>
</div>
<?php require_once __DIR__ . '/../includes/admin_footer.php'; ?>
