<?php
declare(strict_types=1);
require_once __DIR__ . '/../../includes/bootstrap.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/notifications.php';

require_login();

$me = current_user();
$myEmail = trim((string) ($me['email'] ?? ''));
$message = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $action = (string) ($_POST['action'] ?? '');
    if ($action === 'save_sender') {
        $settings = app_settings();
        $settings['notification_from_email'] = trim((string) ($_POST['notification_from_email'] ?? ''));
        $settings['notification_from_name']  = trim((string) ($_POST['notification_from_name']  ?? ''));
        $settings['smtp_host']       = trim((string) ($_POST['smtp_host']       ?? ''));
        $settings['smtp_port']       = (int)    ($_POST['smtp_port']       ?? 587);
        $settings['smtp_encryption'] = trim((string) ($_POST['smtp_encryption'] ?? 'tls'));
        $settings['smtp_username']   = trim((string) ($_POST['smtp_username']   ?? ''));
        // Only overwrite the password if a new one was typed.
        $newPass = (string) ($_POST['smtp_password'] ?? '');
        if ($newPass !== '') $settings['smtp_password'] = $newPass;
        write_json_file(APP_SETTINGS_FILE, $settings);
        $message = 'saved';
    } elseif ($action === 'send_test') {
        if ($myEmail === '') {
            $message = 'no_email';
        } else {
            $sender = notify_from();
            $ok = notify_send_one(
                $myEmail,
                'Test — Heather Osness notifications',
                "This is a test email from heather.osness.org.\n\n"
                . "If you see this, the notification pipe works. If it lands in spam, "
                . "add " . $sender['email'] . " to your contacts.\n\n"
                . "Sent " . date('Y-m-d H:i:s') . " to " . $myEmail . ".\n",
                'test'
            );
            $message = $ok ? 'test_ok' : 'test_fail';
        }
    }
    header('Location: /admin/notifications.php?msg=' . urlencode($message));
    exit;
}

$settings = app_settings();
$msg = (string) ($_GET['msg'] ?? '');
$smtpConfigured = notify_smtp_configured();
$sender = notify_from();
$effective = notify_smtp_config();

$pageTitle = 'Notifications';
$activeTopNav = 'settings';
require_once __DIR__ . '/../../includes/admin_header.php';
?>
<div class="container py-3">
<div class="row g-4">
<div class="col-md-3"><?= admin_subnav_html('settings', 'notifications') ?></div>
<div class="col-md-9" style="max-width: 780px;">
    <h1 class="h4 mb-3">Notifications</h1>
    <p class="text-muted small">
        Powers the password-reset email today; any future feature that needs to
        email admins hooks the same pipe.
    </p>

    <?php if ($msg === 'saved'): ?>
        <div class="alert alert-info alert-dismissible fade show">Saved.<button type="button" class="btn-close" data-bs-dismiss="alert"></button></div>
    <?php elseif ($msg === 'test_ok'): ?>
        <div class="alert alert-success">
            <strong>Test accepted by the mail server.</strong>
            Check your inbox at <code><?= htmlspecialchars($myEmail) ?></code>. If it lands in spam, add <code><?= htmlspecialchars($sender['email']) ?></code> to your contacts.
        </div>
    <?php elseif ($msg === 'test_fail'): ?>
        <?php $lastLine = notify_log_tail(1)[0] ?? ''; ?>
        <div class="alert alert-danger">
            <strong>Test failed.</strong>
            <?php if ($smtpConfigured): ?>
                The SMTP server rejected something. The details line below names the exact verb (auth, from, rcpt, etc.).
            <?php else: ?>
                PHP <code>mail()</code> rejected the message — IONOS blocks it without an authenticated sender. Configure SMTP below.
            <?php endif; ?>
            <?php if ($lastLine !== ''): ?>
                <pre class="mt-2 mb-0 p-2" style="white-space:pre-wrap; word-break:break-word; background:#fff; border:1px solid #f5c2c7; border-radius:.25rem; font-size:.8rem;"><?= htmlspecialchars($lastLine) ?></pre>
            <?php endif; ?>
        </div>
    <?php elseif ($msg === 'no_email'): ?>
        <div class="alert alert-warning">Add an email address on the <a href="/admin/users.php">Users</a> page first.</div>
    <?php endif; ?>

    <div class="card">
        <div class="card-header d-flex justify-content-between align-items-center flex-wrap gap-2">
            <strong>Sender + SMTP</strong>
            <?php if ($smtpConfigured): ?>
                <span class="badge bg-success">SMTP active</span>
            <?php else: ?>
                <span class="badge bg-warning text-dark">Using PHP mail() — unreliable on IONOS</span>
            <?php endif; ?>
        </div>
        <div class="card-body">
            <p class="small text-muted mb-3">
                Site-wide. When SMTP host/user/password are all set, mail goes over authenticated SMTP (reliable).
                Otherwise PHP <code>mail()</code> is used as a fallback (silently drops mail on most shared hosts).
            </p>
            <form method="post">
                <?php csrf_field(); ?>
                <input type="hidden" name="action" value="save_sender">

                <h6 class="text-muted">From address</h6>
                <div class="row g-2 mb-3">
                    <div class="col-md-7">
                        <label class="form-label">From address</label>
                        <input class="form-control" type="email" name="notification_from_email" value="<?= htmlspecialchars((string) ($settings['notification_from_email'] ?? '')) ?>" placeholder="notifications@heather.osness.org">
                    </div>
                    <div class="col-md-5">
                        <label class="form-label">From name <small class="text-muted">(inbox display)</small></label>
                        <input class="form-control" type="text" name="notification_from_name" value="<?= htmlspecialchars((string) ($settings['notification_from_name'] ?? '')) ?>" placeholder="<?= htmlspecialchars((string) $settings['site_name']) ?>">
                    </div>
                </div>

                <h6 class="text-muted">SMTP <small class="text-muted">(recommended)</small></h6>
                <p class="small text-muted mb-2">
                    For IONOS mailboxes: host <code>smtp.ionos.com</code>, port <code>587</code>, TLS,
                    username + password from your IONOS mailbox. All fields except the password have
                    sensible defaults — just paste the password and save.
                </p>
                <div class="row g-2 mb-3">
                    <div class="col-md-7">
                        <label class="form-label">SMTP host</label>
                        <input class="form-control" type="text" name="smtp_host" value="<?= htmlspecialchars($effective['host']) ?>">
                    </div>
                    <div class="col-md-2">
                        <label class="form-label">Port</label>
                        <input class="form-control" type="number" name="smtp_port" value="<?= (int) $effective['port'] ?>" min="1" max="65535">
                    </div>
                    <div class="col-md-3">
                        <label class="form-label">Encryption</label>
                        <select class="form-select" name="smtp_encryption">
                            <?php foreach (['tls' => 'TLS (587)', 'ssl' => 'SSL (465)', 'none' => 'None'] as $v => $label): ?>
                                <option value="<?= $v ?>" <?= ($effective['encryption'] === $v) ? 'selected' : '' ?>><?= $label ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-7">
                        <label class="form-label">SMTP username</label>
                        <input class="form-control" type="text" name="smtp_username" value="<?= htmlspecialchars($effective['username']) ?>" autocomplete="off">
                    </div>
                    <div class="col-md-5">
                        <label class="form-label">SMTP password <span class="text-danger">*</span></label>
                        <input class="form-control" type="password" name="smtp_password" placeholder="<?= !empty($settings['smtp_password']) ? '••••••• (set — leave blank to keep)' : 'mailbox password' ?>" autocomplete="new-password">
                        <small class="text-muted">Stored in <code>protected/heather/settings.json</code> — outside webroot.</small>
                    </div>
                </div>

                <button type="submit" class="btn btn-dark">Save sender + SMTP</button>
            </form>
        </div>
    </div>

    <div class="card mt-3">
        <div class="card-header d-flex justify-content-between align-items-center">
            <strong>Test the pipe</strong>
            <form method="post" class="m-0">
                <?php csrf_field(); ?>
                <input type="hidden" name="action" value="send_test">
                <button type="submit" class="btn btn-sm btn-outline-dark" <?= $myEmail === '' ? 'disabled' : '' ?>>Send test email to me</button>
            </form>
        </div>
        <div class="card-body">
            <p class="small text-muted mb-0">
                Sends a test to <code><?= htmlspecialchars($myEmail !== '' ? $myEmail : 'your address (add one on Users)') ?></code>.
                Result appears above and in the log below.
            </p>
        </div>
    </div>

    <div class="card mt-3">
        <div class="card-header"><strong>Recent notification attempts</strong> <small class="text-muted">(newest first, last 30)</small></div>
        <?php $tail = notify_log_tail(30); ?>
        <?php if ($tail === []): ?>
            <div class="card-body text-muted small">Nothing logged yet. Send a test or trigger an event to populate this.</div>
        <?php else: ?>
            <div class="card-body p-3">
                <button type="button" class="btn btn-sm btn-outline-secondary mb-2" id="copyLogBtn" data-no-spinner>Copy all to clipboard</button>
                <textarea id="logTail" readonly style="width:100%; height:280px; font-family:monospace; font-size:.8rem; line-height:1.5; background:#f8f9fa; border:1px solid #dee2e6; border-radius:.25rem; padding:.5rem; resize:vertical; white-space:pre; overflow:auto;"><?= htmlspecialchars(implode("\n", $tail)) ?></textarea>
                <script>
                    document.getElementById('copyLogBtn')?.addEventListener('click', async (e) => {
                        const btn = e.currentTarget;
                        const ta = document.getElementById('logTail');
                        try {
                            await navigator.clipboard.writeText(ta.value);
                            const orig = btn.textContent;
                            btn.textContent = 'Copied ✓';
                            setTimeout(() => { btn.textContent = orig; }, 1500);
                        } catch { ta.focus(); ta.select(); }
                    });
                </script>
            </div>
        <?php endif; ?>
    </div>
</div>
</div>
</div>
<?php require_once __DIR__ . '/../../includes/admin_footer.php'; ?>
