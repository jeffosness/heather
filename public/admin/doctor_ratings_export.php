<?php
declare(strict_types=1);
require_once __DIR__ . '/../../includes/bootstrap.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/cases_service.php';
require_once __DIR__ . '/../../includes/doctors_service.php';
require_once __DIR__ . '/../../includes/students_service.php';
require_once __DIR__ . '/../../includes/specialties_service.php';

require_login();

// CSV of every case that has a doctor_rating set. One row per rating,
// so AI can group by doctor and pull out signal + memorable comments.

$cases        = load_cases();
$doctorById   = doctors_by_id();
$studentsById = [];
foreach (load_students() as $s) $studentsById[(string) $s['id']] = $s;
$specById = [];
foreach (load_specialties() as $sp) $specById[(string) $sp['id']] = $sp;

// Only rows with an actual rating (comments alone don't count — the
// award is rating-based, and comments without a rating are noise here).
$rows = [];
foreach ($cases as $c) {
    $r = (int) ($c['doctor_rating'] ?? 0);
    if ($r < 1 || $r > 5) continue;
    $rows[] = $c;
}

$filename = 'doctor_ratings_' . date('Y-m-d') . '.csv';
header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="' . $filename . '"');

$out = fopen('php://output', 'w');
// BOM so Excel opens as UTF-8 without prompting.
fwrite($out, "\xEF\xBB\xBF");
fputcsv($out, [
    'Case date',
    'Doctor',
    'Rating (1-5)',
    'Comment',
    'Procedure',
    'Specialty',
    'Role',
    'Student',
    'Student cohort',
]);
foreach ($rows as $c) {
    $d = $doctorById[(string) ($c['doctor_id'] ?? '')] ?? null;
    $s = $studentsById[(string) ($c['student_id'] ?? '')] ?? null;
    $sp = $specById[(string) ($c['specialty_id'] ?? '')] ?? null;
    fputcsv($out, [
        (string) ($c['case_date'] ?? ''),
        $d ? (string) $d['name'] : '(deleted)',
        (int) ($c['doctor_rating'] ?? 0),
        (string) ($c['doctor_comment'] ?? ''),
        (string) ($c['procedure'] ?? ''),
        $sp ? (string) $sp['name'] : '',
        role_label((string) ($c['role'] ?? '')),
        $s ? (string) $s['name'] : '(unknown)',
        $s ? student_cohort_label($s) : '',
    ]);
}
fclose($out);
exit;
