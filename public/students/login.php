<?php
declare(strict_types=1);
require_once __DIR__ . '/../../includes/bootstrap.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/student_auth.php';

if (is_student_logged_in()) {
    header('Location: /students/');
    exit;
}

$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    if (attempt_student_login((string) ($_POST['who'] ?? ''), (string) ($_POST['password'] ?? ''))) {
        header('Location: /students/');
        exit;
    }
    $error = 'Sign in failed. Check your username (or email) and password.';
}

$pageTitle = 'Sign in';
$hideNav = true;
require_once __DIR__ . '/../../includes/student_header.php';
?>
<div class="container py-5" style="max-width: 420px;">
    <h1 class="h4 mb-3 text-center">Student sign in</h1>
    <?php if ($error !== ''): ?>
        <div class="alert alert-danger"><?= htmlspecialchars($error) ?></div>
    <?php endif; ?>
    <div class="card">
        <div class="card-body">
            <form method="post">
                <?php csrf_field(); ?>
                <div class="mb-3">
                    <label class="form-label">Username or email</label>
                    <input class="form-control" type="text" name="who" required autofocus autocomplete="username">
                </div>
                <div class="mb-3">
                    <label class="form-label">Password</label>
                    <input class="form-control" type="password" name="password" required autocomplete="current-password">
                </div>
                <button class="btn btn-scrub w-100" type="submit">Sign in</button>
            </form>
        </div>
    </div>
    <p class="text-center mt-3 mb-1"><a href="/students/forgot_password.php" class="text-muted small">Forgot your password?</a></p>
    <p class="text-center mb-0"><a href="/" class="text-muted small">← Back to site</a></p>
</div>
<?php require_once __DIR__ . '/../../includes/student_footer.php'; ?>
