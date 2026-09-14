<?php
declare(strict_types=1);

require_once __DIR__ . '/json_store.php';
require_once __DIR__ . '/specialties_service.php';

/**
 * Program-specific graduation requirements — the "merit badges" Heather
 * defines on top of the CST 7e minimums. Each row is one rule; a student
 * has to have at least N matching cases to check it off.
 *
 *   { id, type: 'specialty'|'procedure',
 *     specialty_id?,        // when type=specialty
 *     procedure_name?,      // when type=procedure (case-insensitive substring)
 *     role: 'first_scrub'|'second_scrub'|'any',
 *     count_required: int,
 *     label?, sort_order, created_at }
 *
 * Role semantics:
 *   'first_scrub'  → only first-scrub cases count
 *   'second_scrub' → only second-scrub cases count
 *   'any'          → first OR second scrub (observer excluded)
 */

const REQ_TYPES = ['specialty', 'procedure'];
const REQ_ROLES = ['first_scrub', 'second_scrub', 'any'];

function role_short_label(string $role): string
{
    return [
        'first_scrub'  => '1st scrub',
        'second_scrub' => '2nd scrub',
        'any'          => 'any role',
    ][$role] ?? $role;
}

function load_requirements(): array
{
    $data = read_json_file(APP_REQUIREMENTS_FILE, []);
    if (!is_array($data)) return [];
    usort($data, function ($a, $b) {
        $so = ((int) ($a['sort_order'] ?? 0)) <=> ((int) ($b['sort_order'] ?? 0));
        if ($so !== 0) return $so;
        return strcasecmp((string) ($a['label'] ?? ''), (string) ($b['label'] ?? ''));
    });
    return $data;
}

function save_requirements(array $items): bool
{
    return write_json_file(APP_REQUIREMENTS_FILE, array_values($items));
}

function find_requirement(string $id): ?array
{
    foreach (load_requirements() as $r) {
        if (($r['id'] ?? '') === $id) return $r;
    }
    return null;
}

/**
 * Build a display label for a requirement — from Heather's custom
 * label if she set one, otherwise auto-generated from type + target + role.
 */
function requirement_label(array $req, ?array $specById = null): string
{
    $custom = trim((string) ($req['label'] ?? ''));
    if ($custom !== '') return $custom;
    if (($req['type'] ?? '') === 'specialty') {
        if ($specById === null) {
            $specById = [];
            foreach (load_specialties() as $s) $specById[(string) $s['id']] = $s;
        }
        $sp = $specById[(string) ($req['specialty_id'] ?? '')] ?? null;
        $name = $sp ? (string) $sp['name'] : '(deleted specialty)';
        return $name . ' (' . role_short_label((string) ($req['role'] ?? 'any')) . ')';
    }
    return (string) ($req['procedure_name'] ?? '(procedure)')
        . ' (' . role_short_label((string) ($req['role'] ?? 'any')) . ')';
}

function add_requirement(array $fields): ?array
{
    $type = trim((string) ($fields['type'] ?? ''));
    if (!in_array($type, REQ_TYPES, true)) return null;
    $role = trim((string) ($fields['role'] ?? 'any'));
    if (!in_array($role, REQ_ROLES, true)) $role = 'any';
    $count = max(1, (int) ($fields['count_required'] ?? 0));

    $rec = [
        'id'             => gen_id('req_'),
        'type'           => $type,
        'role'           => $role,
        'count_required' => $count,
        'label'          => trim((string) ($fields['label'] ?? '')),
        'sort_order'     => (int) ($fields['sort_order'] ?? 100),
        'created_at'     => date('Y-m-d H:i:s'),
    ];
    if ($type === 'specialty') {
        $sid = trim((string) ($fields['specialty_id'] ?? ''));
        if ($sid === '') return null;
        $rec['specialty_id'] = $sid;
    } else {
        $pname = trim((string) ($fields['procedure_name'] ?? ''));
        if ($pname === '') return null;
        $rec['procedure_name'] = $pname;
    }
    $items = load_requirements();
    $items[] = $rec;
    if (!save_requirements($items)) return null;
    return $rec;
}

function update_requirement(string $id, array $fields): bool
{
    return update_record_by_id(APP_REQUIREMENTS_FILE, $id, function (array &$r) use ($fields) {
        if (array_key_exists('role', $fields)) {
            $v = trim((string) $fields['role']);
            if (in_array($v, REQ_ROLES, true)) $r['role'] = $v;
        }
        if (array_key_exists('count_required', $fields)) {
            $r['count_required'] = max(1, (int) $fields['count_required']);
        }
        if (array_key_exists('label', $fields))      $r['label']      = trim((string) $fields['label']);
        if (array_key_exists('sort_order', $fields)) $r['sort_order'] = (int) $fields['sort_order'];
        if (($r['type'] ?? '') === 'specialty' && array_key_exists('specialty_id', $fields)) {
            $r['specialty_id'] = trim((string) $fields['specialty_id']);
        }
        if (($r['type'] ?? '') === 'procedure' && array_key_exists('procedure_name', $fields)) {
            $r['procedure_name'] = trim((string) $fields['procedure_name']);
        }
    });
}

function delete_requirement(string $id): bool
{
    $items = array_values(array_filter(load_requirements(), fn($r) => ($r['id'] ?? '') !== $id));
    return save_requirements($items);
}

/**
 * Does a single case match a requirement's rule?
 *   - role filter (first/second/any, with 'any' excluding observer)
 *   - target: specialty_id exact match, or procedure name substring
 */
function case_matches_requirement(array $case, array $req): bool
{
    $role = (string) ($case['role'] ?? '');
    $wantRole = (string) ($req['role'] ?? 'any');
    if ($wantRole === 'first_scrub'  && $role !== 'first_scrub')  return false;
    if ($wantRole === 'second_scrub' && $role !== 'second_scrub') return false;
    if ($wantRole === 'any'          && $role === 'observer')     return false;

    if (($req['type'] ?? '') === 'specialty') {
        return (string) ($case['specialty_id'] ?? '') === (string) ($req['specialty_id'] ?? '');
    }
    $target = trim((string) ($req['procedure_name'] ?? ''));
    $subject = (string) ($case['procedure'] ?? '');
    if ($target === '') return false;
    return stripos($subject, $target) !== false;
}

/**
 * Score every requirement against a student's cases. Returns a list of
 *   ['req' => ..., 'have' => N, 'need' => N, 'met' => bool, 'label' => '...']
 * in the same order load_requirements() returned them (sorted by sort_order).
 */
function requirements_for_student(string $studentId): array
{
    require_once __DIR__ . '/cases_service.php';
    $reqs  = load_requirements();
    if ($reqs === []) return [];
    $cases = cases_for_student($studentId);
    $specById = [];
    foreach (load_specialties() as $s) $specById[(string) $s['id']] = $s;

    $out = [];
    foreach ($reqs as $req) {
        $have = 0;
        foreach ($cases as $c) {
            if (case_matches_requirement($c, $req)) $have++;
        }
        $need = (int) ($req['count_required'] ?? 0);
        $out[] = [
            'req'   => $req,
            'have'  => $have,
            'need'  => $need,
            'met'   => $have >= $need,
            'label' => requirement_label($req, $specById),
        ];
    }
    return $out;
}
