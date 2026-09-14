<?php
declare(strict_types=1);

require_once __DIR__ . '/json_store.php';
require_once __DIR__ . '/users_service.php';

function app_settings(): array
{
    $defaults = [
        'site_name'         => 'Heather Osness',
        'site_tagline'      => 'A little corner of the internet.',
        'contact_email'     => '',
        // GitHub Issues integration for the change-request queue. Empty
        // token = feedback still saves locally, just doesn't create issues.
        'github_token'      => '',
        'github_repo_owner' => 'jeffosness',
        'github_repo_name'  => 'heather',
    ];
    $loaded = read_json_file(APP_SETTINGS_FILE, []);
    return array_merge($defaults, is_array($loaded) ? $loaded : []);
}

function is_logged_in(): bool
{
    return !empty($_SESSION['heather_logged_in']) && !empty($_SESSION['heather_user_id']);
}

function is_admin(): bool
{
    // Every logged-in user is admin for now — single-owner site.
    return is_logged_in();
}

function current_user(): ?array
{
    if (!is_logged_in()) return null;
    return find_user((string) $_SESSION['heather_user_id']);
}

function require_login(): void
{
    if (!is_logged_in()) {
        header('Location: /admin/login.php');
        exit;
    }
}

function attempt_login(string $username, string $password): bool
{
    if ($username === '' || $password === '') return false;
    $user = find_user_by_username($username);
    if (!$user) return false;
    $hash = (string) ($user['password_hash'] ?? '');
    if ($hash === '' || !password_verify($password, $hash)) return false;
    session_regenerate_id(true);
    $_SESSION['heather_logged_in'] = true;
    $_SESSION['heather_user_id']   = (string) $user['id'];
    update_user((string) $user['id'], ['last_login_at' => date('Y-m-d H:i:s')]);
    return true;
}

function logout_user(): void
{
    unset($_SESSION['heather_logged_in'], $_SESSION['heather_user_id']);
    session_regenerate_id(true);
}
