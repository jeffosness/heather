<?php
declare(strict_types=1);

require_once __DIR__ . '/json_store.php';

/**
 * Preceptors — the supervising CSTs students scrub under. Same shape
 * as doctors_service; kept separate because they're a distinct role
 * with a distinct admin page and count column.
 *
 *   { id, name, created_at, updated_at? }
 */

function load_preceptors(): array
{
    $data = read_json_file(APP_PRECEPTORS_FILE, []);
    if (!is_array($data)) return [];
    usort($data, fn($a, $b) => strcasecmp((string) ($a['name'] ?? ''), (string) ($b['name'] ?? '')));
    return $data;
}

function save_preceptors(array $records): bool
{
    return write_json_file(APP_PRECEPTORS_FILE, array_values($records));
}

function find_preceptor(string $id): ?array
{
    foreach (load_preceptors() as $p) {
        if (($p['id'] ?? '') === $id) return $p;
    }
    return null;
}

function find_preceptor_by_name(string $name): ?array
{
    $needle = strtolower(trim($name));
    if ($needle === '') return null;
    foreach (load_preceptors() as $p) {
        if (strtolower(trim((string) ($p['name'] ?? ''))) === $needle) return $p;
    }
    return null;
}

function find_or_create_preceptor_by_name(string $name): ?string
{
    $name = trim($name);
    if ($name === '') return null;
    $existing = find_preceptor_by_name($name);
    if ($existing) return (string) $existing['id'];
    $rec = [
        'id'         => gen_id('pr_'),
        'name'       => $name,
        'created_at' => date('Y-m-d H:i:s'),
    ];
    $items = load_preceptors();
    $items[] = $rec;
    if (!save_preceptors($items)) return null;
    return $rec['id'];
}

function update_preceptor(string $id, array $fields): bool
{
    return update_record_by_id(APP_PRECEPTORS_FILE, $id, function (array &$p) use ($fields) {
        if (array_key_exists('name', $fields)) $p['name'] = trim((string) $fields['name']);
        $p['updated_at'] = date('Y-m-d H:i:s');
    });
}

function delete_preceptor(string $id): bool
{
    $items = array_values(array_filter(load_preceptors(), fn($p) => ($p['id'] ?? '') !== $id));
    return save_preceptors($items);
}

function preceptor_case_count(string $id): int
{
    static $counts = null;
    if ($counts === null) {
        require_once __DIR__ . '/cases_service.php';
        $counts = [];
        foreach (load_cases() as $c) {
            $pid = (string) ($c['preceptor_id'] ?? '');
            if ($pid === '') continue;
            $counts[$pid] = ($counts[$pid] ?? 0) + 1;
        }
    }
    return (int) ($counts[$id] ?? 0);
}

/**
 * Rating aggregate for one preceptor: [avg, rating_count].
 * Ratings of 0 are excluded (treated as unrated). Cached-per-request.
 */
function preceptor_rating_stats(string $id): array
{
    static $stats = null;
    if ($stats === null) {
        require_once __DIR__ . '/cases_service.php';
        $sums = []; $counts = [];
        foreach (load_cases() as $c) {
            $pid = (string) ($c['preceptor_id'] ?? '');
            if ($pid === '') continue;
            $r = (int) ($c['preceptor_rating'] ?? 0);
            if ($r < 1 || $r > 5) continue;
            $sums[$pid]   = ($sums[$pid] ?? 0) + $r;
            $counts[$pid] = ($counts[$pid] ?? 0) + 1;
        }
        $stats = ['sums' => $sums, 'counts' => $counts];
    }
    $count = (int) ($stats['counts'][$id] ?? 0);
    $avg = $count > 0 ? round($stats['sums'][$id] / $count, 2) : 0.0;
    return ['avg' => $avg, 'count' => $count];
}
