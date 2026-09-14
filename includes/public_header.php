<?php
declare(strict_types=1);
$settings = app_settings();
$pageTitle = $pageTitle ?? '';
$fullTitle = ($pageTitle !== '' ? $pageTitle . ' · ' : '') . htmlspecialchars((string) $settings['site_name']);
$extraHead = $extraHead ?? '';
$activeNav = $activeNav ?? '';
?><!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= $fullTitle ?></title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Playfair+Display:wght@600;800&family=Inter:wght@400;500;600&display=swap" rel="stylesheet">
<meta name="theme-color" content="#5e548e">
<style>
    :root {
        --plum:   #5e548e;
        --sage:   #9caf88;
        --cream:  #faf7f2;
        --char:   #2e2a3d;
    }
    body { font-family: 'Inter', system-ui, sans-serif; color: var(--char); background: var(--cream); }
    h1, h2, h3, .display-1, .display-2, .display-3, .display-4, .brand {
        font-family: 'Playfair Display', serif;
        letter-spacing: -.01em;
    }
    .brand { font-weight: 800; }
    .navbar-brand { font-family: 'Playfair Display', serif; font-weight: 800; font-size: 1.35rem; }
    a { color: var(--plum); }
    a:hover { color: var(--char); }
    .btn-plum { background: var(--plum); border-color: var(--plum); color: #fff; }
    .btn-plum:hover { background: #493f6d; border-color: #493f6d; color: #fff; }
    .hero {
        background: linear-gradient(135deg, #efeaf7 0%, var(--cream) 100%);
        padding: 5rem 0;
    }
    .hero h1 { font-size: 3.5rem; }
    @media (max-width: 575.98px) {
        .hero { padding: 3rem 0; }
        .hero h1 { font-size: 2.5rem; }
    }
    input, select, textarea { font-size: 16px; }
    .btn, .form-control, .form-select { min-height: 42px; }
</style>
<?= $extraHead ?>
</head>
<body>
<nav class="navbar navbar-expand-md bg-white border-bottom sticky-top">
    <div class="container">
        <a class="navbar-brand" href="/">✿ <?= htmlspecialchars((string) $settings['site_name']) ?></a>
        <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#mainNav">
            <span class="navbar-toggler-icon"></span>
        </button>
        <div class="collapse navbar-collapse" id="mainNav">
            <ul class="navbar-nav ms-auto">
                <li class="nav-item"><a class="nav-link <?= $activeNav === 'home' ? 'fw-bold' : '' ?>" href="/">Home</a></li>
            </ul>
        </div>
    </div>
</nav>
