<?php
declare(strict_types=1);

require_once __DIR__ . '/json_store.php';
require_once __DIR__ . '/users_service.php';
require_once __DIR__ . '/notifications.php';

/**
 * Password-reset flow. Two entry points:
 *
 *   request_password_reset($usernameOrEmail): void
 *     — Called from public/forgot_password.php on POST. Looks the user up,
 *       generates a one-hour token, writes it to the user record, sends
 *       a reset link to their email. Silent on lookup miss — never reveals
 *       whether a username or email exists on this site (rate-limits guessing).
 *
 *   consume_password_reset_token($token): ?array
 *     — Called by public/reset_password.php to validate a token before
 *       showing the new-password form and again on submit. Returns the user
 *       record if the token matches and hasn't expired, null otherwise.
 *
 *   apply_password_reset($token, $newPassword): bool
 *     — Called on submit. Verifies the token again, hashes the new
 *       password, clears the token fields. Returns success.
 *
 * User schema adds:
 *   reset_token         — hex string, cleared after use
 *   reset_token_expires — unix timestamp, cleared after use
 */

const PASSWORD_RESET_TTL_SECONDS = 3600; // 1 hour
const INVITE_TTL_SECONDS         = 7 * 24 * 3600; // 7 days — invites need slack for people to notice the email

function request_password_reset(string $usernameOrEmail): void
{
    $q = trim($usernameOrEmail);
    if ($q === '') return;

    // Match on username first, then email. Case-insensitive on email so
    // "Heather@..." matches a stored "heather@...".
    $user = find_user_by_username($q);
    if (!$user) {
        $qLower = strtolower($q);
        foreach (load_users() as $u) {
            if (strtolower(trim((string) ($u['email'] ?? ''))) === $qLower) {
                $user = $u;
                break;
            }
        }
    }
    if (!$user) return;

    $email = trim((string) ($user['email'] ?? ''));
    if ($email === '') return; // no way to send the link

    $token = bin2hex(random_bytes(32));
    $expires = time() + PASSWORD_RESET_TTL_SECONDS;
    update_user((string) $user['id'], [
        'reset_token'         => $token,
        'reset_token_expires' => $expires,
    ]);

    $link = 'https://' . ($_SERVER['HTTP_HOST'] ?? 'heather.osness.org')
          . '/reset_password.php?token=' . urlencode($token);
    $body = "Hi " . (string) ($user['name'] ?? '') . ",\n\n"
          . "Someone (hopefully you) asked to reset your password on "
          . ($_SERVER['HTTP_HOST'] ?? 'heather.osness.org') . ".\n\n"
          . "To set a new password, click this link within the next hour:\n\n"
          . $link . "\n\n"
          . "If you didn't request this, ignore this email — your password stays the same.\n";
    notify_send_one($email, 'Reset your password', $body, 'password_reset');
}

function consume_password_reset_token(string $token): ?array
{
    $token = trim($token);
    if ($token === '' || strlen($token) < 32) return null;
    foreach (load_users() as $u) {
        $storedToken = (string) ($u['reset_token'] ?? '');
        if ($storedToken === '' || !hash_equals($storedToken, $token)) continue;
        $expires = (int) ($u['reset_token_expires'] ?? 0);
        if ($expires < time()) return null;
        return $u;
    }
    return null;
}

function apply_password_reset(string $token, string $newPassword): bool
{
    if (strlen($newPassword) < 8) return false;
    $user = consume_password_reset_token($token);
    if (!$user) return false;
    return update_record_by_id(APP_USERS_FILE, (string) $user['id'], function (array &$u) use ($newPassword) {
        $u['password_hash'] = password_hash($newPassword, PASSWORD_DEFAULT);
        $u['reset_token'] = '';
        $u['reset_token_expires'] = 0;
    });
}

/**
 * Generate a fresh invite token for a user (who typically has no
 * password_hash yet), stamp it on the record, email them a link to
 * set their password. Reuses the reset_token fields — the /reset_password.php
 * page auto-detects "invite mode" via empty password_hash and switches
 * its copy accordingly.
 *
 * Safe to call again to resend the invite — replaces the previous token
 * so old links stop working.
 */
function send_invite_email(array $user): bool
{
    $email = trim((string) ($user['email'] ?? ''));
    if ($email === '') return false;

    $token = bin2hex(random_bytes(32));
    $expires = time() + INVITE_TTL_SECONDS;
    update_user((string) $user['id'], [
        'reset_token'         => $token,
        'reset_token_expires' => $expires,
    ]);

    $host = $_SERVER['HTTP_HOST'] ?? 'heather.osness.org';
    $link = 'https://' . $host . '/reset_password.php?token=' . urlencode($token);
    $body = "Hi " . (string) ($user['name'] ?? '') . ",\n\n"
          . "You've been invited to " . $host . ".\n\n"
          . "Click this link to set your password and get started:\n\n"
          . $link . "\n\n"
          . "The link works for 7 days. Your username is: " . (string) ($user['username'] ?? '') . "\n";
    return notify_send_one($email, "You're invited to " . $host, $body, 'invite');
}

/**
 * Student-side twin of send_invite_email. Uses the students table
 * and points the invite link at /students/set_password.php so the
 * flow lives entirely inside the student portal.
 */
function send_student_invite_email(array $student): bool
{
    require_once __DIR__ . '/students_service.php';
    $email = trim((string) ($student['email'] ?? ''));
    if ($email === '') return false;

    $token = bin2hex(random_bytes(32));
    $expires = time() + INVITE_TTL_SECONDS;
    update_student((string) $student['id'], [
        'reset_token'         => $token,
        'reset_token_expires' => $expires,
    ]);

    $host = $_SERVER['HTTP_HOST'] ?? 'heather.osness.org';
    $link = 'https://' . $host . '/students/set_password.php?token=' . urlencode($token);
    $body = "Hi " . (string) ($student['name'] ?? '') . ",\n\n"
          . "You've been enrolled in the Surgical Technology case-log tracker at " . $host . ".\n\n"
          . "Click this link to set your password and get started:\n\n"
          . $link . "\n\n"
          . "The link works for 7 days. Your username is: " . (string) ($student['username'] ?? '') . "\n";
    return notify_send_one($email, "Welcome to the case log — set your password", $body, 'student_invite');
}

/**
 * Same reset flow but for a student token. Looks up in the students
 * table.
 */
function consume_student_reset_token(string $token): ?array
{
    require_once __DIR__ . '/students_service.php';
    $token = trim($token);
    if ($token === '' || strlen($token) < 32) return null;
    foreach (load_students() as $s) {
        $storedToken = (string) ($s['reset_token'] ?? '');
        if ($storedToken === '' || !hash_equals($storedToken, $token)) continue;
        $expires = (int) ($s['reset_token_expires'] ?? 0);
        if ($expires < time()) return null;
        return $s;
    }
    return null;
}

function apply_student_password_reset(string $token, string $newPassword): bool
{
    if (strlen($newPassword) < 8) return false;
    $student = consume_student_reset_token($token);
    if (!$student) return false;
    return update_record_by_id(APP_STUDENTS_FILE, (string) $student['id'], function (array &$s) use ($newPassword) {
        $s['password_hash']       = password_hash($newPassword, PASSWORD_DEFAULT);
        $s['reset_token']         = '';
        $s['reset_token_expires'] = 0;
    });
}
