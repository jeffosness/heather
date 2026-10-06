<?php
declare(strict_types=1);

require_once __DIR__ . '/json_store.php';
require_once __DIR__ . '/presentation_types.php';

/**
 * Presentations = reusable LESSONS + dated SESSIONS.
 *
 * Lessons are reused and built upon year to year, so all content lives on
 * the lesson. A session is one run of a lesson for a class on a date — it
 * only holds run state (where she is, the score) and the final result.
 *
 * Lesson — one file each at presentations/<id>.json:
 *   { id, title, type, course, description, content, created_at, updated_at }
 *   `content` shape is owned by the type (presentation_types.php).
 *
 * Session — presentation_sessions.json:
 *   { id, lesson_id, date, label, mode, state, result, created_at, updated_at }
 *   mode is 'class' today (one shared score she controls up front).
 *   state.scores / state.skulls are keyed by team id ('class' for now) so a
 *   future 'teams' mode with per-team join pages fits without a migration.
 *
 * Every lesson save snapshots the previous version into
 * presentations/_history/<id>/ so edits are never destructive. Lessons
 * shipped in includes/presentation_seeds/ are imported once on first load.
 */

const PRESENTATION_SEEDS_DIR = __DIR__ . DIRECTORY_SEPARATOR . 'presentation_seeds';

function presentations_history_dir(string $id): string
{
    return APP_PRESENTATIONS_DIR . DIRECTORY_SEPARATOR . '_history' . DIRECTORY_SEPARATOR . $id;
}

function valid_lesson_id(string $id): bool
{
    return (bool) preg_match('/^[a-z0-9][a-z0-9_-]{0,63}$/', $id);
}

function lesson_path(string $id): string
{
    return APP_PRESENTATIONS_DIR . DIRECTORY_SEPARATOR . $id . '.json';
}

/** Fields a seed update replaces — all of them count when deciding "has she edited this?". */
const LESSON_SEED_FIELDS = ['title', 'type', 'course', 'description', 'content'];

function lesson_seed_hash(array $lesson): string
{
    $fields = [];
    foreach (LESSON_SEED_FIELDS as $k) $fields[$k] = $lesson[$k] ?? null;
    return md5((string) json_encode($fields, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
}

/** Shipped seed lessons keyed by id. */
function seed_lessons(): array
{
    $out = [];
    foreach (glob(PRESENTATION_SEEDS_DIR . DIRECTORY_SEPARATOR . '*.json') ?: [] as $file) {
        $lesson = read_json_file($file, null);
        if (is_array($lesson) && valid_lesson_id((string) ($lesson['id'] ?? ''))) $out[$lesson['id']] = $lesson;
    }
    return $out;
}

/** True if the lesson still matches what was seeded (Heather hasn't edited it). */
function lesson_unedited_since_seed(array $lesson): bool
{
    if (!empty($lesson['seed_customized'])) return false;
    if (isset($lesson['seed_hash'])) return $lesson['seed_hash'] === lesson_seed_hash($lesson);
    // Lessons seeded before seed_hash existed: unedited if never re-saved.
    return ($lesson['updated_at'] ?? '') === ($lesson['created_at'] ?? '');
}

/** A newer shipped version exists for this lesson. */
function lesson_seed_update_available(array $lesson): bool
{
    $seed = seed_lessons()[$lesson['id'] ?? ''] ?? null;
    return $seed !== null && (int) ($seed['seed_version'] ?? 1) > (int) ($lesson['seed_version'] ?? 1);
}

/** Replace a lesson's content with its shipped seed (previous version goes to history). */
function apply_seed_update(string $id): bool
{
    $seed = seed_lessons()[$id] ?? null;
    // Read the file directly: find_lesson() runs seeding, which calls back into here.
    $lesson = read_json_file(lesson_path($id), null);
    if ($seed === null || !is_array($lesson)) return false;
    foreach (LESSON_SEED_FIELDS as $k) {
        if (array_key_exists($k, $seed)) $lesson[$k] = $seed[$k];
    }
    $lesson['seed_version'] = (int) ($seed['seed_version'] ?? 1);
    $lesson['seed_hash'] = lesson_seed_hash($lesson);
    unset($lesson['seed_customized']);
    return save_lesson($lesson);
}

/**
 * Import seed lessons once each — deleting a seeded lesson keeps it deleted.
 * When a seed's `seed_version` goes up, lessons she hasn't edited update
 * automatically; edited ones get an "update available" banner in the editor.
 */
function seed_lessons_if_needed(): void
{
    $markerPath = APP_PRESENTATIONS_DIR . DIRECTORY_SEPARATOR . '_seeded.json';
    $seeded = read_json_file($markerPath, []);
    if (!is_array($seeded)) $seeded = [];
    $changed = false;
    foreach (seed_lessons() as $id => $lesson) {
        if (in_array($id, $seeded, true)) {
            $current = read_json_file(lesson_path($id), null);
            if (is_array($current) && lesson_seed_update_available($current) && lesson_unedited_since_seed($current)) {
                apply_seed_update($id);
            }
            continue;
        }
        if (!is_file(lesson_path($id))) {
            $lesson['seed_version'] = (int) ($lesson['seed_version'] ?? 1);
            $lesson['seed_hash'] = lesson_seed_hash($lesson);
            $lesson['created_at'] = $lesson['updated_at'] = date('Y-m-d H:i:s');
            write_json_file(lesson_path($id), $lesson);
        }
        $seeded[] = $id;
        $changed = true;
    }
    if ($changed) write_json_file($markerPath, $seeded);
}

function load_lessons(): array
{
    seed_lessons_if_needed();
    $lessons = [];
    foreach (glob(APP_PRESENTATIONS_DIR . DIRECTORY_SEPARATOR . '*.json') ?: [] as $file) {
        if (str_starts_with(basename($file), '_')) continue;
        $l = read_json_file($file, null);
        if (is_array($l) && isset($l['id'])) $lessons[] = $l;
    }
    usort($lessons, fn($a, $b) => strcasecmp((string) ($a['title'] ?? ''), (string) ($b['title'] ?? '')));
    return $lessons;
}

function find_lesson(string $id): ?array
{
    if (!valid_lesson_id($id)) return null;
    seed_lessons_if_needed();
    $l = read_json_file(lesson_path($id), null);
    return is_array($l) ? $l : null;
}

function lesson_slug_id(string $title): string
{
    $base = trim((string) preg_replace('/[^a-z0-9]+/', '-', strtolower($title)), '-');
    $base = substr($base !== '' ? $base : 'lesson', 0, 48);
    $id = $base;
    for ($i = 2; is_file(lesson_path($id)); $i++) $id = $base . '-' . $i;
    return $id;
}

/** Save a lesson, snapshotting whatever was on disk first. */
function save_lesson(array $lesson): bool
{
    $id = (string) ($lesson['id'] ?? '');
    if (!valid_lesson_id($id)) return false;
    $path = lesson_path($id);
    if (is_file($path)) {
        $hist = presentations_history_dir($id);
        if (!is_dir($hist)) @mkdir($hist, 0775, true);
        @copy($path, $hist . DIRECTORY_SEPARATOR . date('Ymd-His') . '-' . bin2hex(random_bytes(2)) . '.json');
    }
    $lesson['updated_at'] = date('Y-m-d H:i:s');
    $lesson['created_at'] = $lesson['created_at'] ?? $lesson['updated_at'];
    return write_json_file($path, $lesson);
}

/** Create a lesson from fields; `content` defaults to the type's starter. */
function create_lesson(array $fields): ?array
{
    $type = (string) ($fields['type'] ?? '');
    $t = presentation_type($type);
    $title = trim((string) ($fields['title'] ?? ''));
    if ($t === null || $title === '') return null;
    $lesson = [
        'id'          => lesson_slug_id($title),
        'title'       => $title,
        'type'        => $type,
        'course'      => trim((string) ($fields['course'] ?? '')),
        'description' => trim((string) ($fields['description'] ?? '')),
        'content'     => $fields['content'] ?? ($t['starter'])(),
    ];
    return save_lesson($lesson) ? $lesson : null;
}

/**
 * Import an exported lesson. Always gets a fresh id so an import never
 * overwrites an existing lesson. Returns [lesson|null, errors[]].
 */
function import_lesson(array $data): array
{
    $type = (string) ($data['type'] ?? '');
    [$errors] = validate_presentation_content($type, $data['content'] ?? null);
    if ($errors !== []) return [null, $errors];
    $lesson = create_lesson($data);
    return [$lesson, $lesson ? [] : ['Could not save the imported lesson (missing title?).']];
}

function duplicate_lesson(string $id): ?array
{
    $src = find_lesson($id);
    if ($src === null) return null;
    $src['title'] = trim((string) ($src['title'] ?? '')) . ' (copy)';
    return create_lesson($src);
}

/** Soft delete: the file moves into its history folder. */
function delete_lesson(string $id): bool
{
    if (!valid_lesson_id($id) || !is_file(lesson_path($id))) return false;
    $hist = presentations_history_dir($id);
    if (!is_dir($hist)) @mkdir($hist, 0775, true);
    return rename(lesson_path($id), $hist . DIRECTORY_SEPARATOR . 'deleted-' . date('Ymd-His') . '.json');
}

/** Previous versions, newest first: [{file, saved_at, title}] */
function lesson_history(string $id): array
{
    if (!valid_lesson_id($id)) return [];
    $out = [];
    foreach (glob(presentations_history_dir($id) . DIRECTORY_SEPARATOR . '*.json') ?: [] as $file) {
        $l = read_json_file($file, []);
        $out[] = [
            'file'     => basename($file),
            'saved_at' => (string) ($l['updated_at'] ?? date('Y-m-d H:i:s', (int) filemtime($file))),
        ];
    }
    usort($out, fn($a, $b) => strcmp($b['file'], $a['file']));
    return $out;
}

function restore_lesson_version(string $id, string $file): bool
{
    if (!valid_lesson_id($id) || !preg_match('/^[A-Za-z0-9_-]+\.json$/', $file)) return false;
    $old = read_json_file(presentations_history_dir($id) . DIRECTORY_SEPARATOR . $file, null);
    if (!is_array($old)) return false;
    $old['id'] = $id;
    // An explicit restore counts as her edit, so automatic seed updates must not
    // immediately replace it (the editor banner still offers the update).
    $old['seed_customized'] = true;
    return save_lesson($old);
}

// ---------------------------------------------------------------- sessions

function load_sessions(): array
{
    $data = read_json_file(APP_PRESENTATION_SESSIONS_FILE, []);
    if (!is_array($data)) return [];
    usort($data, fn($a, $b) => strcmp((string) ($a['date'] ?? ''), (string) ($b['date'] ?? '')));
    return $data;
}

function save_sessions(array $records): bool
{
    return write_json_file(APP_PRESENTATION_SESSIONS_FILE, array_values($records));
}

function find_session(string $id): ?array
{
    foreach (load_sessions() as $s) {
        if (($s['id'] ?? '') === $id) return $s;
    }
    return null;
}

function add_session(array $fields): ?array
{
    $lessonId = (string) ($fields['lesson_id'] ?? '');
    $date = (string) ($fields['date'] ?? '');
    if (find_lesson($lessonId) === null || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) return null;
    $rec = [
        'id'         => gen_id('ps_'),
        'lesson_id'  => $lessonId,
        'date'       => $date,
        'label'      => trim((string) ($fields['label'] ?? '')),
        'mode'       => 'class',
        'state'      => null,
        'result'     => null,
        'created_at' => date('Y-m-d H:i:s'),
    ];
    $items = load_sessions();
    $items[] = $rec;
    return save_sessions($items) ? $rec : null;
}

function update_session(string $id, array $fields): bool
{
    return update_record_by_id(APP_PRESENTATION_SESSIONS_FILE, $id, function (array &$s) use ($fields) {
        foreach (['date', 'label', 'state', 'result', 'reset_at'] as $k) {
            if (array_key_exists($k, $fields)) $s[$k] = $fields[$k];
        }
        $s['updated_at'] = date('Y-m-d H:i:s');
    });
}

function delete_session(string $id): bool
{
    $items = array_values(array_filter(load_sessions(), fn($s) => ($s['id'] ?? '') !== $id));
    return save_sessions($items);
}

/** Sessions scheduled for today, for the dashboard. */
function todays_sessions(): array
{
    $today = date('Y-m-d');
    return array_values(array_filter(load_sessions(), fn($s) => ($s['date'] ?? '') === $today));
}
