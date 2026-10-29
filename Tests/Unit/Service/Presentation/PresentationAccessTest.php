<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\T3Cowriter\Tests\Unit\Service\Presentation;

use Netresearch\T3Cowriter\Service\FieldSuggestion\RecordFinder;
use Netresearch\T3Cowriter\Service\FieldSuggestion\Tca;
use Netresearch\T3Cowriter\Service\Presentation\Layout;
use Netresearch\T3Cowriter\Service\Presentation\PresentationAccess;
use Netresearch\T3Cowriter\Service\Presentation\PresentationElement;
use Netresearch\T3Cowriter\Service\Presentation\PresentationElementReader;
use Netresearch\T3Cowriter\Service\Presentation\PresentationException;
use Netresearch\T3Cowriter\Service\RecordEditAccess;
use Netresearch\T3Cowriter\Tests\Unit\EventListener\RegisterPresentationControlListenerTest;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;
use TYPO3\CMS\Core\Authentication\AccessCheckResult;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;
use TYPO3\CMS\Core\Type\Bitmask\Permission;

/**
 * Which layouts an editor may create in place of a text element, against a
 * TCA shaped like bootstrap_package's. The functional PresentationWriterTest
 * runs the same rules against the real TCA and DataHandler.
 */
#[CoversClass(PresentationAccess::class)]
#[CoversClass(PresentationElementReader::class)]
#[CoversClass(PresentationElement::class)]
#[CoversClass(PresentationException::class)]
#[CoversClass(RecordEditAccess::class)]
#[CoversClass(Layout::class)]
#[CoversClass(Tca::class)]
final class PresentationAccessTest extends TestCase
{
    private const ELEMENT = ['uid' => 10, 'pid' => 2, 'CType' => 'text', 'header' => 'Chairs', 'bodytext' => '<p>a</p>', 'sys_language_uid' => 0, 'colPos' => 1];

    private const PAGE = ['uid' => 2, 'pid' => 1, 'title' => 'Products'];

    protected function setUp(): void
    {
        $tca = RegisterPresentationControlListenerTest::tca(true, true);
        // @phpstan-ignore offsetAccess.nonOffsetAccessible
        $tca['tt_content']['ctrl'] = ['languageField' => 'sys_language_uid'];
        // @phpstan-ignore offsetAccess.nonOffsetAccessible
        $tca['tt_content']['columns']['CType']['config']['authMode'] = 'explicitAllow';
        // @phpstan-ignore offsetAccess.nonOffsetAccessible
        $tca['tt_content']['columns'] += [
            'header'           => ['config' => ['type' => 'input']],
            'colPos'           => ['exclude' => true, 'config' => ['type' => 'select']],
            'sys_language_uid' => ['exclude' => true, 'config' => ['type' => 'language']],
        ];
        foreach (['tab', 'accordion'] as $ctype) {
            // @phpstan-ignore offsetAccess.nonOffsetAccessible
            $tca['tx_bootstrappackage_' . $ctype . '_item']['ctrl'] = ['languageField' => 'sys_language_uid'];
            // @phpstan-ignore offsetAccess.nonOffsetAccessible
            $tca['tx_bootstrappackage_' . $ctype . '_item']['columns']['header']['exclude'] = true;
            // @phpstan-ignore offsetAccess.nonOffsetAccessible
            $tca['tx_bootstrappackage_' . $ctype . '_item']['columns']['sys_language_uid'] = ['exclude' => 1, 'config' => ['type' => 'language']];
        }

        $GLOBALS['TCA'] = $tca;
    }

    protected function tearDown(): void
    {
        unset($GLOBALS['TCA']);
        parent::tearDown();
    }

    /**
     * A non-admin editor. Every check passes unless it is named in $denied:
     * "<type>:<value>" of check(), "authMode:<CType>", "language:<id>",
     * "page:<perm>" or "record".
     *
     * @param list<string> $denied
     */
    private function editor(array $denied = []): BackendUserAuthentication&Stub
    {
        $user = $this->createStub(BackendUserAuthentication::class);
        $user->method('check')->willReturnCallback(
            static fn (string $type, string $value): bool => !in_array($type . ':' . $value, $denied, true),
        );
        $user->method('checkAuthMode')->willReturnCallback(
            static fn (string $table, string $field, string $value): bool => !in_array('authMode:' . $value, $denied, true),
        );
        $user->method('checkLanguageAccess')->willReturnCallback(
            static fn (int $language): bool => !in_array('language:' . $language, $denied, true),
        );
        $user->method('doesUserHaveAccess')->willReturnCallback(
            static fn (array $row, int $perms): bool => !in_array('page:' . $perms, $denied, true),
        );

        $recordAllowed = !in_array('record', $denied, true);
        if (method_exists(BackendUserAuthentication::class, 'checkRecordEditAccess')) {
            $user->method('checkRecordEditAccess')->willReturn(new AccessCheckResult($recordAllowed));
        } else {
            $user->method('recordEditAccessInternals')->willReturn($recordAllowed);
        }

        return $user;
    }

    /**
     * @param list<string>            $denied
     * @param array<string, mixed>    $element
     * @param array<array-key, mixed> $tsConfig
     *
     * @return list<Layout>
     */
    private function layouts(array $denied = [], array $element = self::ELEMENT, array $tsConfig = []): array
    {
        return (new PresentationAccess())->layoutsFor($this->editor($denied), $element, self::PAGE, $tsConfig);
    }

    #[Test]
    public function anEditorWithAllPermissionsGetsBothLayouts(): void
    {
        self::assertSame([Layout::Tabs, Layout::Accordion], $this->layouts());
    }

    /**
     * @return iterable<string, array{list<string>}>
     */
    public static function refusalsOfEverything(): iterable
    {
        yield 'content table not writable' => [['tables_modify:tt_content']];
        yield 'no content edit on the page' => [['page:' . Permission::CONTENT_EDIT]];
        yield 'record not editable' => [['record']];
        yield 'language not allowed' => [['language:0']];
        yield 'colPos excluded' => [['non_exclude_fields:tt_content:colPos']];
        yield 'language field excluded' => [['non_exclude_fields:tt_content:sys_language_uid']];
    }

    /**
     * @param list<string> $denied
     */
    #[Test]
    #[DataProvider('refusalsOfEverything')]
    public function aMissingGeneralPermissionRefusesBothLayouts(array $denied): void
    {
        self::assertSame([], $this->layouts($denied));
    }

    /**
     * @return iterable<string, array{list<string>}>
     */
    public static function refusalsOfTheTabs(): iterable
    {
        yield 'CType not allowed' => [['authMode:tab']];
        yield 'item table not writable' => [['tables_modify:tx_bootstrappackage_tab_item']];
        yield 'item header excluded' => [['non_exclude_fields:tx_bootstrappackage_tab_item:header']];
    }

    /**
     * @param list<string> $denied
     */
    #[Test]
    #[DataProvider('refusalsOfTheTabs')]
    public function aMissingPermissionForOneLayoutLeavesTheOther(array $denied): void
    {
        self::assertSame([Layout::Accordion], $this->layouts($denied));
    }

    #[Test]
    public function aCTypeWithoutAuthModeNeedsNoExplicitAllow(): void
    {
        // @phpstan-ignore offsetAccess.nonOffsetAccessible
        unset($GLOBALS['TCA']['tt_content']['columns']['CType']['config']['authMode']);

        self::assertSame([Layout::Tabs, Layout::Accordion], $this->layouts(['authMode:tab', 'authMode:accordion']));
    }

    #[Test]
    public function onlyTextElementsQualify(): void
    {
        self::assertSame([Layout::Tabs, Layout::Accordion], $this->layouts([], ['CType' => 'textpic'] + self::ELEMENT));
        self::assertSame([Layout::Tabs, Layout::Accordion], $this->layouts([], ['CType' => 'textmedia'] + self::ELEMENT));
        self::assertSame([], $this->layouts([], ['CType' => 'header'] + self::ELEMENT));
        self::assertSame([], $this->layouts([], ['CType' => 'tab'] + self::ELEMENT));
    }

    #[Test]
    public function anElementInAnotherLanguageNeedsTheItemLanguageField(): void
    {
        $element = ['sys_language_uid' => 1] + self::ELEMENT;

        self::assertSame([Layout::Tabs, Layout::Accordion], $this->layouts([], $element));
        self::assertSame([Layout::Accordion], $this->layouts(['non_exclude_fields:tx_bootstrappackage_tab_item:sys_language_uid'], $element));
        self::assertSame([Layout::Tabs, Layout::Accordion], $this->layouts(['non_exclude_fields:tx_bootstrappackage_tab_item:sys_language_uid']), 'not needed in the default language');
        self::assertSame([], $this->layouts(['language:1'], $element));
    }

    #[Test]
    public function anItemTableWithoutLanguageFieldCannotTakeATranslation(): void
    {
        // @phpstan-ignore offsetAccess.nonOffsetAccessible
        unset($GLOBALS['TCA']['tx_bootstrappackage_tab_item']['ctrl']);

        self::assertSame([Layout::Accordion], $this->layouts([], ['sys_language_uid' => 1] + self::ELEMENT));
        self::assertSame([Layout::Tabs, Layout::Accordion], $this->layouts());
    }

    /**
     * @return iterable<string, array{string, list<Layout>}>
     */
    public static function tsConfigs(): iterable
    {
        yield 'tab removed' => ['removeItems = tab', [Layout::Accordion]];
        yield 'both removed' => ['removeItems = text, tab,accordion', []];
        yield 'only accordion kept' => ['keepItems = text,accordion', [Layout::Accordion]];
        yield 'neither kept' => ['keepItems = text', []];
        yield 'unrelated removed' => ['removeItems = bullets', [Layout::Tabs, Layout::Accordion]];
    }

    /**
     * @param list<Layout> $expected
     */
    #[Test]
    #[DataProvider('tsConfigs')]
    public function pageTsConfigShapesTheChoice(string $setting, array $expected): void
    {
        [$key, $value] = array_map(trim(...), explode('=', $setting, 2));
        $tsConfig      = ['TCEFORM.' => ['tt_content.' => ['CType.' => [$key => $value]]]];

        self::assertSame($expected, $this->layouts([], self::ELEMENT, $tsConfig));
    }

    #[Test]
    public function malformedTsConfigIsIgnored(): void
    {
        self::assertSame([Layout::Tabs, Layout::Accordion], $this->layouts([], self::ELEMENT, ['TCEFORM.' => 'x']));
        self::assertSame([Layout::Tabs, Layout::Accordion], $this->layouts([], self::ELEMENT, ['TCEFORM.' => ['tt_content.' => 'x']]));
        self::assertSame([Layout::Tabs, Layout::Accordion], $this->layouts([], self::ELEMENT, ['TCEFORM.' => ['tt_content.' => ['CType.' => ['removeItems' => ['tab']]]]]));
    }

    #[Test]
    public function theTcaDecidesWhichLayoutsExist(): void
    {
        self::assertSame([Layout::Tabs, Layout::Accordion], PresentationAccess::layoutsInTca());
        self::assertSame([], PresentationAccess::layoutsInTca(RegisterPresentationControlListenerTest::tca(false)));
        self::assertSame([], PresentationAccess::layoutsInTca([]));
        self::assertSame(
            ['table' => 'tx_bootstrappackage_tab_item', 'parentField' => 'tt_content'],
            PresentationAccess::itemRelation(Layout::Tabs),
        );
    }

    /**
     * @return iterable<string, array{callable(array<string, mixed>): array<string, mixed>}>
     */
    public static function brokenRelations(): iterable
    {
        yield 'CType not selectable' => [static function (array $tca): array {
            // @phpstan-ignore offsetAccess.nonOffsetAccessible
            $tca['tt_content']['columns']['CType']['config']['items'] = [['label' => 'Text', 'value' => 'text'], ['label' => 'x', 'value' => 'accordion'], 'junk', ['value' => 7]];

            return $tca;
        }];
        yield 'item column not inline' => [static function (array $tca): array {
            // @phpstan-ignore offsetAccess.nonOffsetAccessible
            $tca['tt_content']['columns']['tx_bootstrappackage_tab_item']['config']['type'] = 'select';

            return $tca;
        }];
        yield 'no foreign table' => [static function (array $tca): array {
            // @phpstan-ignore offsetAccess.nonOffsetAccessible
            $tca['tt_content']['columns']['tx_bootstrappackage_tab_item']['config']['foreign_table'] = '';

            return $tca;
        }];
        yield 'no foreign field' => [static function (array $tca): array {
            // @phpstan-ignore offsetAccess.nonOffsetAccessible
            unset($tca['tt_content']['columns']['tx_bootstrappackage_tab_item']['config']['foreign_field']);

            return $tca;
        }];
        yield 'item table without header' => [static function (array $tca): array {
            // @phpstan-ignore offsetAccess.nonOffsetAccessible
            unset($tca['tx_bootstrappackage_tab_item']['columns']['header']);

            return $tca;
        }];
        yield 'item table without bodytext' => [static function (array $tca): array {
            // @phpstan-ignore offsetAccess.nonOffsetAccessible
            unset($tca['tx_bootstrappackage_tab_item']['columns']['bodytext']);

            return $tca;
        }];
    }

    /**
     * @param callable(array<string, mixed>): array<string, mixed> $break
     */
    #[Test]
    #[DataProvider('brokenRelations')]
    public function aLayoutWhoseTcaIsIncompleteIsNotOffered(callable $break): void
    {
        /** @var array<string, mixed> $tca */
        $tca            = $GLOBALS['TCA'];
        $GLOBALS['TCA'] = $break($tca);

        self::assertSame([Layout::Accordion], PresentationAccess::layoutsInTca());
        self::assertSame([Layout::Accordion], $this->layouts());
    }

    // ---- the reader ----------------------------------------------------------

    /**
     * @param array<string, array<string, mixed>> $records  keyed "table:uid"
     * @param array<array-key, mixed>             $tsConfig
     */
    private function reader(array $records, array $tsConfig = []): PresentationElementReader
    {
        $finder = $this->createStub(RecordFinder::class);
        $finder->method('findRecord')->willReturnCallback(
            static fn (string $table, int $uid, int $workspaceId): ?array => $records[$table . ':' . $uid . ':' . $workspaceId] ?? null,
        );

        return new class ($finder, $tsConfig) extends PresentationElementReader {
            /**
             * @param array<array-key, mixed> $tsConfig
             */
            public function __construct(RecordFinder $finder, private readonly array $tsConfig)
            {
                parent::__construct($finder);
            }

            protected function pageTsConfig(int $pageUid): array
            {
                return $pageUid === 2 ? $this->tsConfig : [];
            }
        };
    }

    #[Test]
    public function theReaderReturnsTheElementAsTheWorkspaceShowsIt(): void
    {
        $editor            = $this->editor();
        $editor->workspace = 3;

        $reader = $this->reader([
            'tt_content:10:3' => ['header' => 'Draft header', 'bodytext' => '<p>draft</p>', 'sys_language_uid' => 2] + self::ELEMENT,
            'pages:2:3'       => self::PAGE,
        ]);

        $element = $reader->read(10, $editor);

        self::assertEquals(
            new PresentationElement(10, 2, 2, 1, 'Draft header', '<p>draft</p>', [Layout::Tabs, Layout::Accordion]),
            $element,
        );
        self::assertSame(hash('sha256', '<p>draft</p>'), $element->fingerprint());
        self::assertTrue($element->allows(Layout::Tabs));
    }

    #[Test]
    public function theReaderAppliesThePagesTsConfig(): void
    {
        $reader = $this->reader(
            ['tt_content:10:0' => self::ELEMENT, 'pages:2:0' => self::PAGE],
            ['TCEFORM.' => ['tt_content.' => ['CType.' => ['removeItems' => 'tab']]]],
        );

        $element = $reader->read(10, $this->editor());

        self::assertSame([Layout::Accordion], $element->layouts);
        self::assertFalse($element->allows(Layout::Tabs));
    }

    /**
     * @return iterable<string, array{int, array<string, array<string, mixed>>, list<string>}>
     */
    public static function unreadableElements(): iterable
    {
        yield 'unknown element' => [10, ['pages:2:0' => self::PAGE], []];
        yield 'uid 0' => [0, ['tt_content:0:0' => self::ELEMENT, 'pages:2:0' => self::PAGE], []];
        yield 'page not found' => [10, ['tt_content:10:0' => self::ELEMENT], []];
        yield 'element on the root level' => [10, ['tt_content:10:0' => ['pid' => 0] + self::ELEMENT, 'pages:0:0' => self::PAGE], []];
        yield 'no layout open to the editor' => [10, ['tt_content:10:0' => self::ELEMENT, 'pages:2:0' => self::PAGE], ['authMode:tab', 'authMode:accordion']];
    }

    /**
     * @param array<string, array<string, mixed>> $records
     * @param list<string>                        $denied
     */
    #[Test]
    #[DataProvider('unreadableElements')]
    public function theReaderRefusesWithOneAnswer(int $uid, array $records, array $denied): void
    {
        $this->expectExceptionObject(PresentationException::notAvailable());

        $this->reader($records)->read($uid, $this->editor($denied));
    }

    #[Test]
    public function aNegativeWorkspaceIdReadsLive(): void
    {
        $editor            = $this->editor();
        $editor->workspace = -1;

        $element = $this->reader(['tt_content:10:0' => self::ELEMENT, 'pages:2:0' => self::PAGE])->read(10, $editor);

        self::assertSame(10, $element->uid);
    }

    #[Test]
    public function anExcludedCTypeFieldNeedsItsPermission(): void
    {
        // @phpstan-ignore offsetAccess.nonOffsetAccessible
        $GLOBALS['TCA']['tt_content']['columns']['CType']['exclude'] = true;

        self::assertSame([], $this->layouts(['non_exclude_fields:tt_content:CType']));
        self::assertSame([Layout::Tabs, Layout::Accordion], $this->layouts());
    }

    #[Test]
    public function aFieldThatIsNotExcludedNeedsNoPermission(): void
    {
        self::assertSame([Layout::Tabs, Layout::Accordion], $this->layouts(['non_exclude_fields:tt_content:header', 'non_exclude_fields:tx_bootstrappackage_tab_item:bodytext']));
    }

    #[Test]
    public function anElementWithoutLanguageIsInTheDefaultLanguage(): void
    {
        $element = self::ELEMENT;
        unset($element['sys_language_uid']);

        self::assertSame([Layout::Tabs, Layout::Accordion], $this->layouts(['language:1', 'language:-1', 'non_exclude_fields:tx_bootstrappackage_tab_item:sys_language_uid'], $element));
    }

    #[Test]
    public function anEmptyParentFieldIsNoRelation(): void
    {
        // @phpstan-ignore offsetAccess.nonOffsetAccessible
        $GLOBALS['TCA']['tt_content']['columns']['tx_bootstrappackage_tab_item']['config']['foreign_field'] = '';

        self::assertNull(PresentationAccess::itemRelation(Layout::Tabs));
    }

    #[Test]
    public function theReaderTakesNumbersAndTextFromTheRowWhateverTheirType(): void
    {
        $element = $this->reader([
            'tt_content:10:0' => ['pid' => '2', 'sys_language_uid' => '0', 'colPos' => '3', 'header' => 2026, 'bodytext' => null] + self::ELEMENT,
            'pages:2:0'       => self::PAGE,
        ])->read(10, $this->editor());

        self::assertEquals(new PresentationElement(10, 2, 0, 3, '2026', '', [Layout::Tabs, Layout::Accordion]), $element);

        $row = self::ELEMENT;
        unset($row['colPos'], $row['sys_language_uid'], $row['header']);
        $element = $this->reader(['tt_content:10:0' => $row, 'pages:2:0' => self::PAGE])->read(10, $this->editor());

        self::assertSame([0, 0, ''], [$element->languageId, $element->columnPosition, $element->header]);
    }

    #[Test]
    public function aRefusalCarriesItsLabelAsMessageAndItsStatusAsCode(): void
    {
        $refusal = PresentationException::changed();

        self::assertSame('presentation.error.changed', $refusal->getMessage());
        self::assertSame(409, $refusal->getCode());
        self::assertSame(409, $refusal->getHttpStatus());
    }
}
