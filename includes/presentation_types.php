<?php
declare(strict_types=1);

/**
 * Registry of presentation TYPES. A lesson's `content` shape is owned by
 * its type; everything else (library, scheduling, sessions, the present
 * shell, history) is shared.
 *
 * Adding a new type (e.g. linear slides, jeopardy, flashcards):
 *   1. includes/presentation_type_<key>.php — validate / starter / outline /
 *      ai_guide (format docs for the ChatGPT planning prompt)
 *   2. public/admin/assets/present/<key>.js — calls Present.registerType('<key>', {...})
 *   3. Register it below.
 */

require_once __DIR__ . '/presentation_type_adventure.php';

function presentation_types(): array
{
    return [
        'adventure' => [
            'label'    => 'Branching adventure',
            'blurb'    => 'Choose-your-own-adventure: scenes, A/B/C choices worth points, die rolls, timed team activities with reveals, and scored endings.',
            'player'   => 'adventure.js',
            'validate' => 'validate_adventure_content',
            'starter'  => 'adventure_starter_content',
            'outline'  => 'adventure_outline',
            'ai_guide' => 'adventure_ai_guide',
        ],
    ];
}

function presentation_type(string $key): ?array
{
    return presentation_types()[$key] ?? null;
}

/**
 * Validate a lesson's content against its type.
 * Returns [errors[], warnings[]] — errors block saving, warnings don't.
 */
function validate_presentation_content(string $type, $content): array
{
    $t = presentation_type($type);
    if ($t === null) return [["Unknown presentation type \"$type\"."], []];
    return ($t['validate'])($content);
}

/**
 * Outline rows for the editor sidebar: [{id, kind, title, links: [[label, target]]}]
 */
function presentation_outline(string $type, $content): array
{
    $t = presentation_type($type);
    if ($t === null || !is_array($content)) return [];
    return ($t['outline'])($content);
}
