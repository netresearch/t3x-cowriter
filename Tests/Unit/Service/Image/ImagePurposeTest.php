<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\T3Cowriter\Tests\Unit\Service\Image;

use Netresearch\T3Cowriter\Service\BackendLabels;
use Netresearch\T3Cowriter\Service\FieldSuggestion\FieldProfile;
use Netresearch\T3Cowriter\Service\FieldSuggestion\RecordContext;
use Netresearch\T3Cowriter\Service\FieldSuggestion\Tca;
use Netresearch\T3Cowriter\Service\Image\ImagePurpose;
use Netresearch\T3Cowriter\Tests\Support\XliffLanguageServiceTrait;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(ImagePurpose::class)]
#[CoversClass(RecordContext::class)]
#[CoversClass(BackendLabels::class)]
#[CoversClass(FieldProfile::class)]
#[CoversClass(Tca::class)]
final class ImagePurposeTest extends TestCase
{
    use XliffLanguageServiceTrait;

    protected function setUp(): void
    {
        parent::setUp();
        $this->useXliffLanguageService();
        $GLOBALS['TCA'] = [
            'tt_content' => [
                'ctrl'    => ['label' => 'header'],
                'columns' => [
                    'header'   => ['label' => 'Header', 'config' => ['type' => 'input']],
                    'bodytext' => ['label' => 'Text', 'config' => ['type' => 'text']],
                    'image'    => ['label' => 'Images', 'config' => ['type' => 'file']],
                ],
            ],
            'pages' => [
                'ctrl'    => ['label' => 'title'],
                'columns' => ['media' => ['label' => 'Media', 'config' => ['type' => 'file']]],
            ],
        ];
    }

    /**
     * @param array<string, array{label: string, value: string}> $fields
     */
    private function context(string $table, string $pageTitle, array $fields): RecordContext
    {
        return new RecordContext(table: $table, field: 'image', record: [], storedValue: '', pageTitle: $pageTitle, pageContent: '', slugPid: 3, recordFields: $fields);
    }

    #[Test]
    public function anElementGetsPurposeFieldAndAProposalFromHeadingPageAndText(): void
    {
        $described = (new ImagePurpose())->describe($this->context('tt_content', 'Airfryer Test', [
            'header'   => ['label' => 'Header', 'value' => 'Our test'],
            'bodytext' => ['label' => 'Text', 'value' => 'We tested twelve airfryers.'],
        ]), 'image');

        self::assertSame([
            'purpose'  => 'for element "Our test" on page "Airfryer Test"',
            'field'    => 'Images',
            'proposal' => 'Our test – Airfryer Test: We tested twelve airfryers.',
        ], $described);
    }

    #[Test]
    public function aPageNamesOnlyThePage(): void
    {
        $described = (new ImagePurpose())->describe($this->context('pages', 'Airfryer Test', [
            'title'    => ['label' => 'Title', 'value' => 'Airfryer Test'],
            'abstract' => ['label' => 'Abstract', 'value' => 'Twelve fryers compared.'],
        ]), 'media');

        self::assertSame(['for page "Airfryer Test"', 'Media', 'Airfryer Test: Twelve fryers compared.'], array_values($described));
    }

    #[Test]
    public function anUntitledElementOnTheRootLevelAndWithoutTextStillGetsAPurpose(): void
    {
        $described = (new ImagePurpose())->describe($this->context('tt_content', '', []), 'unknown_field');

        self::assertSame(['purpose' => 'for element "untitled"', 'field' => '', 'proposal' => ''], $described);
    }

    #[Test]
    public function theHeadingIsTakenOnlyFromTheFieldsTheReaderLetThrough(): void
    {
        // The row carries a header the user may not read; recordFields does not.
        $context   = new RecordContext(table: 'tt_content', field: 'image', record: ['header' => 'Secret'], storedValue: '', pageTitle: 'Page', pageContent: '', slugPid: 3);
        $described = (new ImagePurpose())->describe($context, 'image');

        self::assertSame('for element "untitled" on page "Page"', $described['purpose']);
        self::assertSame('Page', $described['proposal']);
    }

    #[Test]
    public function aLongTextIsShortenedAtAWordAndTheProposalStaysBelowTheLimit(): void
    {
        $text      = str_repeat('word ', 200);
        $described = (new ImagePurpose())->describe($this->context('tt_content', 'Page', [
            'header'   => ['label' => 'Header', 'value' => str_repeat('h', 400)],
            'bodytext' => ['label' => 'Text', 'value' => $text],
        ]), 'image');

        self::assertLessThanOrEqual(ImagePurpose::MAX_PROPOSAL_LENGTH, mb_strlen($described['proposal']));
        self::assertStringEndsWith('…', $described['proposal']);
        self::assertSame('word word…', ImagePurpose::shorten('word word word', 12));
        self::assertSame('abcdefghi…', ImagePurpose::shorten('abcdefghijklmnop', 10), 'no space in reach: cut hard');
        self::assertSame('short', ImagePurpose::shorten('short', 5));
    }

    #[Test]
    public function theExcerptComesFromTheFirstTextFieldThatHasText(): void
    {
        $described = (new ImagePurpose())->describe($this->context('tt_content', 'Page', [
            'header'    => ['label' => 'Header', 'value' => 'H'],
            'bodytext'  => ['label' => 'Text', 'value' => '  '],
            'subheader' => ['label' => 'Subheader', 'value' => 'Sub'],
        ]), 'image');

        self::assertSame('H – Page: Sub', $described['proposal']);
    }
}
