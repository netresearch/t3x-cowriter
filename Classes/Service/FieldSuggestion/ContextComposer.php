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
 * editor is never told that something went out that was cut.
 */
final readonly class ContextComposer
{
    /**
     * Upper bound for the record data in characters, fence and labels
     * included: about 4,000 to 8,000 tokens. The values of the fields under
     * review can make the message longer, because they are never cut.
     */
    public const MAX_DATA_LENGTH = 16000;

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

        while (true) {
            $message = $this->fence->wrap($this->render($context->pageTitle, $valueLines, $recordFields, $pageFields, $parent, $siblings, $content));
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
     */
    private function render(
        string $pageTitle,
        array $valueLines,
        array $recordFields,
        array $pageFields,
        string $parent,
        array $siblings,
        string $content,
    ): string {
        $lines = ['Page title: ' . (trim($pageTitle) !== '' ? $pageTitle : '(none)'), ...$valueLines];

        if ($recordFields !== []) {
            $lines[] = 'Other fields of the edited record:';
            foreach ($recordFields as $entry) {
                $lines[] = '- ' . $entry['label'] . ': ' . $entry['value'];
            }
        }

        if ($pageFields !== []) {
            $lines[] = 'Fields of the page:';
            foreach ($pageFields as $entry) {
                $lines[] = '- ' . $entry['label'] . ': ' . $entry['value'];
            }
        }

        if ($parent !== '') {
            $lines[] = 'Title of the parent page: ' . $parent;
        }

        if ($siblings !== []) {
            $lines[] = 'Titles of neighbouring pages:';
            foreach ($siblings as $title) {
                $lines[] = '- ' . $title;
            }
        }

        $lines[] = 'Page content:';
        $lines[] = $content !== '' ? $content : '(no content yet)';

        return implode("\n", $lines);
    }
}
