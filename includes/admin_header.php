<?php
declare(strict_types=1);
$settings = app_settings();
$pageTitle = $pageTitle ?? '';
$fullTitle = ($pageTitle !== '' ? $pageTitle . ' · ' : '') . 'Admin · ' . htmlspecialchars((string) $settings['site_name']);
$extraHead = $extraHead ?? '';
$hideNav = $hideNav ?? false;
$activeTopNav = $activeTopNav ?? '';

/**
 * Sub-nav for grouped admin sections. Grouped pages set
 * $activeTopNav = 'settings' and $subnavActive = the specific page key,
 * then drop <?= admin_subnav_html(...) ?> into a col-md-3 next to their
 * main content.
 */
function admin_subnav_html(string $group, string $active): string
{
    $groups = [
        'settings' => [
            ['settings',      'Site info',     '/admin/settings.php'],
            ['notifications', 'Notifications', '/admin/notifications.php'],
            ['specialties',   'Specialties',   '/admin/specialties.php'],
            ['doctors',       'Doctors',       '/admin/doctors.php'],
            ['preceptors',    'Preceptors',    '/admin/preceptors.php'],
            ['users',         'Admin users',   '/admin/users.php'],
        ],
    ];
    if (!isset($groups[$group])) return '';
    $html = '<div class="list-group admin-subnav mb-3">';
    foreach ($groups[$group] as [$key, $label, $url]) {
        $activeCls = $key === $active ? 'active' : '';
        $html .= sprintf(
            '<a href="%s" class="list-group-item list-group-item-action %s">%s</a>',
            htmlspecialchars($url), $activeCls, htmlspecialchars($label)
        );
    }
    $html .= '</div>';
    return $html;
}
?><!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= $fullTitle ?></title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
<meta name="theme-color" content="#2e2a3d">
<style>
    :root {
        --plum:  #5e548e;
        --sage:  #9caf88;
        --cream: #faf7f2;
        --char:  #2e2a3d;
    }
    body { background: #f4f0ea; color: var(--char); }
    .navbar { background: var(--char); }
    .navbar-brand { font-weight: 700; }
    .btn, .form-control, .form-select { min-height: 42px; }
    input, select, textarea { font-size: 16px; }
</style>
<?= $extraHead ?>
</head>
<body>
<?php if (!$hideNav && is_logged_in()): ?>
<nav class="navbar navbar-expand-md navbar-dark">
    <div class="container">
        <a class="navbar-brand" href="/admin/">✿ Admin</a>
        <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#adminNav">
            <span class="navbar-toggler-icon"></span>
        </button>
        <div class="collapse navbar-collapse" id="adminNav">
            <?php
            $topNav = [
                ['dashboard', 'Dashboard',       '/admin/'],
                ['students',  'Students',        '/admin/students.php'],
                ['feedback',  'Change requests', '/admin/feedback.php'],
                ['settings',  'Settings',        '/admin/settings.php'],
            ];
            ?>
            <ul class="navbar-nav me-auto">
                <?php foreach ($topNav as [$key, $label, $url]): ?>
                    <li class="nav-item"><a class="nav-link <?= $activeTopNav === $key ? 'fw-bold active' : '' ?>" href="<?= htmlspecialchars($url) ?>"><?= htmlspecialchars($label) ?></a></li>
                <?php endforeach; ?>
            </ul>
            <ul class="navbar-nav">
                <li class="nav-item"><a class="nav-link" href="/" target="_blank">↗ View site</a></li>
                <li class="nav-item"><a class="nav-link" href="/admin/logout.php">Sign out</a></li>
            </ul>
        </div>
    </div>
</nav>
<?php endif; ?>
