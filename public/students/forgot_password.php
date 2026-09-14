<?php
declare(strict_types=1);
require_once __DIR__ . '/../../includes/bootstrap.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/user_reset.php';
require_once __DIR__ . '/../../includes/students_service.php';
require_once __DIR__ . '/../../includes/notifications.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $who = trim((string) ($_POST['who'] ?? ''));
    if ($who !== '') {
        $student = find_student_by_username($who) ?: find_student_by_email($who);
        if ($student) {
            // Reuses the same invite email helper — student sets a new
            // password via /students/set_password.php.
            send_student_invite_email($student);
        }
    }
    header('Location: /students/forgot_password.php?sent=1');
    exit;
}
$sent = isset($_GET['sent']) && $_GET['sent'] === '1';

$settings = app_settings();
$pageTitle = 'Forgot password';
$hideNav = true;
require_once __DIR__ . '/../../includes/student_header.php';
?>
<div class="container py-5" style="max-width: 460px;">
    <h1 class="h4 mb-3 text-center">Reset your password</h1>

    <?php if ($sent): ?>
        <div class="alert alert-success">
            <strong>Check your email.</strong> If an account exists, a reset link is on its way. The link expires in 7 days.
        </div>
        <div class="text-center mt-3">
            <a href="/students/login.php" class="btn btn-outline-dark">← Back to sign in</a>
        </div>
    <?php else: ?>
        <div class="card">
            <div class="card-body">
                <p class="small text-muted mb-3">
                    Enter your username or the email on your account.
                </p>
                <form method="post">
                    <?php csrf_field(); ?>
                    <div class="mb-3">
                        <label class="form-label">Username or email</label>
                        <input class="form-control" type="text" name="who" required autofocus>
                    </div>
                    <button type="submit" class="btn btn-scrub w-100">Send reset link</button>
                </form>
            </div>
        </div>
        <p class="text-center mt-3"><a href="/students/login.php" class="text-muted small">← Back to sign in</a></p>
    <?php endif; ?>
</div>
<?php require_once __DIR__ . '/../../includes/student_footer.php'; ?>
