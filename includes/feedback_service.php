<?php
declare(strict_types=1);

require_once __DIR__ . '/json_store.php';
require_once __DIR__ . '/auth.php';

/**
 * Change-request queue. When Heather submits a request from the admin panel:
 *   1. Save it locally to feedback.json (bulletproof — never fails)
 *   2. Try to create a GitHub issue via the REST API (if a token is configured)
 *   3. Store the issue number back on the local record for cross-reference
 *
 * When Heather views the list, we fetch fresh state from GitHub so she sees
 * "under review" / "done" / "won't do" without us syncing anything manually.
 * All comments on the issue are visible to her — that's the point; if you
 * want private discussion, do it in DMs, not on the issue.
 *
 *   { id, title, body, page?, submitted_by, submitted_at,
 *     github_issue_number?, github_issue_url?, local_status: 'sent'|'send_failed' }
 */

const FEEDBACK_ISSUE_LABEL = 'heather-feedback';

function load_feedback(): array
{
    $data = read_json_file(APP_FEEDBACK_FILE, []);
    if (!is_array($data)) return [];
    usort($data, fn($a, $b) => strcmp((string) ($b['submitted_at'] ?? ''), (string) ($a['submitted_at'] ?? '')));
    return $data;
}

function save_feedback_records(array $records): bool
{
    return write_json_file(APP_FEEDBACK_FILE, array_values($records));
}

function find_feedback(string $id): ?array
{
    foreach (load_feedback() as $f) {
        if (($f['id'] ?? '') === $id) return $f;
    }
    return null;
}

function add_feedback(array $fields, string $submittedBy): ?array
{
    $title = trim((string) ($fields['title'] ?? ''));
    $body  = trim((string) ($fields['body']  ?? ''));
    $page  = trim((string) ($fields['page']  ?? ''));
    if ($title === '' || $body === '') return null;

    $record = [
        'id'           => gen_id('fb_'),
        'title'        => $title,
        'body'         => $body,
        'page'         => $page,
        'submitted_by' => $submittedBy,
        'submitted_at' => date('Y-m-d H:i:s'),
        'local_status' => 'sent',
    ];

    if (github_configured()) {
        $issueBody = "**From:** " . $submittedBy . "\n"
                   . ($page !== '' ? "**Page:** " . $page . "\n" : '')
                   . "**Submitted:** " . $record['submitted_at'] . "\n\n"
                   . "---\n\n"
                   . $body;
        $issue = github_create_issue($title, $issueBody, [FEEDBACK_ISSUE_LABEL]);
        if ($issue !== null) {
            $record['github_issue_number'] = (int) ($issue['number'] ?? 0);
            $record['github_issue_url']    = (string) ($issue['html_url'] ?? '');
        } else {
            $record['local_status'] = 'send_failed';
        }
    }

    $records = load_feedback();
    $records[] = $record;
    if (!save_feedback_records($records)) return null;
    return $record;
}

// -------- GitHub API integration --------

function github_settings(): array
{
    $s = app_settings();
    return [
        'token' => (string) ($s['github_token'] ?? ''),
        'owner' => (string) ($s['github_repo_owner'] ?? 'jeffosness'),
        'repo'  => (string) ($s['github_repo_name']  ?? 'heather'),
    ];
}

function github_configured(): bool
{
    $g = github_settings();
    return $g['token'] !== '' && $g['owner'] !== '' && $g['repo'] !== '';
}

function github_api_call(string $method, string $path, ?array $body = null): array
{
    $g = github_settings();
    if ($g['token'] === '') return [0, null];
    $url = 'https://api.github.com' . $path;
    $ch = curl_init($url);
    $headers = [
        'Accept: application/vnd.github+json',
        'Authorization: Bearer ' . $g['token'],
        'X-GitHub-Api-Version: 2022-11-28',
        'User-Agent: heather-osness-site',
    ];
    curl_setopt_array($ch, [
        CURLOPT_CUSTOMREQUEST  => $method,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER     => $headers,
        CURLOPT_TIMEOUT        => 10,
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_FOLLOWLOCATION => true,
    ]);
    if ($body !== null) {
        $headers[] = 'Content-Type: application/json';
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body));
    }
    $raw = curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if (!is_string($raw)) return [0, null];
    $decoded = json_decode($raw, true);
    return [$code, is_array($decoded) ? $decoded : null];
}

function github_create_issue(string $title, string $body, array $labels = []): ?array
{
    $g = github_settings();
    if (!github_configured()) return null;
    [$code, $data] = github_api_call('POST', "/repos/{$g['owner']}/{$g['repo']}/issues", [
        'title'  => $title,
        'body'   => $body,
        'labels' => $labels,
    ]);
    return ($code >= 200 && $code < 300 && is_array($data)) ? $data : null;
}

function github_fetch_feedback_issues(): array
{
    $g = github_settings();
    if (!github_configured()) return [];
    [$code, $data] = github_api_call('GET', "/repos/{$g['owner']}/{$g['repo']}/issues?labels=" . urlencode(FEEDBACK_ISSUE_LABEL) . "&state=all&per_page=100");
    if ($code < 200 || $code >= 300 || !is_array($data)) return [];
    $byNumber = [];
    foreach ($data as $issue) {
        if (!is_array($issue)) continue;
        $n = (int) ($issue['number'] ?? 0);
        if ($n > 0) $byNumber[$n] = $issue;
    }
    return $byNumber;
}

function github_fetch_issue(int $number): ?array
{
    $g = github_settings();
    if (!github_configured() || $number <= 0) return null;
    [$code, $data] = github_api_call('GET', "/repos/{$g['owner']}/{$g['repo']}/issues/{$number}");
    return ($code >= 200 && $code < 300 && is_array($data)) ? $data : null;
}

function github_fetch_issue_comments(int $number): array
{
    $g = github_settings();
    if (!github_configured() || $number <= 0) return [];
    [$code, $data] = github_api_call('GET', "/repos/{$g['owner']}/{$g['repo']}/issues/{$number}/comments?per_page=100");
    return ($code >= 200 && $code < 300 && is_array($data)) ? $data : [];
}

function feedback_status_label(?array $issue): array
{
    if ($issue === null) return ['label' => 'Sent', 'badge' => 'bg-secondary'];
    $state = (string) ($issue['state'] ?? '');
    $reason = (string) ($issue['state_reason'] ?? '');
    if ($state === 'closed') {
        if ($reason === 'completed') return ['label' => '✓ Done', 'badge' => 'bg-success'];
        if ($reason === 'not_planned') return ['label' => "Won't do", 'badge' => 'bg-secondary'];
        return ['label' => 'Closed', 'badge' => 'bg-secondary'];
    }
    return ['label' => 'Under review', 'badge' => 'bg-info text-dark'];
}
