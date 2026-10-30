<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\T3Cowriter\Service\Image;

use Netresearch\T3Cowriter\Service\BackendLabels;
use Netresearch\T3Cowriter\Service\FieldSuggestion\FieldProfile;
use Netresearch\T3Cowriter\Service\FieldSuggestion\RecordContext;
use Netresearch\T3Cowriter\Service\FieldSuggestion\Tca;

/**
 * What the image dialog shows before anything is generated: what the image
 * is for ("for element 'X' on page 'Y'") and a proposal for its description,
 * built from the record and its page. No model is asked for either.
 *
 * The proposal names the subject (the element's heading, the page title) and
 * the beginning of the element's text, in the language they are written in.
 * It becomes the image's alternative text once the image is generated, so it
 * describes the subject and carries nothing else; the purpose is shown
 * separately.
 */
final readonly class ImagePurpose
{
    /**
     * Characters of the element's text in the proposal.
     */
    public const MAX_EXCERPT_LENGTH = 300;

    /**
     * Longest proposal, well below the 1000 characters the generation route
     * accepts, so the editor has room to add to it.
     */
    public const MAX_PROPOSAL_LENGTH = 500;

    /**
     * Text fields the excerpt is taken from, the first one that has text.
     */
    private const EXCERPT_FIELDS = ['bodytext', 'abstract', 'description', 'subheader', 'teaser'];

    public function __construct(
        private BackendLabels $labels = new BackendLabels(),
    ) {}

    /**
     * @return array{purpose: string, field: string, proposal: string}
     */
    public function describe(RecordContext $context, string $field): array
    {
        $isPage     = $context->table === 'pages';
        $labelField = Tca::labelField($context->table);
        // From the fields the reader let through: the label field too may be an excluded field.
        $recordName = $labelField === null ? '' : trim($context->recordFields[$labelField]['value'] ?? '');
        $pageTitle  = $this->plainText($context->pageTitle);
        $column     = Tca::column($context->table, $field);

        $purpose = match (true) {
            $isPage           => $this->labels->get('about.purpose.page', $recordName !== '' ? $recordName : $pageTitle),
            $pageTitle !== '' => $this->labels->get('about.purpose.element', $this->orUntitled($recordName), $pageTitle),
            default           => $this->labels->get('about.purpose.elementOnly', $this->orUntitled($recordName)),
        };

        $subjects = array_values(array_unique(array_filter(
            $isPage ? [$recordName !== '' ? $recordName : $pageTitle] : [$recordName, $pageTitle],
            static fn (string $subject): bool => $subject !== '',
        )));
        $excerpt  = $this->excerpt($context);
        $proposal = implode(' – ', $subjects);
        if ($excerpt !== '') {
            $proposal = $proposal === '' ? $excerpt : $proposal . ': ' . $excerpt;
        }

        return [
            'purpose'  => $purpose,
            'field'    => $column === null ? '' : FieldProfile::columnLabel($field, $column),
            'proposal' => self::shorten($proposal, self::MAX_PROPOSAL_LENGTH),
        ];
    }

    /**
     * $text cut to $length characters at a word boundary, with an ellipsis.
     */
    public static function shorten(string $text, int $length): string
    {
        if (mb_strlen($text, 'UTF-8') <= $length) {
            return $text;
        }

        $cut   = mb_substr($text, 0, $length - 1, 'UTF-8');
        $space = mb_strrpos($cut, ' ', 0, 'UTF-8');

        return rtrim($space !== false && $space > $length / 2 ? mb_substr($cut, 0, $space, 'UTF-8') : $cut) . '…';
    }

    private function excerpt(RecordContext $context): string
    {
        foreach (self::EXCERPT_FIELDS as $field) {
            $value = trim($context->recordFields[$field]['value'] ?? '');
            if ($value !== '') {
                return self::shorten($value, self::MAX_EXCERPT_LENGTH);
            }
        }

        return '';
    }

    private function orUntitled(string $name): string
    {
        return $name !== '' ? $name : $this->labels->get('about.purpose.untitled');
    }

    private function plainText(mixed $value): string
    {
        $text = is_scalar($value) ? html_entity_decode(strip_tags((string) $value), ENT_QUOTES | ENT_HTML5, 'UTF-8') : '';

        return trim((string) preg_replace('/\s+/u', ' ', $text));
    }
}
