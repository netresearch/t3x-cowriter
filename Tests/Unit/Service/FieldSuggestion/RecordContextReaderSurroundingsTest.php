<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\T3Cowriter\Tests\Unit\Service\FieldSuggestion;

use Netresearch\T3Cowriter\Domain\DTO\FieldSuggestionRequest;
use Netresearch\T3Cowriter\EventListener\RegisterFieldSuggestionControlsListener;
use Netresearch\T3Cowriter\Service\FieldSuggestion\FieldProfile;
use Netresearch\T3Cowriter\Service\FieldSuggestion\FieldSuggestionException;
use Netresearch\T3Cowriter\Service\FieldSuggestion\RecordContext;
use Netresearch\T3Cowriter\Service\FieldSuggestion\RecordContextReader;
use Netresearch\T3Cowriter\Service\FieldSuggestion\RecordFinder;
use Netresearch\T3Cowriter\Service\FieldSuggestion\Tca;
use Netresearch\T3Cowriter\Service\RecordEditAccess;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use TYPO3\CMS\Core\Authentication\AccessCheckResult;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;
use TYPO3\CMS\Core\Type\Bitmask\Permission;

/**
 * What the reader adds around the record: the page's descriptive fields,
 * the parent page and the neighbouring pages, and the reads for the image
 * dialog. RecordFinder is a stub keyed by table and uid; the functional
 * tests run the same rules against a database.
 */
#[CoversClass(RecordContextReader::class)]
#[CoversClass(RecordContext::class)]
#[CoversClass(FieldSuggestionException::class)]
#[CoversClass(FieldSuggestionRequest::class)]
#[CoversClass(FieldProfile::class)]
#[CoversClass(Tca::class)]
#[CoversClass(RecordEditAccess::class)]
final class RecordContextReaderSurroundingsTest extends TestCase
{
    private const PARENT = ['uid' => 2, 'pid' => 1, 'title' => 'Kitchen', 'doktype' => 1];

    private const PAGE = [
        'uid'      => 3, 'pid' => 2, 'title' => 'Airfryer Test', 'doktype' => 1, 'sys_language_uid' => 0,
        'subtitle' => '<b>Twelve</b> fryers', 'abstract' => 'We tested.', 'description' => 'Meta', 'author' => 'Jane Doe',
        'TSconfig' => 'secret', 'slug' => '/airfryer', 'seo_title' => '', 'keywords' => 'fryer',
    ];

    private const CONTENT = ['uid' => 40, 'pid' => 3, 'header' => 'Our test', 'bodytext' => '<p>Body</p>', 'sys_language_uid' => 0];

    protected function setUp(): void
    {
        $control        = [RegisterFieldSuggestionControlsListener::CONTROL_NAME => ['renderType' => RegisterFieldSuggestionControlsListener::NODE_NAME]];
        $GLOBALS['TCA'] = [
            'pages' => [
                'ctrl'    => ['label' => 'title', 'languageField' => 'sys_language_uid', 'transOrigPointerField' => 'l10n_parent'],
                'columns' => [
                    'title'       => ['label' => 'Title', 'config' => ['type' => 'input']],
                    'subtitle'    => ['label' => 'Subtitle', 'config' => ['type' => 'input']],
                    'abstract'    => ['label' => 'Abstract', 'config' => ['type' => 'text']],
                    'description' => ['label' => 'Description', 'exclude' => true, 'config' => ['type' => 'text']],
                    'keywords'    => ['label' => 'Keywords', 'config' => ['type' => 'text']],
                    'seo_title'   => ['label' => 'SEO title', 'config' => ['type' => 'input']],
                    'author'      => ['label' => 'Author', 'config' => ['type' => 'input']],
                    'TSconfig'    => ['label' => 'TSconfig', 'config' => ['type' => 'text']],
                    'slug'        => ['label' => 'Slug', 'config' => ['type' => 'slug', 'fieldControl' => $control]],
                    'tx_teaser'   => ['label' => 'Teaser', 'config' => ['type' => 'text', 'fieldControl' => $control]],
                ],
            ],
            'tt_content' => [
                'ctrl'    => ['label' => 'header', 'languageField' => 'sys_language_uid'],
                'columns' => [
                    'header'    => ['label' => 'Header', 'config' => ['type' => 'input', 'fieldControl' => $control]],
                    'subheader' => ['label' => 'Subheader', 'exclude' => true, 'config' => ['type' => 'input', 'fieldControl' => $control]],
                    'bodytext'  => ['label' => 'Text', 'config' => ['type' => 'text']],
                    'image'     => ['label' => 'Images', 'exclude' => true, 'config' => ['type' => 'file']],
                ],
            ],
        ];
    }

    /**
     * @param array<string, array<string, mixed>> $records      keyed "table:uid"
     * @param list<array<string, mixed>>          $subpages     subpages of the parent page
     * @param array<string, array<string, mixed>> $translations keyed "uid:language"
     */
    private function finder(array $records = [], array $subpages = [], array $translations = []): RecordFinder
    {
        $records += ['pages:2' => self::PARENT, 'pages:3' => self::PAGE, 'tt_content:40' => self::CONTENT];

        $finder = $this->createStub(RecordFinder::class);
        $finder->method('findRecord')->willReturnCallback(
            static fn (string $table, int $uid): ?array => $records[$table . ':' . $uid] ?? null,
        );
        $finder->method('findPageContent')->willReturn([]);
        $finder->method('findVisibleSubpages')->willReturnCallback(
            static fn (int $pid): array => $pid === 2 ? $subpages : [],
        );
        $finder->method('findPageTranslation')->willReturnCallback(
            static fn (int $uid, int $language): ?array => $translations[$uid . ':' . $language] ?? null,
        );
        $finder->method('isVisibleRow')->willReturnCallback(
            static fn (array $row): bool => (int) ($row['hidden'] ?? 0) === 0,
        );

        return $finder;
    }

    /**
     * A non-admin editor; every check passes unless named in $denied:
     * "<type>:<value>" for check(), "show:<uid>" for PAGE_SHOW on a page.
     *
     * @param list<string> $denied
     */
    private function editor(array $denied = []): BackendUserAuthentication
    {
        $user = $this->createStub(BackendUserAuthentication::class);
        $user->method('isAdmin')->willReturn(false);
        $user->method('check')->willReturnCallback(
            static fn (string $type, string $value): bool => !in_array($type . ':' . $value, $denied, true),
        );
        $user->method('doesUserHaveAccess')->willReturnCallback(
            static fn (array $row, int $perms): bool => $perms !== Permission::PAGE_SHOW || !in_array('show:' . ($row['uid'] ?? 0), $denied, true),
        );
        if (method_exists(BackendUserAuthentication::class, 'checkRecordEditAccess')) {
            $user->method('checkRecordEditAccess')->willReturn(new AccessCheckResult(true));
        } else {
            $user->method('recordEditAccessInternals')->willReturn(true);
        }

        return $user;
    }

    /**
     * @param array<string, mixed> $body
     */
    private function read(RecordFinder $finder, array $body = [], ?BackendUserAuthentication $user = null): RecordContext
    {
        return (new RecordContextReader($finder))->read(
            FieldSuggestionRequest::fromArray($body + ['table' => 'tt_content', 'field' => 'header', 'uid' => 40]),
            $user ?? $this->editor(),
        );
    }

    /**
     * @return array<string, mixed>
     */
    private static function page(int $uid, string $title, array $extra = []): array
    {
        return $extra + ['uid' => $uid, 'pid' => 2, 'title' => $title, 'doktype' => 1];
    }

    #[Test]
    public function aContentElementGetsTheDescriptivePageFieldsByNameOnly(): void
    {
        $context = $this->read($this->finder());

        self::assertSame([
            'subtitle'    => ['label' => 'Subtitle', 'value' => 'Twelve fryers'],
            'abstract'    => ['label' => 'Abstract', 'value' => 'We tested.'],
            'description' => ['label' => 'Description', 'value' => 'Meta'],
            'keywords'    => ['label' => 'Keywords', 'value' => 'fryer'],
        ], $context->pageFields, 'never title, slug, author or TSconfig; empty fields left out');
    }

    #[Test]
    public function aPageFieldWithTheSuggestionControlCountsAsDescriptive(): void
    {
        $context = $this->read($this->finder(['pages:3' => self::PAGE + ['tx_teaser' => 'Teaser text']]));

        self::assertSame('Teaser text', $context->pageFields['tx_teaser']['value'] ?? null);
    }

    #[Test]
    public function anExcludedPageFieldNeedsTheRightAndPagesNeedTheReadRight(): void
    {
        self::assertArrayNotHasKey('description', $this->read($this->finder(), user: $this->editor(['non_exclude_fields:pages:description']))->pageFields);
        self::assertSame([], $this->read($this->finder(), user: $this->editor(['tables_select:pages']))->pageFields);
    }

    #[Test]
    public function aPageFieldIsCutAtTheFieldLimit(): void
    {
        $context = $this->read($this->finder(['pages:3' => ['abstract' => str_repeat('ä', 1500)] + self::PAGE]));

        self::assertSame(RecordContextReader::MAX_RECORD_FIELD_LENGTH, mb_strlen($context->pageFields['abstract']['value']));
    }

    #[Test]
    public function translatedContentReadsTitleAndFieldsOfThePageTranslation(): void
    {
        $content    = ['sys_language_uid' => 1] + self::CONTENT;
        $translated = ['uid' => 9, 'pid' => 2, 'title' => 'Heißluft-Test', 'abstract' => 'Wir haben getestet.', 'sys_language_uid' => 1, 'l10n_parent' => 3];
        $context    = $this->read($this->finder(['tt_content:40' => $content], [], ['3:1' => $translated]));

        self::assertSame('Heißluft-Test', $context->pageTitle);
        self::assertSame(['abstract' => ['label' => 'Abstract', 'value' => 'Wir haben getestet.']], $context->pageFields);
    }

    #[Test]
    public function theParentTitleIsSentWhenTheEditorMaySeeTheParent(): void
    {
        self::assertSame('Kitchen', $this->read($this->finder())->parentPageTitle);
        self::assertSame('', $this->read($this->finder(), user: $this->editor(['show:2']))->parentPageTitle, 'outside the web mount');
        self::assertSame('', $this->read($this->finder(['pages:2' => ['hidden' => 1] + self::PARENT]))->parentPageTitle);
        self::assertSame('', $this->read($this->finder(['pages:2' => ['doktype' => 254] + self::PARENT]))->parentPageTitle);
        self::assertSame('', $this->read($this->finder(['pages:3' => ['pid' => 0] + self::PAGE]))->parentPageTitle, 'root level');
    }

    #[Test]
    public function neighboursAreVisibleContentPagesTheEditorMaySeeNeverThePageItself(): void
    {
        $subpages = [
            self::page(10, 'Toasters'),
            self::page(3, 'Airfryer Test'),
            self::page(11, 'Hidden', ['hidden' => 1]),
            self::page(12, 'Folder', ['doktype' => 254]),
            self::page(13, 'Spacer', ['doktype' => 199]),
            self::page(14, 'Recycler', ['doktype' => 255]),
            self::page(15, 'Locked'),
            self::page(16, 'Mixers'),
        ];

        self::assertSame(['Toasters', 'Mixers'], $this->read($this->finder([], $subpages), user: $this->editor(['show:15']))->siblingPageTitles);
        self::assertSame([], $this->read($this->finder([], $subpages), user: $this->editor(['tables_select:pages']))->siblingPageTitles);
    }

    #[Test]
    public function atMostFiveNeighboursAreSentInPageTreeOrder(): void
    {
        $subpages = array_map(static fn (int $i): array => self::page(100 + $i, 'Page ' . $i), range(1, 8));

        self::assertSame(['Page 1', 'Page 2', 'Page 3', 'Page 4', 'Page 5'], $this->read($this->finder([], $subpages))->siblingPageTitles);
    }

    #[Test]
    public function neighboursAreNamedInTheRecordsLanguageWhenAVisibleTranslationExists(): void
    {
        $content  = ['sys_language_uid' => 1] + self::CONTENT;
        $subpages = [self::page(10, 'Toasters'), self::page(16, 'Mixers'), self::page(17, 'Kettles')];
        $context  = $this->read($this->finder(['tt_content:40' => $content], $subpages, [
            '10:1' => ['uid' => 20, 'title' => 'Toaster DE'],
            '16:1' => ['uid' => 21, 'title' => 'Mixer DE', 'hidden' => 1],
            '17:1' => ['uid' => 22, 'title' => ' '],
            '2:1'  => ['uid' => 23, 'title' => 'Küche'],
        ]));

        self::assertSame(['Toaster DE', 'Mixers', 'Kettles'], $context->siblingPageTitles, 'a hidden or empty translation falls back');
        self::assertSame('Küche', $context->parentPageTitle);
    }

    #[Test]
    public function aPageRecordGetsItsOwnDescriptiveFieldsAsRecordFieldsAndNoPageFields(): void
    {
        $context = $this->read(
            $this->finder([], [self::page(10, 'Toasters'), self::page(3, 'Airfryer Test')]),
            ['table' => 'pages', 'field' => 'slug', 'uid' => 3],
        );

        self::assertSame([], $context->pageFields);
        // The field itself (slug) is among them; the message leaves it out of this list.
        self::assertSame(['title', 'subtitle', 'abstract', 'description', 'keywords', 'slug'], array_keys($context->recordFields));
        self::assertSame('Kitchen', $context->parentPageTitle);
        self::assertSame(['Toasters'], $context->siblingPageTitles);
    }

    #[Test]
    public function aNewElementGetsThePagesSurroundingsAndANewPageItsFutureNeighbours(): void
    {
        $finder = $this->finder([], [self::page(10, 'Toasters')]);

        $element = $this->read($finder, ['uid' => 'NEW1', 'pid' => 3]);
        self::assertSame('Airfryer Test', $element->pageTitle);
        self::assertSame('Kitchen', $element->parentPageTitle);
        self::assertSame(['Toasters'], $element->siblingPageTitles);
        self::assertArrayHasKey('abstract', $element->pageFields);

        $page = $this->read($finder, ['table' => 'pages', 'field' => 'slug', 'uid' => 'NEW1', 'pid' => 2]);
        self::assertSame('Kitchen', $page->pageTitle, 'the parent is the page title of a new page');
        self::assertSame('', $page->parentPageTitle);
        self::assertSame(['Toasters'], $page->siblingPageTitles);
        self::assertSame([], $page->pageFields);
    }

    #[Test]
    public function readForImageReadsRecordAndPageTitleButNothingAround(): void
    {
        $finder = $this->createMock(RecordFinder::class);
        $finder->method('findRecord')->willReturnCallback(
            static fn (string $table, int $uid): ?array => ['tt_content:40' => self::CONTENT, 'pages:3' => self::PAGE][$table . ':' . $uid] ?? null,
        );
        $finder->expects(self::never())->method('findPageContent');
        $finder->expects(self::never())->method('findVisibleSubpages');

        $context = (new RecordContextReader($finder))->readForImage('tt_content', 'image', 40, 0, $this->editor());

        self::assertSame('Airfryer Test', $context->pageTitle);
        self::assertSame('Our test', $context->recordFields['header']['value'] ?? null);
        self::assertSame(['', [], '', []], [$context->pageContent, $context->pageFields, $context->parentPageTitle, $context->siblingPageTitles]);
    }

    #[Test]
    public function readForImageOfANewElementNamesOnlyItsPage(): void
    {
        $context = (new RecordContextReader($this->finder()))->readForImage('tt_content', 'image', 0, 3, $this->editor());

        self::assertSame(['Airfryer Test', '', [], []], [$context->pageTitle, $context->pageContent, $context->pageFields, $context->siblingPageTitles]);
    }

    #[Test]
    public function readForImageRefusesAnUnknownFieldATableAndAFieldTheEditorMayNotEdit(): void
    {
        $reader = new RecordContextReader($this->finder());
        $cases  = [
            'unknown field'   => fn (): RecordContext => $reader->readForImage('tt_content', 'nope', 40, 0, $this->editor()),
            'no table rights' => fn (): RecordContext => $reader->readForImage('tt_content', 'image', 40, 0, $this->editor(['tables_modify:tt_content'])),
            'excluded field'  => fn (): RecordContext => $reader->readForImage('tt_content', 'image', 40, 0, $this->editor(['non_exclude_fields:tt_content:image'])),
            'no record'       => fn (): RecordContext => $reader->readForImage('tt_content', 'image', 41, 0, $this->editor()),
        ];

        foreach ($cases as $name => $case) {
            try {
                $case();
                self::fail($name . ' was not refused');
            } catch (FieldSuggestionException $e) {
                self::assertSame($name === 'unknown field' ? 'fieldSuggestions.error.notEnabled' : 'fieldSuggestions.error.accessDenied', $e->getLabelKey(), $name);
            }
        }
    }

    #[Test]
    public function suggestionFieldsAreTheFieldsWithTheControlTheEditorMayEdit(): void
    {
        $reader = new RecordContextReader($this->finder());

        self::assertSame(['header' => 'Header', 'subheader' => 'Subheader'], $reader->suggestionFields('tt_content', $this->editor()));
        self::assertSame(['header' => 'Header'], $reader->suggestionFields('tt_content', $this->editor(['non_exclude_fields:tt_content:subheader'])));
        self::assertSame([], $reader->suggestionFields('unknown', $this->editor()));
    }
}
