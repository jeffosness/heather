<?php
declare(strict_types=1);

require_once __DIR__ . '/json_store.php';

/**
 * Student accounts — separate from admin users. Same invite-based
 * signup pattern (Heather invites via email, student sets their own
 * password on first click).
 *
 *   { id, name, email, username, password_hash?, cohort?,
 *     created_at, last_login_at?, reset_token?, reset_token_expires? }
 *
 * Sessions are keyed with heather_student_* so a signed-in student
 * and a signed-in admin on the same browser don't collide.
 */

function load_students(): array
{
    $data = read_json_file(APP_STUDENTS_FILE, []);
    if (!is_array($data)) return [];
    usort($data, fn($a, $b) => strcasecmp((string) ($a['name'] ?? ''), (string) ($b['name'] ?? '')));
    return $data;
}

function save_students(array $records): bool
{
    return write_json_file(APP_STUDENTS_FILE, array_values($records));
}

function find_student(string $id): ?array
{
    foreach (load_students() as $s) {
        if (($s['id'] ?? '') === $id) return $s;
    }
    return null;
}

function find_student_by_username(string $username): ?array
{
    $username = strtolower(trim($username));
    if ($username === '') return null;
    foreach (load_students() as $s) {
        if (strtolower((string) ($s['username'] ?? '')) === $username) return $s;
    }
    return null;
}

function find_student_by_email(string $email): ?array
{
    $email = strtolower(trim($email));
    if ($email === '') return null;
    foreach (load_students() as $s) {
        if (strtolower((string) ($s['email'] ?? '')) === $email) return $s;
    }
    return null;
}

function student_username_available(string $username, string $excludeId = ''): bool
{
    $existing = find_student_by_username($username);
    if (!$existing) return true;
    return ($excludeId !== '' && (string) $existing['id'] === $excludeId);
}

/**
 * Invite a new student — creates the record without a password. Caller
 * is expected to send_student_invite_email() after this returns.
 */
function invite_student(array $fields): ?array
{
    $username = strtolower(trim((string) ($fields['username'] ?? '')));
    $name  = trim((string) ($fields['name']  ?? ''));
    $email = trim((string) ($fields['email'] ?? ''));
    $cohort= trim((string) ($fields['cohort'] ?? ''));
    if ($username === '' || $name === '' || $email === '') return null;
    if (!student_username_available($username)) return null;
    $student = [
        'id'         => gen_id('st_'),
        'name'       => $name,
        'username'   => $username,
        'email'      => $email,
        'cohort'     => $cohort,
        'created_at' => date('Y-m-d H:i:s'),
    ];
    $items = load_students();
    $items[] = $student;
    if (!save_students($items)) return null;
    return $student;
}

function update_student(string $id, array $fields): bool
{
    return update_record_by_id(APP_STUDENTS_FILE, $id, function (array &$s) use ($fields) {
        if (array_key_exists('name', $fields))   $s['name']   = trim((string) $fields['name']);
        if (array_key_exists('email', $fields))  $s['email']  = trim((string) $fields['email']);
        if (array_key_exists('cohort', $fields)) $s['cohort'] = trim((string) $fields['cohort']);
        if (!empty($fields['password'])) {
            $s['password_hash'] = password_hash((string) $fields['password'], PASSWORD_DEFAULT);
        }
        if (array_key_exists('last_login_at', $fields)) {
            $s['last_login_at'] = (string) $fields['last_login_at'];
        }
        if (array_key_exists('reset_token', $fields)) {
            $s['reset_token'] = (string) $fields['reset_token'];
        }
        if (array_key_exists('reset_token_expires', $fields)) {
            $s['reset_token_expires'] = (int) $fields['reset_token_expires'];
        }
    });
}

function delete_student(string $id): bool
{
    $items = array_values(array_filter(load_students(), fn($s) => ($s['id'] ?? '') !== $id));
    return save_students($items);
}

function student_has_password(array $student): bool
{
    return trim((string) ($student['password_hash'] ?? '')) !== '';
}
