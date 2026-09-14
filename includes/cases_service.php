<?php
declare(strict_types=1);

require_once __DIR__ . '/json_store.php';

/**
 * Surgical case log entries. One row = one case a student scrubbed.
 * Everything about the student's CST 7e progress is derived by scanning
 * these rows via case_progress.php.
 *
 *   { id, student_id, case_date, specialty_id, procedure,
 *     doctor, preceptor, role, notes, created_at, updated_at? }
 *
 * role is one of: 'first_scrub' | 'second_scrub' | 'observer'
 * (Observer cases are logged for the student's own record but do not
 * count toward the 120-case total per CST 7e.)
 */

const CASE_ROLES = ['first_scrub', 'second_scrub', 'observer'];

function role_label(string $role): string
{
    return [
        'first_scrub'  => 'First scrub',
        'second_scrub' => 'Second scrub',
        'observer'     => 'Observer',
    ][$role] ?? $role;
}

function load_cases(): array
{
    $data = read_json_file(APP_CASES_FILE, []);
    if (!is_array($data)) return [];
    return $data;
}

function save_cases(array $records): bool
{
    return write_json_file(APP_CASES_FILE, array_values($records));
}

function cases_for_student(string $studentId): array
{
    $items = array_values(array_filter(load_cases(), fn($c) => ($c['student_id'] ?? '') === $studentId));
    // Newest first — students spend most of their time reviewing what
    // they just added.
    usort($items, fn($a, $b) => strcmp((string) ($b['case_date'] ?? ''), (string) ($a['case_date'] ?? '')));
    return $items;
}

function find_case(string $id): ?array
{
    foreach (load_cases() as $c) {
        if (($c['id'] ?? '') === $id) return $c;
    }
    return null;
}

function add_case(string $studentId, array $fields): ?array
{
    $procedure = trim((string) ($fields['procedure'] ?? ''));
    $specialty = trim((string) ($fields['specialty_id'] ?? ''));
    $role      = trim((string) ($fields['role'] ?? ''));
    $caseDate  = trim((string) ($fields['case_date'] ?? ''));
    if ($studentId === '' || $procedure === '' || $specialty === ''
        || !in_array($role, CASE_ROLES, true) || $caseDate === '') {
        return null;
    }
    $rec = [
        'id'           => gen_id('c_'),
        'student_id'   => $studentId,
        'case_date'    => $caseDate,
        'specialty_id' => $specialty,
        'procedure'    => $procedure,
        'doctor'       => trim((string) ($fields['doctor']    ?? '')),
        'preceptor'    => trim((string) ($fields['preceptor'] ?? '')),
        'role'         => $role,
        'notes'        => trim((string) ($fields['notes']     ?? '')),
        'created_at'   => date('Y-m-d H:i:s'),
    ];
    $items = load_cases();
    $items[] = $rec;
    if (!save_cases($items)) return null;
    return $rec;
}

function update_case(string $id, array $fields): bool
{
    return update_record_by_id(APP_CASES_FILE, $id, function (array &$c) use ($fields) {
        if (array_key_exists('case_date', $fields))    $c['case_date']    = trim((string) $fields['case_date']);
        if (array_key_exists('specialty_id', $fields)) $c['specialty_id'] = trim((string) $fields['specialty_id']);
        if (array_key_exists('procedure', $fields))    $c['procedure']    = trim((string) $fields['procedure']);
        if (array_key_exists('doctor', $fields))       $c['doctor']       = trim((string) $fields['doctor']);
        if (array_key_exists('preceptor', $fields))    $c['preceptor']    = trim((string) $fields['preceptor']);
        if (array_key_exists('role', $fields)) {
            $r = trim((string) $fields['role']);
            if (in_array($r, CASE_ROLES, true)) $c['role'] = $r;
        }
        if (array_key_exists('notes', $fields))        $c['notes']        = trim((string) $fields['notes']);
        $c['updated_at'] = date('Y-m-d H:i:s');
    });
}

function delete_case(string $id): bool
{
    $items = array_values(array_filter(load_cases(), fn($c) => ($c['id'] ?? '') !== $id));
    return save_cases($items);
}

/**
 * Distinct doctors / preceptors the student has already used, for
 * autocomplete on the case entry form. Keeps typing consistent
 * ("Dr. Chen" vs "Dr Chen" vs "Sarah Chen, MD").
 */
function student_doctors(string $studentId): array
{
    $set = [];
    foreach (cases_for_student($studentId) as $c) {
        $d = trim((string) ($c['doctor'] ?? ''));
        if ($d !== '') $set[$d] = true;
    }
    $names = array_keys($set);
    sort($names, SORT_NATURAL | SORT_FLAG_CASE);
    return $names;
}

function student_preceptors(string $studentId): array
{
    $set = [];
    foreach (cases_for_student($studentId) as $c) {
        $p = trim((string) ($c['preceptor'] ?? ''));
        if ($p !== '') $set[$p] = true;
    }
    $names = array_keys($set);
    sort($names, SORT_NATURAL | SORT_FLAG_CASE);
    return $names;
}
