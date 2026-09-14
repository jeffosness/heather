<?php
declare(strict_types=1);

require_once __DIR__ . '/json_store.php';

/**
 * Student accounts — separate from admin users. Same invite-based
 * signup pattern (Heather invites via email, student sets their own
 * password on first click).
 *
 *   { id, name, email, username, password_hash?, cohort?,
 *     created_at, last_login_at?, reset_token?, reset_token_expires? }
 *
 * Sessions are keyed with heather_student_* so a signed-in student
 * and a signed-in admin on the same browser don't collide.
 */

function load_students(): array
{
    $data = read_json_file(APP_STUDENTS_FILE, []);
    if (!is_array($data)) return [];
    usort($data, fn($a, $b) => strcasecmp((string) ($a['name'] ?? ''), (string) ($b['name'] ?? '')));
    return $data;
}

function save_students(array $records): bool
{
    return write_json_file(APP_STUDENTS_FILE, array_values($records));
}

function find_student(string $id): ?array
{
    foreach (load_students() as $s) {
        if (($s['id'] ?? '') === $id) return $s;
    }
    return null;
}

function find_student_by_username(string $username): ?array
{
    $username = strtolower(trim($username));
    if ($username === '') return null;
    foreach (load_students() as $s) {
        if (strtolower((string) ($s['username'] ?? '')) === $username) return $s;
    }
    return null;
}

function find_student_by_email(string $email): ?array
{
    $email = strtolower(trim($email));
    if ($email === '') return null;
    foreach (load_students() as $s) {
        if (strtolower((string) ($s['email'] ?? '')) === $email) return $s;
    }
    return null;
}

function student_username_available(string $username, string $excludeId = ''): bool
{
    $existing = find_student_by_username($username);
    if (!$existing) return true;
    return ($excludeId !== '' && (string) $existing['id'] === $excludeId);
}

const COHORT_SEASONS = ['Fall', 'Spring', 'Summer', 'Winter'];

/**
 * Effective cohort tuple. Prefers the structured cohort_season +
 * cohort_year fields. Falls back to parsing a legacy `cohort` free-text
 * value (e.g. "Fall 2026") for students created before the structured
 * fields were introduced. Returns ['', 0] when nothing usable is on the
 * record.
 */
function student_cohort_tuple(array $student): array
{
    $season = trim((string) ($student['cohort_season'] ?? ''));
    $year   = (int)         ($student['cohort_year']   ?? 0);
    if (in_array($season, COHORT_SEASONS, true) && $year >= 2000 && $year <= 2100) {
        return [$season, $year];
    }
    // Try to parse a legacy free-text cohort like "Fall 2026" / "spring 2027".
    $legacy = trim((string) ($student['cohort'] ?? ''));
    if ($legacy !== '' && preg_match('/^(Fall|Spring|Summer|Winter)\s+(\d{4})$/i', $legacy, $m)) {
        return [ucfirst(strtolower($m[1])), (int) $m[2]];
    }
    return ['', 0];
}

/**
 * Human-friendly cohort label. Uses the parsed tuple so legacy records
 * still render "Fall 2026" even if their structured fields were never
 * populated.
 */
function student_cohort_label(array $student): string
{
    [$season, $year] = student_cohort_tuple($student);
    if ($season !== '' && $year > 0) return $season . ' ' . $year;
    // Last resort — surface whatever legacy string exists so it's not
    // silently invisible.
    return trim((string) ($student['cohort'] ?? ''));
}

/**
 * Invite a new student — creates the record without a password. Caller
 * is expected to send_student_invite_email() after this returns.
 *
 * cohort_season + cohort_year are required (Heather needs to report on
 * class-by-class progress). Season must be one of COHORT_SEASONS.
 */
function invite_student(array $fields): ?array
{
    $username = strtolower(trim((string) ($fields['username'] ?? '')));
    $name  = trim((string) ($fields['name']  ?? ''));
    $email = trim((string) ($fields['email'] ?? ''));
    $season= trim((string) ($fields['cohort_season'] ?? ''));
    $year  = (int)         ($fields['cohort_year']   ?? 0);
    if ($username === '' || $name === '' || $email === '') return null;
    if (!in_array($season, COHORT_SEASONS, true)) return null;
    if ($year < 2000 || $year > 2100) return null;
    if (!student_username_available($username)) return null;
    $student = [
        'id'            => gen_id('st_'),
        'name'          => $name,
        'username'      => $username,
        'email'         => $email,
        'cohort_season' => $season,
        'cohort_year'   => $year,
        'created_at'    => date('Y-m-d H:i:s'),
    ];
    $items = load_students();
    $items[] = $student;
    if (!save_students($items)) return null;
    return $student;
}

/**
 * All distinct cohort tuples that show up on any student, newest first.
 * Used for admin filters and reports.
 */
function all_cohorts(): array
{
    $set = [];
    foreach (load_students() as $s) {
        [$season, $year] = student_cohort_tuple($s);
        if ($season === '' || $year === 0) continue;
        $key = $season . '|' . $year;
        $set[$key] = ['season' => $season, 'year' => $year, 'label' => $season . ' ' . $year];
    }
    $items = array_values($set);
    // Sort by year desc, then season order (Fall before Spring — sort by
    // COHORT_SEASONS index desc).
    $seasonRank = array_flip(COHORT_SEASONS);
    usort($items, function ($a, $b) use ($seasonRank) {
        if ($a['year'] !== $b['year']) return $b['year'] <=> $a['year'];
        return ($seasonRank[$a['season']] ?? 0) <=> ($seasonRank[$b['season']] ?? 0);
    });
    return $items;
}

function update_student(string $id, array $fields): bool
{
    return update_record_by_id(APP_STUDENTS_FILE, $id, function (array &$s) use ($fields) {
        if (array_key_exists('name', $fields))   $s['name']   = trim((string) $fields['name']);
        if (array_key_exists('email', $fields))  $s['email']  = trim((string) $fields['email']);
        if (array_key_exists('cohort_season', $fields)) {
            $v = trim((string) $fields['cohort_season']);
            if (in_array($v, COHORT_SEASONS, true)) $s['cohort_season'] = $v;
        }
        if (array_key_exists('cohort_year', $fields)) {
            $v = (int) $fields['cohort_year'];
            if ($v >= 2000 && $v <= 2100) $s['cohort_year'] = $v;
        }
        if (!empty($fields['password'])) {
            $s['password_hash'] = password_hash((string) $fields['password'], PASSWORD_DEFAULT);
        }
        if (array_key_exists('last_login_at', $fields)) {
            $s['last_login_at'] = (string) $fields['last_login_at'];
        }
        if (array_key_exists('reset_token', $fields)) {
            $s['reset_token'] = (string) $fields['reset_token'];
        }
        if (array_key_exists('reset_token_expires', $fields)) {
            $s['reset_token_expires'] = (int) $fields['reset_token_expires'];
        }
    });
}

function delete_student(string $id): bool
{
    $items = array_values(array_filter(load_students(), fn($s) => ($s['id'] ?? '') !== $id));
    return save_students($items);
}

function student_has_password(array $student): bool
{
    return trim((string) ($student['password_hash'] ?? '')) !== '';
}
