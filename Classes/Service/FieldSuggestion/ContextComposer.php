<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\T3Cowriter\Service\FieldSuggestion;

use Netresearch\T3Cowriter\Service\Transparency\ContextSources;

/**
 * Writes the record data of a field suggestion or field overview request
 * into one fenced user message, within MAX_DATA_LENGTH characters, and
 * counts what went in.
 *
 * The lines about the field values the model works on are never cut. When
 * the rest does not fit, it is cut in this order until it does: the titles
 * of neighbouring pages (from the last), the parent page title, the page
 * content (from its end), the page fields and the record's other fields
 * (from the last). The source list is counted from what is left, so the
 * editor is never told that something went out that was cut; a source the
 * cut removed completely keeps its heading with LEFT_OUT, so the model does
 * not take it for an empty one.
 */
final readonly class ContextComposer
{
    /**
     * Upper bound for the record data in characters, fence and labels
     * included: about 4,000 to 8,000 tokens. The values of the fields under
     * review can make the message longer, because they are never cut.
     */
    public const MAX_DATA_LENGTH = 16000;

    /**
     * Stands for a source the cut removed completely: the model must not read
     * it as "there is none" (an empty page, a page without neighbours).
     */
    public const LEFT_OUT = '(left out for length)';

    public function __construct(
        private UntrustedDataFence $fence = new UntrustedDataFence(),
    ) {}

    /**
     * @param list<string> $valueLines lines about the field values the model works on; never cut
     * @param list<string> $skip       record fields already named in $valueLines
     * @param bool         $valuesSent whether $valueLines hold a value at all, not only placeholders
     * @param bool         $reviewed   the values are those of the fields under review (the overview)
     */
    public function compose(RecordContext $context, array $valueLines, array $skip, bool $valuesSent, bool $reviewed = false): ComposedContext
    {
        $recordFields = array_values(array_filter(
            $context->recordFields,
            static fn (array $entry, string $field): bool => !in_array($field, $skip, true) && trim($entry['value']) !== '',
            ARRAY_FILTER_USE_BOTH,
        ));
        $pageFields = array_values(array_filter(
            $context->pageFields,
            static fn (array $entry): bool => trim($entry['value']) !== '',
        ));
        $parent   = trim($context->parentPageTitle);
        $siblings = array_values(array_filter(array_map(trim(...), $context->siblingPageTitles), static fn (string $title): bool => $title !== ''));
        $content  = trim($context->pageContent);
        // What there was before the cut: a source the cut removes completely
        // is named with LEFT_OUT, so the model does not read it as missing.
        $had = [
            'recordFields' => $recordFields !== [],
            'pageFields'   => $pageFields !== [],
            'parent'       => $parent !== '',
            'siblings'     => $siblings !== [],
            'content'      => $content !== '',
        ];

        while (true) {
            $message = $this->fence->wrap($this->render($context->pageTitle, $valueLines, $recordFields, $pageFields, $parent, $siblings, $content, $had));
            $over    = mb_strlen($message, 'UTF-8') - self::MAX_DATA_LENGTH;
            if ($over <= 0) {
                break;
            }

            if ($siblings !== []) {
                array_pop($siblings);
            } elseif ($parent !== '') {
                $parent = '';
            } elseif ($content !== '') {
                $content = trim(mb_substr($content, 0, max(0, mb_strlen($content, 'UTF-8') - $over), 'UTF-8'));
            } elseif ($pageFields !== []) {
                array_pop($pageFields);
            } elseif ($recordFields !== []) {
                array_pop($recordFields);
            } else {
                // Only the field values are left, and they are never cut.
                break;
            }
        }

        return new ComposedContext($message, new ContextSources(
            pageTitle: trim($context->pageTitle) !== '',
            fieldValues: $valuesSent,
            pageFields: count($pageFields),
            recordFields: count($recordFields),
            // RecordContextReader joins the elements with a blank line and
            // keeps none inside one.
            contentElements: $content === '' ? 0 : count(explode("\n\n", $content)),
            parentTitle: $parent !== '',
            siblingTitles: count($siblings),
            reviewedFields: $reviewed,
        ));
    }

    /**
     * @param list<string>                              $valueLines
     * @param list<array{label: string, value: string}> $recordFields
     * @param list<array{label: string, value: string}> $pageFields
     * @param list<string>                              $siblings
     * @param array<string, bool>                       $had          which sources there were before the cut
     */
    private function render(
        string $pageTitle,
        array $valueLines,
        array $recordFields,
        array $pageFields,
        string $parent,
        array $siblings,
        string $content,
        array $had,
    ): string {
        $lines = ['Page title: ' . (trim($pageTitle) !== '' ? $pageTitle : '(none)'), ...$valueLines];

        if ($recordFields !== [] || $had['recordFields']) {
            $lines[] = 'Other fields of the edited record:';
            foreach ($recordFields as $entry) {
                $lines[] = '- ' . $entry['label'] . ': ' . $entry['value'];
            }

            if ($recordFields === []) {
                $lines[] = self::LEFT_OUT;
            }
        }

        if ($pageFields !== [] || $had['pageFields']) {
            $lines[] = 'Fields of the page:';
            foreach ($pageFields as $entry) {
                $lines[] = '- ' . $entry['label'] . ': ' . $entry['value'];
            }

            if ($pageFields === []) {
                $lines[] = self::LEFT_OUT;
            }
        }

        if ($parent !== '' || $had['parent']) {
            $lines[] = 'Title of the parent page: ' . ($parent !== '' ? $parent : self::LEFT_OUT);
        }

        if ($siblings !== [] || $had['siblings']) {
            $lines[] = 'Titles of neighbouring pages:';
            foreach ($siblings as $title) {
                $lines[] = '- ' . $title;
            }

            if ($siblings === []) {
                $lines[] = self::LEFT_OUT;
            }
        }

        $lines[] = 'Page content:';
        $lines[] = match (true) {
            $content !== '' => $content,
            $had['content'] => self::LEFT_OUT,
            default         => '(no content yet)',
        };

        return implode("\n", $lines);
    }
}
