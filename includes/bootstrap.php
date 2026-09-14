<?php
declare(strict_types=1);

const APP_NAME = 'Heather Osness';
const APP_TIMEZONE = 'America/Denver';

date_default_timezone_set(APP_TIMEZONE);

$baseDir = dirname(__DIR__);
$protectedDir = $baseDir . DIRECTORY_SEPARATOR . 'protected' . DIRECTORY_SEPARATOR . 'heather';

define('APP_BASE_DIR',       $baseDir);
define('APP_PROTECTED_DIR',  $protectedDir);
define('APP_UPLOADS_DIR',    dirname($protectedDir) . DIRECTORY_SEPARATOR . 'uploads');
define('APP_SETTINGS_FILE',  APP_PROTECTED_DIR . DIRECTORY_SEPARATOR . 'settings.json');
define('APP_USERS_FILE',     APP_PROTECTED_DIR . DIRECTORY_SEPARATOR . 'users.json');
define('APP_FEEDBACK_FILE',  APP_PROTECTED_DIR . DIRECTORY_SEPARATOR . 'feedback.json');

if (session_status() === PHP_SESSION_NONE) {
    $sessionLifetime = 30 * 24 * 60 * 60;
    $isHttps = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https')
        || (($_SERVER['SERVER_PORT'] ?? '') === '443');
    ini_set('session.gc_maxlifetime', (string) $sessionLifetime);
    ini_set('session.use_strict_mode', '1');
    session_set_cookie_params([
        'lifetime' => $sessionLifetime,
        'path' => '/',
        'httponly' => true,
        'samesite' => 'Lax',
        'secure' => $isHttps,
    ]);
    session_start();
}

require_once __DIR__ . '/csrf.php';
