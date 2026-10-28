<?php
declare(strict_types=1);

require_once __DIR__ . '/json_store.php';
require_once __DIR__ . '/feedback_service.php';

/**
 * Lesson reviews: a draft lesson storyboard Heather reviews on the site
 * before it gets built. The draft ships in git at
 * includes/lesson_reviews/<id>.json (steps, proposed answers, questions);
 * her answers live at protected/heather/lesson_reviews/<id>.json:
 *   { steps: {<step id>: {verdict: 'ok'|'change', note}},
 *     questions: {<q id>: {choice, other}}, general, saved_at, sent_at }
 * "Send to Jeff" posts her answers as a comment on the draft's GitHub issue.
 */

function lesson_review_ids(): array
{
    $ids = [];
    foreach (glob(__DIR__ . '/lesson_reviews/*.json') ?: [] as $f) $ids[] = basename($f, '.json');
    sort($ids);
    return $ids;
}

function find_lesson_review(string $id): ?array
{
    if (!preg_match('/^[a-z0-9-]+$/', $id)) return null;
    $r = read_json_file(__DIR__ . '/lesson_reviews/' . $id . '.json');
    return is_array($r) ? $r : null;
}

function lesson_review_answers(string $id): array
{
    $a = read_json_file(APP_LESSON_REVIEWS_DIR . DIRECTORY_SEPARATOR . $id . '.json', []);
    return is_array($a) ? $a : [];
}

/** Reviews she hasn't sent back yet: [[review, answers], ...] */
function pending_lesson_reviews(): array
{
    $out = [];
    foreach (lesson_review_ids() as $id) {
        $r = find_lesson_review($id);
        if (!$r) continue;
        $a = lesson_review_answers($id);
        if (empty($a['sent_at'])) $out[] = [$r, $a];
    }
    return $out;
}

/** Build the answers record from a POST, keeping only known step/question ids. */
function lesson_review_answers_from_post(array $review, array $post, array $prev): array
{
    $a = ['steps' => [], 'questions' => [], 'general' => trim((string) ($post['general'] ?? ''))];
    foreach ($review['steps'] ?? [] as $s) {
        $sid = (string) $s['id'];
        $verdict = (string) ($post['verdict'][$sid] ?? '');
        $note = trim((string) ($post['note'][$sid] ?? ''));
        if (!in_array($verdict, ['ok', 'change'], true)) $verdict = '';
        if ($verdict !== '' || $note !== '') $a['steps'][$sid] = ['verdict' => $verdict, 'note' => $note];
    }
    foreach ($review['questions'] ?? [] as $q) {
        $qid = (string) $q['id'];
        $choice = (string) ($post['q'][$qid] ?? '');
        if ($choice !== '' && !in_array($choice, $q['options'] ?? [], true)) $choice = '';
        $other = trim((string) ($post['q_other'][$qid] ?? ''));
        if ($choice !== '' || $other !== '') $a['questions'][$qid] = ['choice' => $choice, 'other' => $other];
    }
    $a['saved_at'] = date('Y-m-d H:i:s');
    if (!empty($prev['sent_at'])) $a['sent_at'] = $prev['sent_at'];
    return $a;
}

function save_lesson_review_answers(string $id, array $answers): bool
{
    return write_json_file(APP_LESSON_REVIEWS_DIR . DIRECTORY_SEPARATOR . $id . '.json', $answers);
}

function lesson_review_comment_markdown(array $review, array $a, string $who): string
{
    $md = "**{$who} reviewed the draft** (sent from the site)\n\n### Questions\n";
    foreach ($review['questions'] ?? [] as $q) {
        $ans = $a['questions'][$q['id']] ?? [];
        $parts = array_filter([(string) ($ans['choice'] ?? ''), (string) ($ans['other'] ?? '')], 'strlen');
        $md .= '- **' . $q['text'] . '** ' . ($parts ? implode(' — ', $parts) : '_(no answer)_') . "\n";
    }
    $md .= "\n### Steps\n";
    foreach ($review['steps'] ?? [] as $i => $s) {
        $ans = $a['steps'][$s['id']] ?? [];
        $v = ['ok' => '👍 Looks right', 'change' => '✏️ Needs a change'][$ans['verdict'] ?? ''] ?? '— not marked';
        $md .= sprintf("%d. **%s**: %s", $i + 1, $s['title'], $v);
        if (($ans['note'] ?? '') !== '') $md .= "\n   > " . str_replace("\n", "\n   > ", $ans['note']);
        $md .= "\n";
    }
    if (($a['general'] ?? '') !== '') $md .= "\n### Anything else\n" . $a['general'] . "\n";
    return $md;
}
