<?php
declare(strict_types=1);

/**
 * "Adventure" presentation type — a branching, scored scenario.
 *
 * content = {
 *   start: "<node id>",
 *   scoring: { enabled, start, label, bands: [{min?, title, text, tone?}] },
 *   nodes: { "<id>": node, ... }
 * }
 *
 * Every node may have: eyebrow, round, time, title, body, prompt, notes
 * (presenter-only), tone ('danger'|'success'|'shake'), and
 * enter: {points, skull} applied when the node is reached.
 *
 *   scene    — body + Continue                                → next
 *   choice   — options: [{key, text, points, skull?, feedback, what_if?, next}]
 *              First pick scores; afterwards other options can be explored
 *              (not scored), showing `what_if` (or `feedback` if absent).
 *   chance   — sides (default 6), outcomes: [{min, max, title, text, points, skull?, tone?, next}]
 *   activity — timer (secs), teams: [{name, prompt}], cards: [{label, text}],
 *              cards_title, printable, reveal: {title, body | steps[]} → next
 *              (steps are revealed one key press at a time)
 *   ending   — final score + matching scoring band
 *
 * Text fields support **bold**, *italic*, "- " bullet lines and blank-line paragraphs.
 */

const ADVENTURE_NODE_KINDS = ['scene', 'choice', 'chance', 'activity', 'ending'];

function adventure_node_links(array $n): array
{
    $links = [];
    switch ((string) ($n['kind'] ?? '')) {
        case 'scene':
        case 'activity':
            $links[] = ['Continue', $n['next'] ?? null];
            break;
        case 'choice':
            foreach (($n['options'] ?? []) as $i => $o) {
                if (!is_array($o)) continue;
                $key = (string) ($o['key'] ?? chr(65 + (int) $i));
                $pts = (int) ($o['points'] ?? 0);
                $links[] = [$key . ($pts !== 0 ? sprintf(' (%+d)', $pts) : ''), $o['next'] ?? null];
            }
            break;
        case 'chance':
            foreach (($n['outcomes'] ?? []) as $o) {
                if (!is_array($o)) continue;
                $range = (int) ($o['min'] ?? 0) . '–' . (int) ($o['max'] ?? 0);
                $pts = (int) ($o['points'] ?? 0);
                $links[] = ['Roll ' . $range . ($pts !== 0 ? sprintf(' (%+d)', $pts) : ''), $o['next'] ?? null];
            }
            break;
    }
    return $links;
}

function validate_adventure_content($content): array
{
    $errors = [];
    $warnings = [];
    if (!is_array($content)) return [['Content must be a JSON object.'], []];

    $nodes = $content['nodes'] ?? null;
    if (!is_array($nodes) || $nodes === [] || array_is_list($nodes)) {
        return [['"nodes" must be an object of { "node-id": { ...node... } }.'], []];
    }
    $start = (string) ($content['start'] ?? '');
    if (!isset($nodes[$start])) $errors[] = "\"start\" points at \"$start\", which isn't a node.";

    foreach ($nodes as $id => $n) {
        $id = (string) $id;
        if (!is_array($n)) { $errors[] = "$id: must be an object."; continue; }
        $kind = (string) ($n['kind'] ?? '');
        if (!in_array($kind, ADVENTURE_NODE_KINDS, true)) {
            $errors[] = "$id: unknown kind \"$kind\" (use " . implode(', ', ADVENTURE_NODE_KINDS) . ').';
            continue;
        }
        if ($kind === 'choice' && (!is_array($n['options'] ?? null) || $n['options'] === [])) {
            $errors[] = "$id: a choice needs at least one option.";
        }
        if ($kind === 'chance') {
            $sides = (int) ($n['sides'] ?? 6);
            if ($sides < 2 || $sides > 20) $errors[] = "$id: sides must be between 2 and 20.";
            $hits = array_fill(1, max(1, $sides), 0);
            foreach (($n['outcomes'] ?? []) as $o) {
                for ($r = (int) ($o['min'] ?? 0); $r <= (int) ($o['max'] ?? -1); $r++) {
                    if (isset($hits[$r])) $hits[$r]++;
                }
            }
            foreach ($hits as $roll => $count) {
                if ($count === 0) $errors[] = "$id: a roll of $roll has no outcome.";
                if ($count > 1)  $errors[] = "$id: a roll of $roll matches more than one outcome.";
            }
        }
        foreach (adventure_node_links($n) as [$label, $to]) {
            if (!is_string($to) || $to === '') $errors[] = "$id: \"$label\" is missing a \"next\".";
            elseif (!isset($nodes[$to]))       $errors[] = "$id: \"$label\" goes to \"$to\", which isn't a node.";
        }
    }

    if ($errors === []) {
        // Reachability from start — unreachable nodes are probably a typo'd "next".
        $seen = [$start => true];
        $queue = [$start];
        $endingReachable = false;
        while ($queue) {
            $id = array_shift($queue);
            if (($nodes[$id]['kind'] ?? '') === 'ending') $endingReachable = true;
            foreach (adventure_node_links($nodes[$id]) as [, $to]) {
                if (!isset($seen[$to])) { $seen[$to] = true; $queue[] = $to; }
            }
        }
        foreach (array_keys($nodes) as $id) {
            if (!isset($seen[(string) $id])) $warnings[] = "$id: can't be reached from the start.";
        }
        if (!$endingReachable) $warnings[] = 'No "ending" node can be reached — the adventure never finishes.';
    }

    return [$errors, $warnings];
}

function adventure_starter_content(): array
{
    return [
        'start' => 'intro',
        'scoring' => [
            'enabled' => true,
            'start'   => 10,
            'label'   => 'Points',
            'bands'   => [
                ['min' => 12, 'title' => 'Nailed it',   'text' => 'Great decisions all around.', 'tone' => 'success'],
                ['title' => 'Keep practicing', 'text' => "Let's talk about what happened."],
            ],
        ],
        'nodes' => [
            'intro' => ['kind' => 'scene', 'time' => '07:00', 'title' => 'The setup', 'body' => 'Describe the scenario here.', 'next' => 'q1'],
            'q1' => [
                'kind' => 'choice', 'round' => 'ROUND 1', 'title' => 'First decision', 'prompt' => 'What do you do?',
                'options' => [
                    ['key' => 'A', 'text' => 'The safe choice', 'points' => 2,  'feedback' => 'Correct — here is why.', 'next' => 'end'],
                    ['key' => 'B', 'text' => 'The risky choice', 'points' => -2, 'feedback' => 'Here is what went wrong.', 'next' => 'end'],
                ],
            ],
            'end' => ['kind' => 'ending', 'title' => 'End of scenario'],
        ],
    ];
}

/** Format guide for the ChatGPT planning prompt (presentation_ai_prompt.php). */
function adventure_ai_guide(): string
{
    require_once __DIR__ . '/presentation_ai_prompt.php';
    $example = presentation_example_excerpt('shift-from-hell.json', ['intro', 'r1', 'r2', 'r2_roll', 'fire', 'mci', 'end']);
    return <<<GUIDE
A branching, scored "choose your own adventure". The lesson is a set of slides ("nodes") linked by "next" ids. Slides can branch (different choices lead to different slides) and rejoin later.

Lesson JSON format:
{
  "title": "...", "type": "adventure", "course": "...", "description": "...",
  "content": {
    "start": "<id of first slide>",
    "scoring": { "enabled": true, "start": 10, "label": "Patient Safety Points",
                 "bands": [ { "min": 15, "title": "...", "text": "...", "tone": "success" }, ..., { "title": "...", "text": "..." } ] },
    "nodes": { "<slide-id>": { ...slide... }, ... }
  }
}
Bands are checked highest "min" first; the last band has no "min" (catch-all). Band "tone" is optional: success | warning | danger.

Every slide may have: "kind" (required), "round" (e.g. "ROUND 2"), "eyebrow" (small label above the title), "time" (shown as a clock, e.g. "08:17"), "title", "body", "prompt" (the question, highlighted), "notes" (presenter-only), "tone" (danger = red pulse, success = green, shake = screen-shake on arrival), and "enter": {"points": -5, "skull": true} (applied automatically when the slide is reached; skull marks a sentinel-event-level disaster ☠️).

Slide kinds:
- "scene": text, then Continue. Needs "next".
- "choice": A/B/C options. "options": [{ "key": "A", "text": "...", "points": 2, "skull": false, "feedback": "shown after picking", "next": "<slide-id>" }]. After a pick the class sees the feedback and point change, and the best option is marked. Only the FIRST pick scores; afterwards I can press the other letters to show "what would have happened" (not scored), which displays the option's optional "what_if" text (or its "feedback" if there's none). Write "what_if" for any wrong answer whose real consequence happens on a later slide (e.g. it leads to a die roll or a disaster), so exploring it still teaches the lesson.
- "chance": an on-screen die roll. "sides": 6, "outcomes": [{ "min": 1, "max": 4, "title": "...", "text": "...", "points": 0, "tone": "danger", "next": "<slide-id>" }]. Outcomes must cover every number 1..sides exactly once.
- "activity": a discussion/team task. Optional "timer" (seconds, countdown with beep), "teams": [{ "name": "Team 1: WATER", "prompt": "..." }], "cards": [{ "label": "Patient A", "text": "..." }] with "cards_title" and "printable": true (adds a Print button), and "reveal": { "title": "...", "body": "..." } (hidden answer I reveal with a key press) or { "title": "...", "steps": ["...", "..."] } (revealed one item per key press, great for confirming answers as the class calls them out). Needs "next".
- "ending": shows the final score, the matching band, and a recap. No "next".

Text formatting inside any text field: **bold**, *italic*, lines starting with "- " become bullets, a blank line (\\n\\n) starts a new paragraph. No HTML, no images.

Excerpt of a real lesson in this format (some slides omitted, so not every "next" exists in this excerpt):
$example
GUIDE;
}

function adventure_outline(array $content): array
{
    $rows = [];
    foreach (($content['nodes'] ?? []) as $id => $n) {
        if (!is_array($n)) continue;
        $title = trim(implode(' · ', array_filter([
            (string) ($n['round'] ?? ''),
            (string) ($n['title'] ?? ''),
        ])));
        $rows[] = [
            'id'    => (string) $id,
            'kind'  => (string) ($n['kind'] ?? '?'),
            'title' => $title,
            'links' => adventure_node_links($n),
            'start' => (string) $id === (string) ($content['start'] ?? ''),
        ];
    }
    return $rows;
}
