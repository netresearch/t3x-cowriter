<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\T3Cowriter\Service\Presentation;

use DOMElement;
use DOMNode;
use Masterminds\HTML5;

/**
 * Splits a stored rich text into its top-level blocks with an HTML5 parser
 * (masterminds/html5, which TYPO3 core requires itself).
 *
 * A block element on the top level (paragraph, list, table, heading, …) is a
 * block of its own. Loose text and inline elements between them — a text
 * stored without paragraphs — are gathered into one block per run. Comments
 * and whitespace between blocks are dropped. Nested structure is never split:
 * a heading inside a wrapping div stays inside that div's block.
 *
 * @internal
 */
final readonly class BlockParser
{
    /**
     * Elements that stand as a block of their own on the top level.
     */
    private const BLOCK_ELEMENTS = [
        'address', 'article', 'aside', 'blockquote', 'details', 'div', 'dl', 'fieldset', 'figure',
        'footer', 'form', 'h1', 'h2', 'h3', 'h4', 'h5', 'h6', 'header', 'hr', 'main', 'nav', 'ol',
        'p', 'pre', 'section', 'table', 'ul',
    ];

    /**
     * Heading elements and their level.
     */
    private const HEADING_LEVELS = ['h1' => 1, 'h2' => 2, 'h3' => 3, 'h4' => 4, 'h5' => 5, 'h6' => 6];

    /**
     * @return list<Block>
     */
    public function parse(string $html): array
    {
        $html5    = new HTML5();
        $fragment = $html5->loadHTMLFragment($html);

        $blocks = [];
        $inline = [];
        foreach ($fragment->childNodes as $node) {
            if ($node->nodeType === XML_COMMENT_NODE) {
                continue;
            }

            // The HTML5 parser gives element names in lower case.
            if ($node instanceof DOMElement && in_array($node->tagName, self::BLOCK_ELEMENTS, true)) {
                $blocks = $this->flushInline($inline, $html5, $blocks);
                $inline = [];

                $blocks[] = new Block(
                    $html5->saveHTML($node),
                    self::plainText($node->textContent),
                    self::HEADING_LEVELS[$node->tagName] ?? 0,
                );

                continue;
            }

            $inline[] = $node;
        }

        return $this->flushInline($inline, $html5, $blocks);
    }

    /**
     * Text on one line: tags gone, entities decoded, whitespace (including
     * no-break spaces) collapsed.
     */
    public static function plainText(string $text): string
    {
        return trim((string) preg_replace('/[\s\x{00A0}]+/u', ' ', $text));
    }

    /**
     * @param list<DOMNode> $nodes
     * @param list<Block>   $blocks
     *
     * @return list<Block>
     */
    private function flushInline(array $nodes, HTML5 $html5, array $blocks): array
    {
        $html = '';
        $text = '';
        foreach ($nodes as $node) {
            $html .= $html5->saveHTML($node);
            // No space between them: "Pl<b>ai</b>n" is one word.
            $text .= $node->textContent;
        }

        $text = self::plainText($text);
        if ($text === '' && trim(strip_tags($html)) === trim($html)) {
            // Only whitespace between two blocks.
            return $blocks;
        }

        $blocks[] = new Block(trim($html), $text);

        return $blocks;
    }
}
