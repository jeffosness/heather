<?php
declare(strict_types=1);
require_once __DIR__ . '/../../includes/bootstrap.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/presentations_service.php';

require_login();

/*
 * Full-screen presenter. Two ways in:
 *   ?session=<id>  — a scheduled run; progress + result saved to the session
 *   ?lesson=<id>   — rehearsal; progress lives only in this browser
 * Add &notes=1 for the presenter-notes window (synced via BroadcastChannel).
 */

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check_ajax();
    header('Content-Type: application/json; charset=utf-8');
    $sessionId = (string) ($_POST['session'] ?? '');
    $state = json_decode((string) ($_POST['state'] ?? ''), true);
    if (find_session($sessionId) === null || !is_array($state) || strlen((string) $_POST['state']) > 256000) {
        http_response_code(400);
        echo json_encode(['ok' => false]);
        exit;
    }
    $fields = ['state' => $state];
    $fields['result'] = is_array($state['result'] ?? null) ? $state['result'] + ['finished_at' => date('Y-m-d H:i:s')] : null;
    $ok = update_session($sessionId, $fields);
    if (!$ok) http_response_code(500);
    echo json_encode(['ok' => $ok]);
    exit;
}

$session = null;
$sessionId = (string) ($_GET['session'] ?? '');
if ($sessionId !== '') {
    $session = find_session($sessionId);
    $lesson = $session ? find_lesson((string) $session['lesson_id']) : null;
} else {
    $lesson = find_lesson((string) ($_GET['lesson'] ?? ''));
}
$type = $lesson ? presentation_type((string) ($lesson['type'] ?? '')) : null;
if ($lesson === null || $type === null) {
    http_response_code(404);
    echo 'Presentation not found. <a href="/admin/presentations.php">Back to presentations</a>';
    exit;
}

$isNotes = !empty($_GET['notes']);
$selfUrl = '/admin/present.php?' . ($session ? 'session=' . urlencode($sessionId) : 'lesson=' . urlencode((string) $lesson['id']));
$config = [
    'type'      => (string) $lesson['type'],
    'lesson'    => $lesson,
    'sessionId' => $session['id'] ?? null,
    'label'     => $session ? trim(date('D M j', strtotime((string) $session['date'])) . ' · ' . (string) ($session['label'] ?? ''), ' ·') : 'Rehearsal',
    'stateKey'  => $session ? 'present:session:' . $session['id'] : 'present:rehearse:' . $lesson['id'],
    'saved'     => $session['state'] ?? null,
    'resetAt'   => (int) ($session['reset_at'] ?? 0),
    'csrf'      => csrf_token(),
    'saveUrl'   => '/admin/present.php',
    'notesUrl'  => $selfUrl . '&notes=1',
    'exitUrl'   => '/admin/presentations.php',
    'isNotes'   => $isNotes,
];
$asset = fn(string $f) => '/admin/assets/present/' . $f . '?v=' . (int) @filemtime(__DIR__ . '/assets/present/' . $f);
?><!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= $isNotes ? 'Notes · ' : '' ?><?= htmlspecialchars((string) $lesson['title']) ?></title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;600;800&family=Playfair+Display:wght@700;800&display=swap" rel="stylesheet">
<link href="<?= $asset('present.css') ?>" rel="stylesheet">
</head>
<body class="<?= $isNotes ? 'is-notes' : 'is-stage' ?>">
<div id="app"></div>
<script>window.PRESENT = <?= json_encode($config, JSON_HEX_TAG | JSON_HEX_AMP | JSON_UNESCAPED_UNICODE) ?>;</script>
<script src="<?= $asset('core.js') ?>"></script>
<script src="<?= $asset((string) $type['player']) ?>"></script>
<script>Present.start();</script>
</body>
</html>
