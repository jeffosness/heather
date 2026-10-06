<?php
declare(strict_types=1);

require_once __DIR__ . '/json_store.php';
require_once __DIR__ . '/presentation_types.php';

/**
 * The "plan a new lesson with ChatGPT" starter prompt shown on the
 * Presentations page. Built from the type registry so it stays accurate
 * as types are added — each type contributes its own `ai_guide`.
 *
 * Flow: Heather copies this into a new ChatGPT chat → designs the lesson
 * → sends PRESENTATION_FINALIZE_PHRASE → pastes ChatGPT's single code block
 * into a change request (→ GitHub issue) for Jeff + Claude to build/import.
 */

const PRESENTATION_FINALIZE_PHRASE = 'Finalize it for Jeff.';

function presentation_chatgpt_prompt(): string
{
    $types = '';
    foreach (presentation_types() as $key => $t) {
        $types .= "\n### Type: \"$key\" — {$t['label']}\n{$t['blurb']}\n\n";
        if (isset($t['ai_guide'])) $types .= ($t['ai_guide'])() . "\n";
    }
    $phrase = PRESENTATION_FINALIZE_PHRASE;

    return <<<PROMPT
You're helping me (Heather, a Surgical Technology instructor) design a new interactive lesson for my classroom presentation website. My husband Jeff builds the site, and he'll turn whatever we design into a working lesson. Please read this whole message before replying.

## How my presentation system works
- I run lessons on a projector in front of the class. I control everything from my laptop and the whole class participates together (they discuss and call out answers, and I click).
- Each lesson is a reusable "lesson" in my library. I schedule it for a class day and present it. Lessons get reused and improved every year.
- Scoring is currently ONE class-wide score that I control. I can also add or subtract points by hand during discussions.
- Every slide can have private presenter notes that only I see (on my laptop), e.g. talking points, answer keys, which of my PowerPoint decks/objectives it covers.
- Lessons are stored as structured JSON. The available lesson TYPES and their exact formats are below.
{$types}
## Things the system can't do YET (but Jeff can add)
Images or video on slides, students answering/voting on their own phones, separate team pages with team scores, sound effects beyond the timer beep, and any game format not listed above (e.g. Jeopardy board, flashcards, matching, sorting, linear slides). If my idea needs one of these, that's fine. Don't water it down. Design it the way it SHOULD work and list it under "New features needed" so Jeff can build it.

## How I want you to help
1. Interview me first. Ask a few questions at a time (not a giant list) about: the topic and which course/unit/objectives it covers, how long it should run, my class size and whether I'll use teams, and the feel I want (dramatic scenario, game show, quick review, etc.). I may paste content from my slides. Use it.
2. Propose an outline (the rounds/slides, the choices, where points are won and lost, any timers or team activities) and refine it with me until I'm happy.
3. Keep on-screen text projector-friendly: short, punchy, readable from the back of the room. Put the detail, sources, and teaching points in presenter notes instead.
4. Make the content clinically accurate for Surgical Technology students. If you're unsure about a clinical fact, flag it for me to verify rather than guessing.
5. Don't produce the final output until I say: "{$phrase}"

## When I say "{$phrase}"
Reply with ONLY one single markdown code block (so I can copy it with one click). Nothing before or after it. Inside the code block, do NOT use any other code fences/backticks. Use exactly this structure:

LESSON REQUEST: <lesson title>
Type: <existing type key, e.g. adventure, OR "NEW TYPE: <short name>">
Course / unit: <...>
Planned date: <date or "not scheduled yet">
Run time: <approx minutes>

SUMMARY
<2–4 sentences: what students do and what they should learn>

LEARNING OBJECTIVES
- <...>

FLOW OVERVIEW
<numbered list of every round/slide in order, including where branches split and rejoin, and points for each choice>

SCORING
<starting score, how points are won/lost, the final score bands and their messages>

NEW FEATURES NEEDED
<bullet list of anything the system can't do yet (see above), described precisely enough to build, or "None">

CLINICAL FACTS TO VERIFY
<anything I should double-check, or "None">

NOTES FOR JEFF
<anything else he should know>

LESSON JSON
<if the lesson fits an existing type: the COMPLETE lesson JSON in exactly that type's format, with every slide, option, outcome and presenter note fully written out (no placeholders, no "..."); if it's a new type: write "N/A — new type" and make FLOW OVERVIEW extra detailed instead>
PROMPT;
}

/** A short excerpt of the real Shift From Hell lesson, used as a format example. */
function presentation_example_excerpt(string $seedFile, array $nodeIds): string
{
    $lesson = read_json_file(__DIR__ . '/presentation_seeds/' . $seedFile, null);
    if (!is_array($lesson)) return '';
    $nodes = [];
    foreach ($nodeIds as $id) {
        if (isset($lesson['content']['nodes'][$id])) $nodes[$id] = $lesson['content']['nodes'][$id];
    }
    $excerpt = [
        'title'   => $lesson['title'],
        'type'    => $lesson['type'],
        'course'  => $lesson['course'] ?? '',
        'content' => ['start' => $lesson['content']['start'], 'scoring' => $lesson['content']['scoring'], 'nodes' => $nodes],
    ];
    return (string) json_encode($excerpt, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
}
