<?php
declare(strict_types=1);
require_once __DIR__ . '/student_auth.php';
$settings = app_settings();
$pageTitle = $pageTitle ?? '';
$fullTitle = ($pageTitle !== '' ? $pageTitle . ' · ' : '') . 'Case Log · ' . htmlspecialchars((string) $settings['site_name']);
$extraHead = $extraHead ?? '';
$activeNav = $activeNav ?? '';
$hideNav = $hideNav ?? false;
$signedInStudent = current_student();
?><!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= $fullTitle ?></title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
<meta name="theme-color" content="#0d6efd">
<style>
    :root {
        --sc-primary:   #2b6cb0;
        --sc-primary-2: #1a4b8a;
        --sc-accent:    #38a169;
        --sc-warn:      #dd6b20;
        --sc-bg:        #f4f7fb;
        --sc-ink:       #1a2237;
    }
    body { font-family: 'Inter', system-ui, sans-serif; color: var(--sc-ink); background: var(--sc-bg); }
    .navbar { background: var(--sc-primary); }
    .navbar-brand, .nav-link { color: #fff !important; }
    .nav-link:hover { opacity: .85; }
    .btn-scrub { background: var(--sc-primary); border-color: var(--sc-primary); color: #fff; }
    .btn-scrub:hover { background: var(--sc-primary-2); border-color: var(--sc-primary-2); color: #fff; }
    .btn, .form-control, .form-select { min-height: 42px; }
    input, select, textarea { font-size: 16px; }

    /* Progress wheels */
    .wheel {
        position: relative;
        width: 130px;
        height: 130px;
    }
    .wheel svg { width: 100%; height: 100%; transform: rotate(-90deg); }
    .wheel circle { fill: none; stroke-width: 12; }
    .wheel .track { stroke: #e2e8f0; }
    .wheel .fill { stroke: var(--sc-primary); transition: stroke-dashoffset .6s ease; }
    .wheel.complete .fill { stroke: var(--sc-accent); }
    .wheel-label {
        position: absolute; inset: 0;
        display: flex; flex-direction: column; align-items: center; justify-content: center;
        text-align: center;
    }
    .wheel-num { font-size: 1.4rem; font-weight: 700; line-height: 1; }
    .wheel-need { font-size: .75rem; color: #64748b; margin-top: 2px; }
    .wheel-caption { font-size: .82rem; color: #475569; text-align: center; margin-top: .5rem; }
</style>
<?= $extraHead ?>
</head>
<body>
<?php if (!$hideNav && $signedInStudent): ?>
<nav class="navbar navbar-expand-md sticky-top">
    <div class="container">
        <a class="navbar-brand fw-bold" href="/students/">Case Log</a>
        <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#studentNav">
            <span class="navbar-toggler-icon"></span>
        </button>
        <div class="collapse navbar-collapse" id="studentNav">
            <ul class="navbar-nav me-auto">
                <li class="nav-item"><a class="nav-link <?= $activeNav === 'progress' ? 'fw-bold' : '' ?>" href="/students/">Progress</a></li>
                <li class="nav-item"><a class="nav-link <?= $activeNav === 'cases' ? 'fw-bold' : '' ?>" href="/students/cases.php">My cases</a></li>
            </ul>
            <ul class="navbar-nav">
                <li class="nav-item"><span class="nav-link small opacity-75"><?= htmlspecialchars((string) $signedInStudent['name']) ?></span></li>
                <li class="nav-item"><a class="nav-link" href="/students/logout.php">Sign out</a></li>
            </ul>
        </div>
    </div>
</nav>
<?php endif; ?>
