<?php
declare(strict_types=1);

function csrf_token(): string
{
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return (string) $_SESSION['csrf_token'];
}

function csrf_field(): void
{
    echo '<input type="hidden" name="csrf" value="' . htmlspecialchars(csrf_token()) . '">';
}

function csrf_valid(string $provided): bool
{
    $expected = (string) ($_SESSION['csrf_token'] ?? '');
    return $expected !== '' && hash_equals($expected, $provided);
}

function csrf_check(): void
{
    if (!csrf_valid((string) ($_POST['csrf'] ?? ''))) {
        http_response_code(403);
        echo 'Forbidden: invalid or missing CSRF token. Please reload and try again.';
        exit;
    }
}

function csrf_check_ajax(): void
{
    $provided = (string) ($_POST['csrf'] ?? ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? ''));
    if (!csrf_valid($provided)) {
        http_response_code(403);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['error' => 'Invalid CSRF token']);
        exit;
    }
}
