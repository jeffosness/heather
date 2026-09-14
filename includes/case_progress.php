<?php
declare(strict_types=1);

require_once __DIR__ . '/cases_service.php';
require_once __DIR__ . '/specialties_service.php';

/**
 * Compute a CST 7e case-log progress report for one student.
 * Every rule Heather pasted turns into a "meter" in the returned array —
 * the dashboard renders them as filling wheels.
 *
 * Requirements per the CST Core Curriculum 7e (as pasted by Heather):
 *   Total (first + second scrub):           120  (30 general / 90 specialty)
 *   First scrub total:                       80  (20 general / 60 specialty)
 *   Additional first-or-second-scrub cases:  40  (10 general / 30 specialty)
 *   First-scrub specialty distribution:
 *     ≥ 4 specialties with ≥ 10 first-scrub cases each  (= 40 required cases)
 *     Remaining 20 first-scrub specialty cases in any specialty(ies)
 *
 * Observer cases are logged for the student's own record but do NOT
 * count toward any of the required minimums.
 */

const CASE_PROGRESS_REQ = [
    'total_all'                => 120,
    'total_general'            => 30,
    'total_specialty'          => 90,
    'first_scrub_total'        => 80,
    'first_scrub_general'      => 20,
    'first_scrub_specialty'    => 60,
    'additional_total'         => 40,
    'additional_general'       => 10,
    'additional_specialty'     => 30,
    'specialty_distribution_min_count'    => 4,   // at least 4 specialties
    'specialty_distribution_per_specialty'=> 10,  // each with at least 10 first-scrub cases
];

/**
 * Return a computed progress report for a student. Shape:
 *   [
 *     'meters' => [
 *        ['key' => 'total_all', 'label' => '...', 'have' => N, 'need' => 120, 'group' => 'Overall'],
 *        ...
 *     ],
 *     'specialties' => [
 *        ['name' => 'Orthopedic', 'first_scrub' => N, 'counts_toward_distribution' => bool, ...],
 *        ...
 *     ],
 *     'distribution' => [
 *        'qualifying_count' => 2,   // specialties with ≥10 first-scrub
 *        'need_count'       => 4,
 *        'complete'         => bool
 *     ],
 *     'total_cases_logged' => N (including observers),
 *   ]
 */
function progress_for_student(string $studentId): array
{
    $cases = cases_for_student($studentId);
    $specs = load_specialties();
    $specById = [];
    foreach ($specs as $s) $specById[(string) $s['id']] = $s;

    // Counters
    $total_all = 0; $total_general = 0; $total_specialty = 0;
    $first_total = 0; $first_general = 0; $first_specialty = 0;
    $second_total = 0; $second_general = 0; $second_specialty = 0;
    $observer = 0;

    // Per-specialty first-scrub counts (for the distribution rule).
    $firstScrubBySpec = [];
    foreach ($specs as $s) $firstScrubBySpec[(string) $s['id']] = 0;

    foreach ($cases as $c) {
        $role = (string) ($c['role'] ?? '');
        if ($role === 'observer') { $observer++; continue; }

        $sp = $specById[(string) ($c['specialty_id'] ?? '')] ?? null;
        $isGen = $sp !== null && !empty($sp['is_general']);

        $total_all++;
        if ($isGen) $total_general++; else $total_specialty++;

        if ($role === 'first_scrub') {
            $first_total++;
            if ($isGen) $first_general++; else $first_specialty++;
            if ($sp !== null && !$isGen) {
                $firstScrubBySpec[(string) $sp['id']] = ($firstScrubBySpec[(string) $sp['id']] ?? 0) + 1;
            }
        } elseif ($role === 'second_scrub') {
            $second_total++;
            if ($isGen) $second_general++; else $second_specialty++;
        }
    }

    // Distribution — count non-general specialties that have ≥ threshold
    // first-scrub cases. Each such specialty contributes exactly 10 toward
    // the 40 "required distributed" count; the rest of first-scrub specialty
    // cases are the "any specialty" 20.
    $perThresh = (int) CASE_PROGRESS_REQ['specialty_distribution_per_specialty'];
    $qualifyingCount = 0;
    foreach ($firstScrubBySpec as $spId => $n) {
        $sp = $specById[$spId] ?? null;
        if ($sp === null || !empty($sp['is_general'])) continue;
        if ($n >= $perThresh) $qualifyingCount++;
    }

    $meters = [
        ['key' => 'total_all',         'label' => 'Total cases',                  'have' => $total_all,       'need' => (int) CASE_PROGRESS_REQ['total_all'],         'group' => 'Overall'],
        ['key' => 'total_general',     'label' => 'General surgery',              'have' => $total_general,   'need' => (int) CASE_PROGRESS_REQ['total_general'],     'group' => 'Overall'],
        ['key' => 'total_specialty',   'label' => 'Specialty',                    'have' => $total_specialty, 'need' => (int) CASE_PROGRESS_REQ['total_specialty'],   'group' => 'Overall'],

        ['key' => 'first_scrub_total', 'label' => 'First scrub total',            'have' => $first_total,     'need' => (int) CASE_PROGRESS_REQ['first_scrub_total'],     'group' => 'First scrub'],
        ['key' => 'first_scrub_general','label'=> 'First scrub — general surgery','have' => $first_general,   'need' => (int) CASE_PROGRESS_REQ['first_scrub_general'],   'group' => 'First scrub'],
        ['key' => 'first_scrub_specialty','label'=>'First scrub — specialty',     'have' => $first_specialty, 'need' => (int) CASE_PROGRESS_REQ['first_scrub_specialty'], 'group' => 'First scrub'],

        ['key' => 'additional_total',    'label' => 'Additional (1st or 2nd scrub)', 'have' => $second_total,     'need' => (int) CASE_PROGRESS_REQ['additional_total'],     'group' => 'Additional'],
        ['key' => 'additional_general',  'label' => 'Additional — general',          'have' => $second_general,   'need' => (int) CASE_PROGRESS_REQ['additional_general'],   'group' => 'Additional'],
        ['key' => 'additional_specialty','label' => 'Additional — specialty',        'have' => $second_specialty, 'need' => (int) CASE_PROGRESS_REQ['additional_specialty'], 'group' => 'Additional'],
    ];

    $distribution = [
        'qualifying_count' => $qualifyingCount,
        'need_count'       => (int) CASE_PROGRESS_REQ['specialty_distribution_min_count'],
        'per_specialty'    => $perThresh,
        'complete'         => $qualifyingCount >= (int) CASE_PROGRESS_REQ['specialty_distribution_min_count'],
    ];

    $specialties = [];
    foreach ($specs as $s) {
        if (!empty($s['is_general'])) continue;
        $n = (int) ($firstScrubBySpec[(string) $s['id']] ?? 0);
        $specialties[] = [
            'id'          => (string) $s['id'],
            'name'        => (string) $s['name'],
            'first_scrub' => $n,
            'qualifies'   => $n >= $perThresh,
        ];
    }
    usort($specialties, fn($a, $b) => $b['first_scrub'] <=> $a['first_scrub']);

    return [
        'meters'             => $meters,
        'specialties'        => $specialties,
        'distribution'       => $distribution,
        'total_cases_logged' => $total_all + $observer,
        'observer'           => $observer,
        'overall_percent'    => $total_all >= (int) CASE_PROGRESS_REQ['total_all']
                                    ? 100
                                    : (int) round(($total_all / (int) CASE_PROGRESS_REQ['total_all']) * 100),
    ];
}
