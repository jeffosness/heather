<?php
declare(strict_types=1);

require_once __DIR__ . '/json_store.php';
require_once __DIR__ . '/doctors_service.php';
require_once __DIR__ . '/preceptors_service.php';

/**
 * Surgical case log entries. One row = one case a student scrubbed.
 * Everything about the student's CST 7e progress is derived by scanning
 * these rows via case_progress.php.
 *
 *   { id, student_id, case_date, specialty_id, procedure,
 *     doctor_id?, doctor_rating?, doctor_comment?,
 *     preceptor_id?, preceptor_rating?, preceptor_comment?,
 *     role, notes, created_at, updated_at? }
 *
 * doctor_rating / preceptor_rating are 1–5 (0 or missing = not rated).
 * Ratings + comments are captured at case time — Heather aggregates them
 * for end-of-year recognition (average rating + all comments per person).
 *
 * doctor_id and preceptor_id point at doctors.json / preceptors.json.
 * Case-entry forms take a free-text name (with datalist autocomplete);
 * add_case / update_case resolve the name to an ID via
 * find_or_create_doctor_by_name() so Heather sees a single canonical
 * doctor record no matter how students type it (and can rename to
 * clean up variants).
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
    // Resolve the typed name to a canonical ID — creates the doctor /
    // preceptor record on the fly if this is the first time anyone's
    // used that name. Heather can rename or delete from admin.
    $doctorId    = find_or_create_doctor_by_name((string) ($fields['doctor']    ?? ''));
    $preceptorId = find_or_create_preceptor_by_name((string) ($fields['preceptor'] ?? ''));
    // Ratings are 0–5 with 0 meaning "not rated." Clamp to that range.
    $dRating = max(0, min(5, (int) ($fields['doctor_rating']    ?? 0)));
    $pRating = max(0, min(5, (int) ($fields['preceptor_rating'] ?? 0)));
    $rec = [
        'id'                => gen_id('c_'),
        'student_id'        => $studentId,
        'case_date'         => $caseDate,
        'specialty_id'      => $specialty,
        'procedure'         => $procedure,
        'doctor_id'         => $doctorId,
        'doctor_rating'     => $dRating,
        'doctor_comment'    => trim((string) ($fields['doctor_comment']    ?? '')),
        'preceptor_id'      => $preceptorId,
        'preceptor_rating'  => $pRating,
        'preceptor_comment' => trim((string) ($fields['preceptor_comment'] ?? '')),
        'role'              => $role,
        'notes'             => trim((string) ($fields['notes'] ?? '')),
        'created_at'        => date('Y-m-d H:i:s'),
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
        if (array_key_exists('doctor', $fields))       $c['doctor_id']    = find_or_create_doctor_by_name((string) $fields['doctor']);
        if (array_key_exists('preceptor', $fields))    $c['preceptor_id'] = find_or_create_preceptor_by_name((string) $fields['preceptor']);
        if (array_key_exists('doctor_rating', $fields))     $c['doctor_rating']     = max(0, min(5, (int) $fields['doctor_rating']));
        if (array_key_exists('doctor_comment', $fields))    $c['doctor_comment']    = trim((string) $fields['doctor_comment']);
        if (array_key_exists('preceptor_rating', $fields))  $c['preceptor_rating']  = max(0, min(5, (int) $fields['preceptor_rating']));
        if (array_key_exists('preceptor_comment', $fields)) $c['preceptor_comment'] = trim((string) $fields['preceptor_comment']);
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
 * Look-up tables (id → record) for the display side of the case list.
 * Avoids O(cases × doctors) scanning when rendering.
 */
function doctors_by_id(): array
{
    $map = [];
    foreach (load_doctors() as $d) $map[(string) $d['id']] = $d;
    return $map;
}

function preceptors_by_id(): array
{
    $map = [];
    foreach (load_preceptors() as $p) $map[(string) $p['id']] = $p;
    return $map;
}

/**
 * All cases belonging to any student whose cohort label matches.
 * Cohort label format is "Fall 2026" — same shape student_cohort_label()
 * returns. Empty label → returns [].
 */
function cases_for_cohort(string $cohortLabel): array
{
    require_once __DIR__ . '/students_service.php';
    $label = trim($cohortLabel);
    if ($label === '') return [];
    $studentIds = [];
    foreach (load_students() as $s) {
        if (student_cohort_label($s) === $label) $studentIds[(string) $s['id']] = true;
    }
    if ($studentIds === []) return [];
    return array_values(array_filter(load_cases(), fn($c) => isset($studentIds[(string) ($c['student_id'] ?? '')])));
}

/**
 * Aggregate ratings across a supplied case list, grouped by whichever
 * person field the caller cares about ('doctor_id' or 'preceptor_id').
 * Ratings of 0 (or missing / out-of-range) are excluded — those mean
 * "student didn't rate this one." Returns [personId => {sum, count, avg}].
 */
function rating_stats_by_person(array $cases, string $ratingField, string $personIdField): array
{
    $stats = [];
    foreach ($cases as $c) {
        $pid = (string) ($c[$personIdField] ?? '');
        if ($pid === '') continue;
        $r = (int) ($c[$ratingField] ?? 0);
        if ($r < 1 || $r > 5) continue;
        if (!isset($stats[$pid])) $stats[$pid] = ['sum' => 0, 'count' => 0, 'avg' => 0.0];
        $stats[$pid]['sum']   += $r;
        $stats[$pid]['count']++;
    }
    foreach ($stats as &$s) {
        $s['avg'] = $s['count'] > 0 ? round($s['sum'] / $s['count'], 2) : 0.0;
    }
    unset($s);
    return $stats;
}

/**
 * Count of cases per person, across the supplied case list.
 * Returns [personId => int]. Missing / empty ids are skipped.
 */
function case_count_by_person(array $cases, string $personIdField): array
{
    $counts = [];
    foreach ($cases as $c) {
        $pid = (string) ($c[$personIdField] ?? '');
        if ($pid === '') continue;
        $counts[$pid] = ($counts[$pid] ?? 0) + 1;
    }
    return $counts;
}
