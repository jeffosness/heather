<?php
declare(strict_types=1);

require_once __DIR__ . '/json_store.php';

/**
 * Surgical specialties. One is flagged is_general (General Surgery) —
 * every other specialty is treated as a "specialty case" for CST 7e
 * accounting. Heather can edit / add / remove from admin.
 *
 *   { id, name, is_general, sort_order }
 */

/**
 * Seed the specialty list on first read. Uses the CST 7e specialty
 * families as the starting set — Heather can adjust in admin.
 */
function seed_specialties_if_empty(): void
{
    $existing = read_json_file(APP_SPECIALTIES_FILE, null);
    if (is_array($existing)) return; // already seeded (empty array is intentional)
    $seed = [
        ['name' => 'General Surgery',                  'is_general' => true,  'sort_order' => 1],
        ['name' => 'Cardiothoracic',                   'is_general' => false, 'sort_order' => 10],
        ['name' => 'Genitourinary',                    'is_general' => false, 'sort_order' => 20],
        ['name' => 'Neurologic',                       'is_general' => false, 'sort_order' => 30],
        ['name' => 'Obstetric and Gynecologic',        'is_general' => false, 'sort_order' => 40],
        ['name' => 'Ophthalmic',                       'is_general' => false, 'sort_order' => 50],
        ['name' => 'Oral / Maxillofacial',             'is_general' => false, 'sort_order' => 60],
        ['name' => 'Orthopedic',                       'is_general' => false, 'sort_order' => 70],
        ['name' => 'Otorhinolaryngologic',             'is_general' => false, 'sort_order' => 80],
        ['name' => 'Peripheral Vascular',              'is_general' => false, 'sort_order' => 90],
        ['name' => 'Plastic / Reconstructive',         'is_general' => false, 'sort_order' => 100],
        ['name' => 'Procurement / Transplant',         'is_general' => false, 'sort_order' => 110],
    ];
    $records = [];
    foreach ($seed as $s) {
        $records[] = [
            'id'          => gen_id('sp_'),
            'name'        => $s['name'],
            'is_general'  => $s['is_general'],
            'sort_order'  => $s['sort_order'],
            'created_at'  => date('Y-m-d H:i:s'),
        ];
    }
    write_json_file(APP_SPECIALTIES_FILE, $records);
}

function load_specialties(): array
{
    seed_specialties_if_empty();
    $data = read_json_file(APP_SPECIALTIES_FILE, []);
    if (!is_array($data)) return [];
    usort($data, function ($a, $b) {
        $so = ((int) ($a['sort_order'] ?? 0)) <=> ((int) ($b['sort_order'] ?? 0));
        if ($so !== 0) return $so;
        return strcasecmp((string) ($a['name'] ?? ''), (string) ($b['name'] ?? ''));
    });
    return $data;
}

function save_specialties(array $records): bool
{
    return write_json_file(APP_SPECIALTIES_FILE, array_values($records));
}

function find_specialty(string $id): ?array
{
    foreach (load_specialties() as $s) {
        if (($s['id'] ?? '') === $id) return $s;
    }
    return null;
}

function add_specialty(array $fields): ?array
{
    $name = trim((string) ($fields['name'] ?? ''));
    if ($name === '') return null;
    $rec = [
        'id'         => gen_id('sp_'),
        'name'       => $name,
        'is_general' => !empty($fields['is_general']),
        'sort_order' => (int) ($fields['sort_order'] ?? 100),
        'created_at' => date('Y-m-d H:i:s'),
    ];
    $items = load_specialties();
    $items[] = $rec;
    if (!save_specialties($items)) return null;
    return $rec;
}

function update_specialty(string $id, array $fields): bool
{
    return update_record_by_id(APP_SPECIALTIES_FILE, $id, function (array &$s) use ($fields) {
        if (array_key_exists('name', $fields))       $s['name']       = trim((string) $fields['name']);
        if (array_key_exists('is_general', $fields)) $s['is_general'] = (bool) $fields['is_general'];
        if (array_key_exists('sort_order', $fields)) $s['sort_order'] = (int) $fields['sort_order'];
    });
}

function delete_specialty(string $id): bool
{
    $items = array_values(array_filter(load_specialties(), fn($s) => ($s['id'] ?? '') !== $id));
    return save_specialties($items);
}
