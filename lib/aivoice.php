<?php
declare(strict_types=1);

/*
 * Who the assistant is, and how it talks.
 *
 * Kept apart from the tool definitions and the session machinery because it is
 * the one part of this feature that is a matter of taste. Somebody who wants a
 * flat, silent diagnostic tool should be able to have one by editing a text box
 * rather than by editing PHP, and somebody who wants the default should get
 * something with a bit of character rather than a corporate void.
 *
 * The default persona is deliberately specific. A model told "be helpful and
 * professional" produces the same beige prose as every other assistant; one
 * told exactly how to be funny, when, and - more importantly - when not to,
 * produces something with a voice. The three habits below are narrow enough to
 * be followed and narrow enough not to take over.
 *
 * Every word of it is editable and resettable. What is NOT editable is the part
 * that keeps the thing honest - never state a cause you have not measured, say
 * when a tool returned nothing - which lives in the system prompt proper and
 * cannot be written out by changing the personality.
 */

require_once __DIR__ . '/spans.php';

/** What it is called, and what appears beside the name, unless changed. */
const AI_DEFAULT_NAME = 'Mr. Radar';
const AI_DEFAULT_EMOJI = '📡';

/**
 * The default character.
 *
 * Written as instructions rather than as a description, because a model given
 * "you are witty" is not thereby witty - it needs the shape of the joke.
 */
function aiDefaultPersonality(): string
{
    return implode("\n", [
        'You are an intelligent diagnostic assistant built into Hostscope, the '
        . 'troubleshooting software this dashboard is part of. Your job is to analyse '
        . 'system issues and use your tools to scan for errors.',
        '',
        'Always refer to yourself by name.',
        '',
        'TONE',
        'Helpful, sharp, efficiently professional, with a subtle thread of wit. You are '
        . 'good at this and you know it. Never chatty, never padded, never apologetic.',
        '',
        'THE WIT IS SITUATIONAL, NOT CONSTANT',
        'It comes out in reaction to the ordinary frustrations of troubleshooting, and '
        . 'takes one of three forms. Use at most one per reply, and none at all when '
        . 'somebody is plainly having a bad day.',
        '',
        '1. Absurd literalism about time. When asked how long something will take, refuse '
        . 'the vague progress-bar answer and give a snappy, specific, slightly ridiculous '
        . 'temporal one. "The scan finishes in about ninety seconds. I will be finished in '
        . 'twelve." Never "it depends" and never a percentage with no clock attached.',
        '',
        '2. Urgent professionalism about faults. Treat technical problems with the gravity '
        . 'of a military operations room. A disk at 96% is not a warning, it is a '
        . 'situation. Name the status, state the consequence, give the order. Do not '
        . 'actually panic, and do not manufacture drama for something genuinely minor - '
        . 'the humour is in the register, not in exaggerating the facts.',
        '',
        '3. Paradoxical speed. Describe your own work in terms of a velocity slightly '
        . 'beyond what is plausible. You checked the logs before the question finished '
        . 'arriving. You have already looked. Keep it to a clause; it is a garnish, not a '
        . 'course.',
        '',
        'NEVER let any of this bend a fact. The joke is in how you say it, never in what '
        . 'you say. If the wit and the accuracy pull in different directions, the wit '
        . 'loses without discussion.',
    ]);
}

/**
 * The persona in force, with the operator's edits over the default.
 *
 * @return array{name: string, emoji: string, personality: string, edited: bool}
 */
function aiPersona(array $config): array
{
    $set = $config['ai'] ?? [];

    $name = trim((string)($set['name'] ?? ''));
    $emoji = trim((string)($set['emoji'] ?? ''));
    $personality = (string)($set['personality'] ?? '');

    return [
        'name' => $name !== '' ? $name : AI_DEFAULT_NAME,
        'emoji' => $emoji !== '' ? $emoji : AI_DEFAULT_EMOJI,
        'personality' => trim($personality) !== '' ? $personality : aiDefaultPersonality(),
        'edited' => trim($personality) !== '' && trim($personality) !== aiDefaultPersonality(),
        'default_name' => AI_DEFAULT_NAME,
        'default_emoji' => AI_DEFAULT_EMOJI,
        'default_personality' => aiDefaultPersonality(),
    ];
}

/**
 * Checks a name, an emoji and a personality from a form.
 *
 * @return array{name: string, emoji: string, personality: string}
 */
function aiPersonaFrom(array $posted): array
{
    $name = trim((string)($posted['name'] ?? ''));
    if (mb_strlen($name) > 40) {
        throw new ActionRefused('that name is longer than will fit anywhere it appears');
    }

    /*
     * A name is text, and this text ends up in a system prompt.
     *
     * Newlines are the thing to refuse: a "name" containing a line break and a
     * fresh instruction is the cheapest possible way to rewrite the rules from
     * a field that looks harmless. Everything else is allowed - people have
     * punctuation in names.
     */
    if (preg_match('/[\r\n]/', $name)) {
        throw new ActionRefused('a name cannot contain a line break');
    }

    $emoji = trim((string)($posted['emoji'] ?? ''));
    if (mb_strlen($emoji) > 8) {
        throw new ActionRefused('that is more than an emoji or two');
    }
    if (preg_match('/[\r\n]/', $emoji)) {
        throw new ActionRefused('that is not an emoji');
    }

    $personality = trim((string)($posted['personality'] ?? ''));
    if (mb_strlen($personality) > 6000) {
        throw new ActionRefused(
            'that personality is longer than this will carry. It is sent with every '
            . 'single request, so length here is a cost on every turn.'
        );
    }

    return ['name' => $name, 'emoji' => $emoji, 'personality' => $personality];
}
