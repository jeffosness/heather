<?php
declare(strict_types=1);
require_once __DIR__ . '/../../includes/bootstrap.php';
require_once __DIR__ . '/../../includes/auth.php';

// First-run only. Once any user exists, this page redirects to login.
if (!no_users_yet()) {
    header('Location: /admin/login.php');
    exit;
}

$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $user = add_user([
        'username' => (string) ($_POST['username'] ?? ''),
        'name'     => (string) ($_POST['name']     ?? ''),
        'email'    => (string) ($_POST['email']    ?? ''),
        'password' => (string) ($_POST['password'] ?? ''),
    ]);
    if ($user) {
        // Sign them in and land on the dashboard.
        session_regenerate_id(true);
        $_SESSION['heather_logged_in'] = true;
        $_SESSION['heather_user_id']   = (string) $user['id'];
        header('Location: /admin/');
        exit;
    }
    $error = 'Fill in every field. Password must be at least 8 characters.';
}

$pageTitle = 'Setup';
$hideNav = true;
require_once __DIR__ . '/../../includes/admin_header.php';
?>
<div class="container py-5" style="max-width: 480px;">
    <h1 class="h4 mb-3 text-center">✿ Set up the first admin</h1>
    <p class="text-muted text-center small">One-time — this page disappears once you have an account.</p>
    <?php if ($error !== ''): ?>
        <div class="alert alert-danger"><?= htmlspecialchars($error) ?></div>
    <?php endif; ?>
    <div class="card">
        <div class="card-body">
            <form method="post">
                <?php csrf_field(); ?>
                <div class="mb-3">
                    <label class="form-label">Your name</label>
                    <input class="form-control" type="text" name="name" required autofocus>
                </div>
                <div class="mb-3">
                    <label class="form-label">Username <small class="text-muted">(what you'll type to sign in)</small></label>
                    <input class="form-control" type="text" name="username" required>
                </div>
                <div class="mb-3">
                    <label class="form-label">Email <small class="text-muted">(optional)</small></label>
                    <input class="form-control" type="email" name="email">
                </div>
                <div class="mb-3">
                    <label class="form-label">Password</label>
                    <input class="form-control" type="password" name="password" minlength="8" required>
                </div>
                <button class="btn btn-dark w-100" type="submit">Create account & sign in</button>
            </form>
        </div>
    </div>
</div>
<?php require_once __DIR__ . '/../../includes/admin_footer.php'; ?>
