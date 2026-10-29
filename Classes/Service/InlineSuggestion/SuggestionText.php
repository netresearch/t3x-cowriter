<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\T3Cowriter\Service\InlineSuggestion;

/**
 * The instruction for an inline suggestion and the clean-up of the model's
 * answer into one line of plain text.
 *
 * The answer is shown as text after the cursor and inserted as text, never
 * parsed as markup, so it is stripped, not escaped: an escaped "&amp;" would
 * land in the document literally.
 *
 * @internal
 */
final class SuggestionText
{
    /**
     * Longest suggestion, in characters.
     */
    public const MAX_LENGTH = 200;

    private const INSTRUCTION = <<<'PROMPT'
        You complete text that an editor is writing in a content management system.
        The user message is the text before the editor's cursor. Reply with the words that should come next:
        at most one sentence, continuing exactly where the text stops.
        Reply with plain text only: no quotes, no Markdown, no HTML, no explanation, and do not repeat the given text.
        Do not complete a word the text ends in; continue after it.
        If there is no sensible continuation, reply with nothing.
        PROMPT;

    /**
     * Characters that start a suggestion without a space before them.
     */
    private const CLOSING_PUNCTUATION = '/^[,.;:!?…)\]}»”’"\']/u';

    /**
     * The system prompt; with the record's language when it is known,
     * otherwise the model keeps the language of the text.
     */
    public static function instruction(?string $language): string
    {
        return self::INSTRUCTION . "\n" . ($language !== null
            ? 'Write in ' . $language . '.'
            : 'Write in the language of the text.');
    }

    /**
     * One line of plain text from the model's answer: no markup, no entities,
     * no control or direction characters, no Markdown markers, cut after the
     * first sentence and at {@see self::MAX_LENGTH} characters. A space is put
     * before it when the text before the cursor does not end in one and the
     * suggestion does not start with closing punctuation. Empty when nothing
     * usable is left.
     */
    public static function clean(string $answer, string $textBefore): string
    {
        // Reasoning some models put into the answer itself.
        $plain = (string) preg_replace('#<think>.*?(</think>|$)#isu', '', $answer);
        // Tags are stripped after decoding, so a tag spelled with entities
        // goes as well.
        $plain = html_entity_decode($plain, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $plain = strip_tags($plain);
        // Direction overrides and zero-width characters go; line breaks,
        // tabs and the Unicode line and paragraph separators become spaces.
        $plain = (string) preg_replace('/[\x{200B}-\x{200F}\x{202A}-\x{202E}\x{2060}-\x{2069}\x{FEFF}]/u', '', $plain);
        $plain = (string) preg_replace('/[\p{Cc}\x{2028}\x{2029}]+/u', ' ', $plain);
        $plain = trim((string) preg_replace('/\s+/u', ' ', $plain));
        // Markdown: code ticks, emphasis, a list or heading marker in front.
        $plain = str_replace(['`', '**', '__'], '', $plain);
        $plain = trim((string) preg_replace('/^(?:#{1,6}|[-*+]|\d+[.)])\s+/u', '', $plain));
        // Quotes around the whole answer.
        $plain = trim((string) preg_replace('/^(["“„«])(.*)(["”“»])$/u', '$2', $plain));

        if ($plain === '') {
            return '';
        }

        if (preg_match('/^.*?[.!?…](?=\s|$)/u', $plain, $sentence) === 1) {
            $plain = $sentence[0];
        }

        $plain = self::shorten($plain);

        $needsSpace = $textBefore !== ''
            && preg_match('/\s$/u', $textBefore) !== 1
            && preg_match(self::CLOSING_PUNCTUATION, $plain) !== 1;

        return ($needsSpace ? ' ' : '') . $plain;
    }

    private static function shorten(string $plain): string
    {
        if (mb_strlen($plain) <= self::MAX_LENGTH) {
            return $plain;
        }

        $cut   = mb_substr($plain, 0, self::MAX_LENGTH);
        $space = mb_strrpos($cut, ' ');

        return rtrim($space !== false && $space > self::MAX_LENGTH / 2 ? mb_substr($cut, 0, $space) : $cut);
    }
}
