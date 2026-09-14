<?php
declare(strict_types=1);
require_once __DIR__ . '/../../includes/bootstrap.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/user_reset.php';
require_once __DIR__ . '/../../includes/students_service.php';

$token   = (string) ($_GET['token'] ?? $_POST['token'] ?? '');
$student = $token !== '' ? consume_student_reset_token($token) : null;
$isInvite = $student !== null && !student_has_password($student);
$error = '';
$done  = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $student) {
    csrf_check();
    $newPw = (string) ($_POST['password'] ?? '');
    $confirm = (string) ($_POST['password_confirm'] ?? '');
    if ($newPw !== $confirm) {
        $error = "Passwords don't match.";
    } elseif (strlen($newPw) < 8) {
        $error = 'Password must be at least 8 characters.';
    } elseif (apply_student_password_reset($token, $newPw)) {
        $done = true;
    } else {
        $error = 'Something went wrong saving the password. Try requesting a fresh link.';
    }
}

$settings = app_settings();
$pageTitle = $isInvite ? 'Set your password' : 'Reset password';
$hideNav = true;
require_once __DIR__ . '/../../includes/student_header.php';
?>
<div class="container py-5" style="max-width: 460px;">
    <h1 class="h4 mb-3 text-center">
        <?= $isInvite ? 'Welcome — set your password' : 'Choose a new password' ?>
    </h1>

    <?php if ($done): ?>
        <div class="alert alert-success">
            <strong>Password saved.</strong> You can sign in now.
        </div>
        <div class="text-center mt-3">
            <a href="/students/login.php" class="btn btn-scrub">Sign in →</a>
        </div>
    <?php elseif (!$student): ?>
        <div class="alert alert-danger">
            This link is invalid or has expired. Ask Heather to resend, or use the reset link below.
        </div>
        <div class="text-center mt-3">
            <a href="/students/forgot_password.php" class="btn btn-outline-dark">← Request a reset link</a>
        </div>
    <?php else: ?>
        <?php if ($error !== ''): ?>
            <div class="alert alert-danger"><?= htmlspecialchars($error) ?></div>
        <?php endif; ?>
        <div class="card">
            <div class="card-body">
                <?php if ($isInvite): ?>
                    <p class="mb-3">
                        Hi <strong><?= htmlspecialchars((string) $student['name']) ?></strong> — welcome to the
                        Surgical Technology case log. Pick a password and you're in.
                    </p>
                    <p class="small text-muted mb-3">
                        Your username will be <code><?= htmlspecialchars((string) ($student['username'] ?? '')) ?></code>.
                    </p>
                <?php else: ?>
                    <p class="small text-muted mb-3">
                        Resetting password for <strong><?= htmlspecialchars((string) $student['name']) ?></strong>.
                    </p>
                <?php endif; ?>
                <form method="post">
                    <?php csrf_field(); ?>
                    <input type="hidden" name="token" value="<?= htmlspecialchars($token) ?>">
                    <div class="mb-3">
                        <label class="form-label"><?= $isInvite ? 'Choose a password' : 'New password' ?></label>
                        <input class="form-control" type="password" name="password" minlength="8" required autofocus autocomplete="new-password">
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Confirm password</label>
                        <input class="form-control" type="password" name="password_confirm" minlength="8" required autocomplete="new-password">
                    </div>
                    <button type="submit" class="btn btn-scrub w-100"><?= $isInvite ? 'Create my account' : 'Save new password' ?></button>
                </form>
            </div>
        </div>
    <?php endif; ?>
</div>
<?php require_once __DIR__ . '/../../includes/student_footer.php'; ?>
