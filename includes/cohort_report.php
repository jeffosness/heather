<?php
declare(strict_types=1);

require_once __DIR__ . '/students_service.php';
require_once __DIR__ . '/cases_service.php';
require_once __DIR__ . '/case_progress.php';
require_once __DIR__ . '/requirements_service.php';
require_once __DIR__ . '/specialties_service.php';

/**
 * Aggregate cohort-level report used on the admin dashboard.
 * Given a list of students (already filtered to one cohort or the
 * whole roster), compute:
 *
 *   summary            — headline numbers (student count, cases logged, avg%, etc.)
 *   completion_buckets — histogram of students by overall completion %
 *   cst_meter_stats    — per CST 7e meter, how many students have met it
 *   requirement_stats  — per program requirement, how many students have met it
 *   specialty_coverage — total cases per specialty across the cohort
 *   students           — the input students with per-student progress numbers attached
 *
 * All the "% of cohort met" numbers use full completion (have >= need)
 * rather than partial credit, since Heather cares about "who's done"
 * more than "who's 80%".
 */
function cohort_report(array $students): array
{
    $studentIds = array_map(fn($s) => (string) $s['id'], $students);
    $reqs = load_requirements();
    $specialties = load_specialties();

    $summary = [
        'student_count'         => count($students),
        'total_cases'           => 0,
        'avg_cases_per_student' => 0,
        'avg_completion_percent'=> 0,
        'fully_met_cst_count'   => 0,
        'fully_met_reqs_count'  => 0,
    ];

    $buckets = [
        ['label' => 'Not started', 'from' => 0,   'to' => 0,   'count' => 0],
        ['label' => '< 25%',        'from' => 1,   'to' => 24,  'count' => 0],
        ['label' => '25–49%',       'from' => 25,  'to' => 49,  'count' => 0],
        ['label' => '50–74%',       'from' => 50,  'to' => 74,  'count' => 0],
        ['label' => '75–99%',       'from' => 75,  'to' => 99,  'count' => 0],
        ['label' => '100% (done)',  'from' => 100, 'to' => 100, 'count' => 0],
    ];

    // For per-CST-meter and per-requirement met-counts, we build tallies
    // that align by key (meter key or requirement id).
    $cstMet = []; // meter key => count
    $reqMet = []; // req id => count
    $reqTotals = []; // req id => target count (for display)

    // Specialty coverage: total cases per specialty across the cohort.
    $specCoverage = [];
    foreach ($specialties as $s) {
        $specCoverage[(string) $s['id']] = [
            'specialty'     => $s,
            'total'         => 0,
            'first_scrub'   => 0,
            'second_scrub'  => 0,
        ];
    }

    // Cases-per-student sum (for avg) — build once from case data.
    $casesByStudent = [];
    foreach (load_cases() as $c) {
        $sid = (string) ($c['student_id'] ?? '');
        if (!in_array($sid, $studentIds, true)) continue;
        $casesByStudent[$sid][] = $c;
        // Specialty coverage: skip observer for total count (matches CST 7e),
        // still count in per-role tallies if wanted.
        $role = (string) ($c['role'] ?? '');
        $spid = (string) ($c['specialty_id'] ?? '');
        if (!isset($specCoverage[$spid])) continue;
        if ($role !== 'observer') $specCoverage[$spid]['total']++;
        if ($role === 'first_scrub')  $specCoverage[$spid]['first_scrub']++;
        if ($role === 'second_scrub') $specCoverage[$spid]['second_scrub']++;
    }

    $completionSum = 0;

    $enrichedStudents = [];
    foreach ($students as $s) {
        $sid = (string) $s['id'];
        $studentCases = $casesByStudent[$sid] ?? [];
        $summary['total_cases'] += count($studentCases);

        // Progress numbers via existing helpers.
        $prog = progress_for_student($sid);
        $completionSum += (int) $prog['overall_percent'];

        // Bucket the student by overall percent.
        $pct = (int) $prog['overall_percent'];
        foreach ($buckets as &$b) {
            if ($pct >= $b['from'] && $pct <= $b['to']) { $b['count']++; break; }
        }
        unset($b);

        // CST meter — count met per meter key.
        $allCstMet = true;
        foreach ($prog['meters'] as $m) {
            $key = (string) $m['key'];
            $isMet = ((int) $m['have']) >= ((int) $m['need']);
            if ($isMet) $cstMet[$key] = ($cstMet[$key] ?? 0) + 1;
            else        $allCstMet = false;
        }
        // Distribution rule counts too.
        if (!$prog['distribution']['complete']) $allCstMet = false;
        if ($allCstMet) $summary['fully_met_cst_count']++;

        // Program requirements.
        $reqReport = requirements_for_student($sid);
        $allReqsMet = true;
        foreach ($reqReport as $rr) {
            $rid = (string) $rr['req']['id'];
            $reqTotals[$rid] = (int) $rr['need'];
            if ($rr['met']) $reqMet[$rid] = ($reqMet[$rid] ?? 0) + 1;
            else            $allReqsMet = false;
        }
        // No requirements defined → everyone counts as met by default so the
        // headline number isn't confusing.
        if ($reqs === [] || $allReqsMet) $summary['fully_met_reqs_count']++;

        $enrichedStudents[] = [
            'student' => $s,
            'progress' => $prog,
            'cases_count' => count($studentCases),
        ];
    }

    $summary['avg_cases_per_student'] = $summary['student_count'] > 0
        ? (int) round($summary['total_cases'] / $summary['student_count'])
        : 0;
    $summary['avg_completion_percent'] = $summary['student_count'] > 0
        ? (int) round($completionSum / $summary['student_count'])
        : 0;

    // Build meter stats using one representative student's meter list for
    // the labels (all students share the same CST 7e definitions).
    $cstStats = [];
    if ($enrichedStudents !== []) {
        foreach ($enrichedStudents[0]['progress']['meters'] as $m) {
            $key = (string) $m['key'];
            $metN = (int) ($cstMet[$key] ?? 0);
            $cstStats[] = [
                'key'         => $key,
                'label'       => (string) $m['label'],
                'target'      => (int) $m['need'],
                'met_count'   => $metN,
                'total'       => $summary['student_count'],
                'met_percent' => $summary['student_count'] > 0
                    ? (int) round(($metN / $summary['student_count']) * 100)
                    : 0,
            ];
        }
    }

    // Program requirement stats.
    $requirementStats = [];
    $specById = [];
    foreach ($specialties as $s) $specById[(string) $s['id']] = $s;
    foreach ($reqs as $req) {
        $rid = (string) $req['id'];
        $metN = (int) ($reqMet[$rid] ?? 0);
        $requirementStats[] = [
            'req'         => $req,
            'label'       => requirement_label($req, $specById),
            'target'      => (int) ($req['count_required'] ?? 0),
            'met_count'   => $metN,
            'total'       => $summary['student_count'],
            'met_percent' => $summary['student_count'] > 0
                ? (int) round(($metN / $summary['student_count']) * 100)
                : 0,
        ];
    }

    // Sort specialty coverage by total desc.
    $coverageList = array_values($specCoverage);
    usort($coverageList, fn($a, $b) => $b['total'] <=> $a['total']);

    return [
        'summary'            => $summary,
        'completion_buckets' => $buckets,
        'cst_meter_stats'    => $cstStats,
        'requirement_stats'  => $requirementStats,
        'specialty_coverage' => $coverageList,
        'students'           => $enrichedStudents,
    ];
}
