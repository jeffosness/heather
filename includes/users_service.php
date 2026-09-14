<?php
declare(strict_types=1);

require_once __DIR__ . '/json_store.php';

/**
 * Admin accounts. Single-owner today (Heather); shape leaves room for
 * more later.
 *   { id, username, name, email?, password_hash, created_at, last_login_at? }
 */

function load_users(): array
{
    $data = read_json_file(APP_USERS_FILE, []);
    if (!is_array($data)) return [];
    usort($data, fn($a, $b) => strcasecmp((string) ($a['name'] ?? ''), (string) ($b['name'] ?? '')));
    return $data;
}

function save_users(array $users): bool
{
    return write_json_file(APP_USERS_FILE, array_values($users));
}

function find_user(string $id): ?array
{
    foreach (load_users() as $u) {
        if (($u['id'] ?? '') === $id) return $u;
    }
    return null;
}

function find_user_by_username(string $username): ?array
{
    $username = strtolower(trim($username));
    if ($username === '') return null;
    foreach (load_users() as $u) {
        if (strtolower((string) ($u['username'] ?? '')) === $username) return $u;
    }
    return null;
}

function username_available(string $username, string $excludeId = ''): bool
{
    $existing = find_user_by_username($username);
    if (!$existing) return true;
    return ($excludeId !== '' && (string) $existing['id'] === $excludeId);
}

function add_user(array $fields): ?array
{
    $username = strtolower(trim((string) ($fields['username'] ?? '')));
    $name = trim((string) ($fields['name'] ?? ''));
    $password = (string) ($fields['password'] ?? '');
    if ($username === '' || $name === '' || $password === '') return null;
    if (!username_available($username)) return null;
    $user = [
        'id'            => gen_id('u_'),
        'username'      => $username,
        'name'          => $name,
        'email'         => trim((string) ($fields['email'] ?? '')),
        'password_hash' => password_hash($password, PASSWORD_DEFAULT),
        'created_at'    => date('Y-m-d H:i:s'),
    ];
    $users = load_users();
    $users[] = $user;
    if (!save_users($users)) return null;
    return $user;
}

/**
 * Invite a new admin without setting a password. The invitee gets a
 * one-time link to /reset_password.php via send_invite_email() and
 * chooses their own password on first click. Requires an email — no
 * email means no way to send the link.
 *
 * Caller is expected to send_invite_email($user) after this returns.
 */
function invite_user(array $fields): ?array
{
    $username = strtolower(trim((string) ($fields['username'] ?? '')));
    $name  = trim((string) ($fields['name']  ?? ''));
    $email = trim((string) ($fields['email'] ?? ''));
    if ($username === '' || $name === '' || $email === '') return null;
    if (!username_available($username)) return null;
    $user = [
        'id'         => gen_id('u_'),
        'username'   => $username,
        'name'       => $name,
        'email'      => $email,
        // No password_hash yet — set when the invitee clicks the link.
        // attempt_login() already refuses users with an empty hash, so
        // there's no window where the account is passwordless-but-loggable.
        'created_at' => date('Y-m-d H:i:s'),
    ];
    $users = load_users();
    $users[] = $user;
    if (!save_users($users)) return null;
    return $user;
}

function user_has_password(array $user): bool
{
    return trim((string) ($user['password_hash'] ?? '')) !== '';
}

function update_user(string $id, array $fields): bool
{
    return update_record_by_id(APP_USERS_FILE, $id, function (array &$u) use ($fields) {
        if (array_key_exists('name', $fields))  $u['name']  = trim((string) $fields['name']);
        if (array_key_exists('email', $fields)) $u['email'] = trim((string) $fields['email']);
        if (!empty($fields['password'])) {
            $u['password_hash'] = password_hash((string) $fields['password'], PASSWORD_DEFAULT);
        }
        if (array_key_exists('last_login_at', $fields)) {
            $u['last_login_at'] = (string) $fields['last_login_at'];
        }
    });
}

function no_users_yet(): bool
{
    return load_users() === [];
}
