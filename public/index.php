<?php
declare(strict_types=1);
require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/auth.php';

$settings = app_settings();

$pageTitle = '';
$activeNav = 'home';
require_once __DIR__ . '/../includes/public_header.php';
?>
<header class="hero text-center">
    <div class="container">
        <h1 class="brand mb-3">✿ <?= htmlspecialchars((string) $settings['site_name']) ?></h1>
        <?php if (trim((string) $settings['site_tagline']) !== ''): ?>
            <p class="lead mb-4"><?= htmlspecialchars((string) $settings['site_tagline']) ?></p>
        <?php endif; ?>
    </div>
</header>

<div class="container py-5 text-center" style="max-width: 640px;">
    <p style="font-size: 1.1rem; line-height: 1.7;">
        This is Heather's corner of the internet — a playground for good ideas.
        More coming soon.
    </p>
</div>

<?php require_once __DIR__ . '/../includes/public_footer.php'; ?>
