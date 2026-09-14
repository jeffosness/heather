<?php
declare(strict_types=1);

require_once __DIR__ . '/json_store.php';

/**
 * Doctors — every attending a student has scrubbed with. Records are
 * auto-created when a student types a new name on a case; Heather can
 * rename or delete from admin. Usage counts drive the "who helps the
 * most" view.
 *
 *   { id, name, created_at, updated_at? }
 */

function load_doctors(): array
{
    $data = read_json_file(APP_DOCTORS_FILE, []);
    if (!is_array($data)) return [];
    usort($data, fn($a, $b) => strcasecmp((string) ($a['name'] ?? ''), (string) ($b['name'] ?? '')));
    return $data;
}

function save_doctors(array $records): bool
{
    return write_json_file(APP_DOCTORS_FILE, array_values($records));
}

function find_doctor(string $id): ?array
{
    foreach (load_doctors() as $d) {
        if (($d['id'] ?? '') === $id) return $d;
    }
    return null;
}

function find_doctor_by_name(string $name): ?array
{
    $needle = strtolower(trim($name));
    if ($needle === '') return null;
    foreach (load_doctors() as $d) {
        if (strtolower(trim((string) ($d['name'] ?? ''))) === $needle) return $d;
    }
    return null;
}

/**
 * The workhorse for case entry: hand it whatever the student typed,
 * get back an ID. Existing match (case-insensitive, trimmed) → same
 * record; unknown name → create a new record on the spot. Empty
 * string → null (caller can leave doctor_id unset).
 */
function find_or_create_doctor_by_name(string $name): ?string
{
    $name = trim($name);
    if ($name === '') return null;
    $existing = find_doctor_by_name($name);
    if ($existing) return (string) $existing['id'];
    $rec = [
        'id'         => gen_id('dr_'),
        'name'       => $name,
        'created_at' => date('Y-m-d H:i:s'),
    ];
    $items = load_doctors();
    $items[] = $rec;
    if (!save_doctors($items)) return null;
    return $rec['id'];
}

function update_doctor(string $id, array $fields): bool
{
    return update_record_by_id(APP_DOCTORS_FILE, $id, function (array &$d) use ($fields) {
        if (array_key_exists('name', $fields)) $d['name'] = trim((string) $fields['name']);
        $d['updated_at'] = date('Y-m-d H:i:s');
    });
}

function delete_doctor(string $id): bool
{
    $items = array_values(array_filter(load_doctors(), fn($d) => ($d['id'] ?? '') !== $id));
    return save_doctors($items);
}

/**
 * How many cases reference this doctor. O(cases) — fine at Heather's
 * scale, and cached-per-request via a static so page renders that
 * show all doctors with counts don't re-scan for every row.
 */
function doctor_case_count(string $id): int
{
    static $counts = null;
    if ($counts === null) {
        require_once __DIR__ . '/cases_service.php';
        $counts = [];
        foreach (load_cases() as $c) {
            $did = (string) ($c['doctor_id'] ?? '');
            if ($did === '') continue;
            $counts[$did] = ($counts[$did] ?? 0) + 1;
        }
    }
    return (int) ($counts[$id] ?? 0);
}
