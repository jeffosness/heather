<?php
declare(strict_types=1);

require_once __DIR__ . '/students_service.php';

/**
 * Student-portal authentication. Completely separate from the admin
 * auth in auth.php — different session keys so a student and an admin
 * on the same browser don't collide.
 *
 * Session keys:
 *   heather_student_logged_in — bool
 *   heather_student_id        — string
 */

function is_student_logged_in(): bool
{
    return !empty($_SESSION['heather_student_logged_in']) && !empty($_SESSION['heather_student_id']);
}

function current_student(): ?array
{
    if (!is_student_logged_in()) return null;
    return find_student((string) $_SESSION['heather_student_id']);
}

function require_student(): void
{
    if (!is_student_logged_in()) {
        header('Location: /students/login.php');
        exit;
    }
}

function attempt_student_login(string $usernameOrEmail, string $password): bool
{
    if ($usernameOrEmail === '' || $password === '') return false;
    $student = find_student_by_username($usernameOrEmail) ?: find_student_by_email($usernameOrEmail);
    if (!$student) return false;
    $hash = (string) ($student['password_hash'] ?? '');
    if ($hash === '' || !password_verify($password, $hash)) return false;
    session_regenerate_id(true);
    $_SESSION['heather_student_logged_in'] = true;
    $_SESSION['heather_student_id']        = (string) $student['id'];
    update_student((string) $student['id'], ['last_login_at' => date('Y-m-d H:i:s')]);
    return true;
}

function logout_student(): void
{
    unset($_SESSION['heather_student_logged_in'], $_SESSION['heather_student_id']);
    session_regenerate_id(true);
}
