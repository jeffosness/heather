<?php
declare(strict_types=1);
require_once __DIR__ . '/../../includes/bootstrap.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/cases_service.php';
require_once __DIR__ . '/../../includes/preceptors_service.php';
require_once __DIR__ . '/../../includes/students_service.php';
require_once __DIR__ . '/../../includes/specialties_service.php';

require_login();

// Mirror of doctor_ratings_export.php but for preceptors.
// Scoped by ?cohort= so year-over-year data doesn't mix.

$cohortParam = trim((string) ($_GET['cohort'] ?? ''));
if ($cohortParam === '' || $cohortParam === '__all__') {
    $cases = load_cases();
    $cohortLabelForFile = 'all';
} else {
    $cases = cases_for_cohort($cohortParam);
    $cohortLabelForFile = strtolower(preg_replace('/[^a-z0-9]+/i', '_', $cohortParam));
}
$preceptorById= preceptors_by_id();
$studentsById = [];
foreach (load_students() as $s) $studentsById[(string) $s['id']] = $s;
$specById = [];
foreach (load_specialties() as $sp) $specById[(string) $sp['id']] = $sp;

$rows = [];
foreach ($cases as $c) {
    $r = (int) ($c['preceptor_rating'] ?? 0);
    if ($r < 1 || $r > 5) continue;
    $rows[] = $c;
}

$filename = 'preceptor_ratings_' . $cohortLabelForFile . '_' . date('Y-m-d') . '.csv';
header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="' . $filename . '"');

$out = fopen('php://output', 'w');
fwrite($out, "\xEF\xBB\xBF");
fputcsv($out, [
    'Case date',
    'Preceptor',
    'Rating (1-5)',
    'Comment',
    'Procedure',
    'Specialty',
    'Role',
    'Student',
    'Student cohort',
]);
foreach ($rows as $c) {
    $p  = $preceptorById[(string) ($c['preceptor_id'] ?? '')] ?? null;
    $s  = $studentsById[(string) ($c['student_id'] ?? '')] ?? null;
    $sp = $specById[(string) ($c['specialty_id'] ?? '')] ?? null;
    fputcsv($out, [
        (string) ($c['case_date'] ?? ''),
        $p ? (string) $p['name'] : '(deleted)',
        (int) ($c['preceptor_rating'] ?? 0),
        (string) ($c['preceptor_comment'] ?? ''),
        (string) ($c['procedure'] ?? ''),
        $sp ? (string) $sp['name'] : '',
        role_label((string) ($c['role'] ?? '')),
        $s ? (string) $s['name'] : '(unknown)',
        $s ? student_cohort_label($s) : '',
    ]);
}
fclose($out);
exit;
