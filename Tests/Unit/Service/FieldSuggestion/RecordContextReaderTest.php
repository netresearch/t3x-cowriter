<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\T3Cowriter\Tests\Unit\Service\FieldSuggestion;

use Netresearch\T3Cowriter\Domain\DTO\FieldSuggestionRequest;
use Netresearch\T3Cowriter\EventListener\RegisterFieldSuggestionControlsListener;
use Netresearch\T3Cowriter\Service\FieldSuggestion\FieldSuggestionException;
use Netresearch\T3Cowriter\Service\FieldSuggestion\RecordContext;
use Netresearch\T3Cowriter\Service\FieldSuggestion\RecordContextReader;
use Netresearch\T3Cowriter\Service\FieldSuggestion\RecordFinder;
use Netresearch\T3Cowriter\Service\FieldSuggestion\Tca;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;
use TYPO3\CMS\Core\Authentication\AccessCheckResult;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;
use TYPO3\CMS\Core\Type\Bitmask\Permission;

#[CoversClass(RecordContextReader::class)]
#[CoversClass(RecordContext::class)]
#[CoversClass(FieldSuggestionException::class)]
#[CoversClass(FieldSuggestionRequest::class)]
#[CoversClass(Tca::class)]
final class RecordContextReaderTest extends TestCase
{
    private const PAGE = [
        'uid'  => 12, 'pid' => 3, 'title' => 'Office chairs', 'seo_title' => 'Stored SEO title',
        'slug' => '/products/office-chairs', 'sys_language_uid' => 0,
    ];

    private const PARENT_PAGE = ['uid' => 3, 'pid' => 1, 'title' => 'Products'];

    private const CONTENT = ['uid' => 40, 'pid' => 12, 'header' => 'Old header', 'sys_language_uid' => 0];

    protected function setUp(): void
    {
        $control        = [RegisterFieldSuggestionControlsListener::CONTROL_NAME => ['renderType' => RegisterFieldSuggestionControlsListener::NODE_NAME]];
        $GLOBALS['TCA'] = [
            'pages' => [
                'ctrl'    => ['languageField' => 'sys_language_uid', 'transOrigPointerField' => 'l10n_parent'],
                'columns' => [
                    'seo_title' => ['exclude' => true, 'config' => ['type' => 'input', 'fieldControl' => $control]],
                    'slug'      => ['config' => ['type' => 'slug', 'fieldControl' => $control]],
                    'title'     => ['config' => ['type' => 'input']],
                    'abstract'  => ['config' => ['type' => 'text', 'fieldControl' => [
                        RegisterFieldSuggestionControlsListener::CONTROL_NAME => ['disabled' => true],
                    ]]],
                    'subtitle' => ['config' => ['type' => 'input', 'fieldControl' => [
                        RegisterFieldSuggestionControlsListener::CONTROL_NAME => ['disabled' => 1],
                    ]]],
                    'keywords' => ['exclude' => 1, 'config' => ['type' => 'text', 'fieldControl' => $control]],
                ],
            ],
            'tt_content' => [
                'ctrl'    => ['languageField' => 'sys_language_uid'],
                'columns' => [
                    'header' => ['config' => ['type' => 'input', 'fieldControl' => $control]],
                ],
            ],
        ];
    }

    /**
     * @param array<string, array<string, mixed>> $records keyed "table:uid"
     * @param list<array<string, mixed>>          $content
     */
    private function finder(array $records = [], array $content = [], bool $mock = false): RecordFinder&Stub
    {
        $finder = $mock ? $this->createMock(RecordFinder::class) : $this->createStub(RecordFinder::class);
        $finder->method('findRecord')->willReturnCallback(
            static fn (string $table, int $uid, int $workspaceId): ?array => $records[$table . ':' . $uid] ?? null,
        );
        $finder->method('findPageContent')->willReturn($content);

        return $finder;
    }

    /**
     * A non-admin editor. Every check passes unless it is named in $denied:
     * "<type>:<value>" of a check() call ("non_exclude_fields:pages:seo_title"),
     * "page:<perm>" for doesUserHaveAccess(), or "record" for the record check.
     *
     * @param list<string> $denied
     */
    private function editor(array $denied = [], bool $admin = false): BackendUserAuthentication&Stub
    {
        $user = $this->createStub(BackendUserAuthentication::class);
        $user->method('isAdmin')->willReturn($admin);
        $user->method('check')->willReturnCallback(
            static fn (string $type, string $value): bool => !in_array($type . ':' . $value, $denied, true),
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
     * @param array<string, mixed> $body
     */
    private static function request(array $body): FieldSuggestionRequest
    {
        return FieldSuggestionRequest::fromArray($body + ['table' => 'pages', 'field' => 'seo_title', 'uid' => 12]);
    }

    private function assertRefused(string $expectedMessage, int $status, callable $read): void
    {
        try {
            $read();
            self::fail('Expected a FieldSuggestionException');
        } catch (FieldSuggestionException $e) {
            self::assertSame($expectedMessage, $e->getMessage());
            self::assertSame($status, $e->getHttpStatus());
        }
    }

    #[Test]
    public function readsTheContextOfAnExistingPage(): void
    {
        $finder = $this->finder(['pages:12' => self::PAGE], [
            ['header' => 'Ergonomic <b>chairs</b>', 'bodytext' => '<p>Adjustable&nbsp;seat   height.</p>'],
            ['header' => '', 'bodytext' => ''],
            ['header' => 'Warranty', 'bodytext' => null],
        ], true);
        self::assertInstanceOf(MockObject::class, $finder);
        $finder->expects(self::once())->method('findPageContent')->with(12, 0, 0);

        $context = (new RecordContextReader($finder))->read(self::request([]), $this->editor());

        self::assertSame('pages', $context->table);
        self::assertSame('seo_title', $context->field);
        self::assertSame('Stored SEO title', $context->storedValue);
        self::assertSame('Office chairs', $context->pageTitle);
        // Tags stripped, entities decoded, every whitespace run (the decoded &nbsp; too) collapsed.
        self::assertSame("Ergonomic chairs\nAdjustable seat height.\n\nWarranty", $context->pageContent);
        self::assertSame(3, $context->slugPid);
        self::assertSame(self::PAGE, $context->record);
    }

    #[Test]
    public function editingAPageRequiresPageEditOnThePageItself(): void
    {
        $user = $this->editor(['page:' . Permission::PAGE_EDIT]);

        $this->assertRefused(
            'You are not allowed to edit this field.',
            403,
            fn () => (new RecordContextReader($this->finder(['pages:12' => self::PAGE])))->read(self::request([]), $user),
        );
    }

    #[Test]
    public function tableWithoutModifyPermissionIsRefused(): void
    {
        $this->assertRefused(
            'You are not allowed to edit this field.',
            403,
            fn () => (new RecordContextReader($this->finder(['pages:12' => self::PAGE])))->read(self::request([]), $this->editor(['tables_modify:pages'])),
        );
    }

    #[Test]
    public function excludeFieldWithoutPermissionIsRefused(): void
    {
        $this->assertRefused(
            'You are not allowed to edit this field.',
            403,
            fn () => (new RecordContextReader($this->finder(['pages:12' => self::PAGE])))->read(self::request([]), $this->editor(['non_exclude_fields:pages:seo_title'])),
        );
    }

    #[Test]
    public function excludeFlagWrittenAsIntegerIsEnforced(): void
    {
        $this->assertRefused(
            'You are not allowed to edit this field.',
            403,
            fn () => (new RecordContextReader($this->finder(['pages:12' => self::PAGE])))
                ->read(self::request(['field' => 'keywords']), $this->editor(['non_exclude_fields:pages:keywords'])),
        );
    }

    #[Test]
    public function pageTranslationUsesPermissionsAndContentOfTheDefaultLanguagePage(): void
    {
        $translation = ['uid' => 57, 'pid' => 3, 'l10n_parent' => 12, 'sys_language_uid' => 2, 'title' => 'Bürostühle', 'seo_title' => 'Alt'];
        $finder      = $this->finder(['pages:57' => $translation, 'pages:12' => self::PAGE], [], true);
        self::assertInstanceOf(MockObject::class, $finder);
        $finder->expects(self::once())->method('findPageContent')->with(12, 2, 0);

        $user = $this->createMock(BackendUserAuthentication::class);
        $user->method('check')->willReturn(true);
        $user->expects(self::once())->method('doesUserHaveAccess')->with(self::PAGE, Permission::PAGE_EDIT)->willReturn(true);
        if (method_exists(BackendUserAuthentication::class, 'checkRecordEditAccess')) {
            $user->method('checkRecordEditAccess')->willReturn(new AccessCheckResult(true));
        } else {
            $user->method('recordEditAccessInternals')->willReturn(true);
        }

        $context = (new RecordContextReader($finder))->read(self::request(['uid' => 57]), $user);

        self::assertSame('Bürostühle', $context->pageTitle);
        self::assertSame('Alt', $context->storedValue);
        self::assertSame($translation, $context->record);
    }

    #[Test]
    public function pageTranslationWithoutOriginalFallsBackToItself(): void
    {
        $translation = ['uid' => 57, 'pid' => 3, 'l10n_parent' => 99, 'sys_language_uid' => 2, 'title' => 'Orphan'];
        $finder      = $this->finder(['pages:57' => $translation], [], true);
        self::assertInstanceOf(MockObject::class, $finder);
        $finder->expects(self::once())->method('findPageContent')->with(57, 2, 0);

        self::assertSame('Orphan', (new RecordContextReader($finder))->read(self::request(['uid' => 57]), $this->editor())->pageTitle);
    }

    #[Test]
    public function nonExcludeFieldDoesNotNeedTheExcludePermission(): void
    {
        $context = (new RecordContextReader($this->finder(['pages:12' => self::PAGE])))
            ->read(self::request(['field' => 'slug']), $this->editor(['non_exclude_fields:pages:slug']));

        self::assertSame('/products/office-chairs', $context->storedValue);
    }

    #[Test]
    public function recordLevelRefusalIsRespected(): void
    {
        $this->assertRefused(
            'You are not allowed to edit this field.',
            403,
            fn () => (new RecordContextReader($this->finder(['pages:12' => self::PAGE])))->read(self::request([]), $this->editor(['record'])),
        );
    }

    #[Test]
    public function fieldWithoutTheControlIsNeverServed(): void
    {
        $reader = new RecordContextReader($this->finder(['pages:12' => self::PAGE]));

        $this->assertRefused('Suggestions are not enabled for this field.', 400, fn () => $reader->read(self::request(['field' => 'title']), $this->editor()));
        $this->assertRefused('Suggestions are not enabled for this field.', 400, fn () => $reader->read(self::request(['field' => 'abstract']), $this->editor()));
        $this->assertRefused('Suggestions are not enabled for this field.', 400, fn () => $reader->read(self::request(['field' => 'subtitle']), $this->editor()));
        $this->assertRefused('Suggestions are not enabled for this field.', 400, fn () => $reader->read(self::request(['table' => 'be_users', 'field' => 'password']), $this->editor(admin: true)));
    }

    #[Test]
    public function missingRecordIsRefusedLikeADeniedOne(): void
    {
        $this->assertRefused(
            'You are not allowed to edit this field.',
            403,
            fn () => (new RecordContextReader($this->finder()))->read(self::request([]), $this->editor()),
        );
    }

    #[Test]
    public function contentElementNeedsContentEditOnItsPage(): void
    {
        $finder = $this->finder(['tt_content:40' => self::CONTENT, 'pages:12' => self::PAGE]);
        $body   = ['table' => 'tt_content', 'field' => 'header', 'uid' => 40];

        $context = (new RecordContextReader($finder))->read(self::request($body), $this->editor());
        self::assertSame('Old header', $context->storedValue);
        self::assertSame('Office chairs', $context->pageTitle);
        self::assertSame(12, $context->slugPid);

        $this->assertRefused(
            'You are not allowed to edit this field.',
            403,
            fn () => (new RecordContextReader($finder))->read(self::request($body), $this->editor(['page:' . Permission::CONTENT_EDIT])),
        );
    }

    #[Test]
    public function contentElementOnAMissingPageIsRefused(): void
    {
        $finder = $this->finder(['tt_content:40' => self::CONTENT]);

        $this->assertRefused(
            'You are not allowed to edit this field.',
            403,
            fn () => (new RecordContextReader($finder))->read(self::request(['table' => 'tt_content', 'field' => 'header', 'uid' => 40]), $this->editor()),
        );
    }

    #[Test]
    public function rootLevelRecordsAreForAdminsOnly(): void
    {
        $finder = $this->finder(['tt_content:40' => ['pid' => 0] + self::CONTENT]);
        $body   = ['table' => 'tt_content', 'field' => 'header', 'uid' => 40];

        $this->assertRefused('You are not allowed to edit this field.', 403, fn () => (new RecordContextReader($finder))->read(self::request($body), $this->editor()));

        $context = (new RecordContextReader($finder))->read(self::request($body), $this->editor(admin: true));
        self::assertSame('', $context->pageTitle);
        self::assertSame('', $context->pageContent);
    }

    #[Test]
    public function contentOfTheRecordLanguageIsRead(): void
    {
        $finder = $this->finder(['pages:12' => ['sys_language_uid' => 2] + self::PAGE], [], true);
        self::assertInstanceOf(MockObject::class, $finder);
        $finder->expects(self::once())->method('findPageContent')->with(12, 2, 0);

        (new RecordContextReader($finder))->read(self::request([]), $this->editor());
    }

    #[Test]
    public function pageContentIsLeftOutWithoutReadAccessToContentElements(): void
    {
        $finder = $this->finder(['pages:12' => self::PAGE], [['header' => 'Secret', 'bodytext' => '']], true);
        self::assertInstanceOf(MockObject::class, $finder);
        $finder->expects(self::never())->method('findPageContent');

        $context = (new RecordContextReader($finder))->read(self::request([]), $this->editor(['tables_select:tt_content']));

        self::assertSame('', $context->pageContent);
    }

    #[Test]
    public function recordWithoutLanguageFieldReadsDefaultLanguageContent(): void
    {
        unset($GLOBALS['TCA']['pages']['ctrl']['languageField']);
        $finder = $this->finder(['pages:12' => ['sys_language_uid' => 2] + self::PAGE], [], true);
        self::assertInstanceOf(MockObject::class, $finder);
        $finder->expects(self::once())->method('findPageContent')->with(12, 0, 0);

        (new RecordContextReader($finder))->read(self::request([]), $this->editor());
    }

    #[Test]
    public function recordForAllLanguagesReadsDefaultLanguageContent(): void
    {
        $finder = $this->finder(['pages:12' => ['sys_language_uid' => -1] + self::PAGE], [], true);
        self::assertInstanceOf(MockObject::class, $finder);
        $finder->expects(self::once())->method('findPageContent')->with(12, 0, 0);

        (new RecordContextReader($finder))->read(self::request([]), $this->editor());
    }

    #[Test]
    public function newPageReadsTheDefaultLanguageContentOfItsParent(): void
    {
        $finder = $this->finder(['pages:3' => self::PARENT_PAGE], [], true);
        self::assertInstanceOf(MockObject::class, $finder);
        $finder->expects(self::once())->method('findPageContent')->with(3, 0, 0);

        (new RecordContextReader($finder))->read(self::request(['uid' => 'NEW1', 'pid' => 3]), $this->editor());
    }

    #[Test]
    public function recordWithoutPidIsTreatedAsRootLevel(): void
    {
        $record = self::CONTENT;
        unset($record['pid']);
        $finder = $this->finder(['tt_content:40' => $record]);

        $this->assertRefused(
            'You are not allowed to edit this field.',
            403,
            fn () => (new RecordContextReader($finder))->read(self::request(['table' => 'tt_content', 'field' => 'header', 'uid' => 40]), $this->editor()),
        );
    }

    #[Test]
    public function multibyteContentIsCutByCharacters(): void
    {
        $finder = $this->finder(['pages:12' => self::PAGE], [['header' => str_repeat('ä', 7000), 'bodytext' => '']]);

        $context = (new RecordContextReader($finder))->read(self::request([]), $this->editor());

        self::assertSame(str_repeat('ä', RecordContextReader::MAX_PAGE_CONTENT_LENGTH), $context->pageContent);
    }

    #[Test]
    public function singleQuoteEntitiesAreDecoded(): void
    {
        $finder = $this->finder(['pages:12' => self::PAGE], [['header' => '', 'bodytext' => '<p>Don&apos;t miss &quot;it&quot;</p>  ']]);

        $context = (new RecordContextReader($finder))->read(self::request([]), $this->editor());

        self::assertSame('Don\'t miss "it"', $context->pageContent);
    }

    #[Test]
    public function pageContentIsCutToTheMaximumLength(): void
    {
        $finder = $this->finder(['pages:12' => self::PAGE], [['header' => str_repeat('a', 7000), 'bodytext' => '']]);

        $context = (new RecordContextReader($finder))->read(self::request([]), $this->editor());

        self::assertSame(RecordContextReader::MAX_PAGE_CONTENT_LENGTH, mb_strlen($context->pageContent));
    }

    #[Test]
    public function newPageNeedsPageNewOnTheParentPage(): void
    {
        $finder = $this->finder(['pages:3' => self::PARENT_PAGE]);
        $body   = ['uid' => 'NEW1', 'pid' => 3];

        $context = (new RecordContextReader($finder))->read(self::request($body), $this->editor());
        self::assertSame('Products', $context->pageTitle);
        self::assertSame('', $context->storedValue);
        self::assertSame(['pid' => 3], $context->record);
        self::assertSame(3, $context->slugPid);

        $this->assertRefused(
            'You are not allowed to edit this field.',
            403,
            fn () => (new RecordContextReader($finder))->read(self::request($body), $this->editor(['page:' . Permission::PAGE_NEW])),
        );
    }

    #[Test]
    public function newContentElementNeedsContentEditOnTheTargetPage(): void
    {
        $finder = $this->finder(['pages:12' => self::PAGE]);
        $body   = ['table' => 'tt_content', 'field' => 'header', 'uid' => 'NEW1', 'pid' => 12];

        self::assertSame('Office chairs', (new RecordContextReader($finder))->read(self::request($body), $this->editor(['page:' . Permission::PAGE_NEW]))->pageTitle);

        $this->assertRefused(
            'You are not allowed to edit this field.',
            403,
            fn () => (new RecordContextReader($finder))->read(self::request($body), $this->editor(['page:' . Permission::CONTENT_EDIT])),
        );
    }

    #[Test]
    public function newRecordOnAMissingPageIsRefusedLikeADeniedOne(): void
    {
        $this->assertRefused(
            'You are not allowed to edit this field.',
            403,
            fn () => (new RecordContextReader($this->finder()))->read(self::request(['uid' => 'NEW1', 'pid' => 99]), $this->editor()),
        );
    }

    #[Test]
    public function newRecordOnTheRootLevelIsForAdminsOnly(): void
    {
        $reader = new RecordContextReader($this->finder());
        $body   = ['uid' => 'NEW1', 'pid' => 0];

        $this->assertRefused('You are not allowed to edit this field.', 403, fn () => $reader->read(self::request($body), $this->editor()));

        $context = $reader->read(self::request($body), $this->editor(admin: true));
        self::assertSame(0, $context->slugPid);
        self::assertTrue($context->isSiteRoot());
    }

    #[Test]
    public function theUsersWorkspaceIsPassedToEveryRead(): void
    {
        $finder = $this->createMock(RecordFinder::class);
        $finder->expects(self::exactly(2))->method('findRecord')->willReturnCallback(
            static function (string $table, int $uid, int $workspaceId): array {
                self::assertSame(4, $workspaceId);

                return $table === 'tt_content' && $uid === 40 ? self::CONTENT : self::PAGE;
            },
        );
        $finder->expects(self::once())->method('findPageContent')->with(12, 0, 4)->willReturn([]);
        $user            = $this->editor();
        $user->workspace = 4;

        (new RecordContextReader($finder))->read(self::request(['table' => 'tt_content', 'field' => 'header', 'uid' => 40]), $user);
    }

    #[Test]
    public function uninitialisedWorkspaceCountsAsLive(): void
    {
        $finder = $this->finder(['pages:12' => self::PAGE], [], true);
        self::assertInstanceOf(MockObject::class, $finder);
        $finder->expects(self::once())->method('findPageContent')->with(12, 0, 0);
        $user            = $this->editor();
        $user->workspace = -99;

        (new RecordContextReader($finder))->read(self::request([]), $user);
    }

    #[Test]
    public function configuredCountIsTheUpperBound(): void
    {
        $GLOBALS['TCA']['pages']['columns']['seo_title']['config']['fieldControl'][RegisterFieldSuggestionControlsListener::CONTROL_NAME]['options'] = ['count' => 2];
        $reader                                                                                                                                      = new RecordContextReader($this->finder(['pages:12' => self::PAGE, 'pages:3' => self::PARENT_PAGE]));

        self::assertSame(2, $reader->read(self::request([]), $this->editor())->maxCount);
        self::assertSame(2, $reader->read(self::request(['uid' => 'NEW1', 'pid' => 3]), $this->editor())->maxCount);

        $GLOBALS['TCA']['pages']['columns']['seo_title']['config']['fieldControl'][RegisterFieldSuggestionControlsListener::CONTROL_NAME]['options'] = ['count' => 9];
        self::assertSame(5, $reader->read(self::request([]), $this->editor())->maxCount);

        $GLOBALS['TCA']['pages']['columns']['seo_title']['config']['fieldControl'][RegisterFieldSuggestionControlsListener::CONTROL_NAME]['options'] = ['count' => 0];
        self::assertSame(1, $reader->read(self::request([]), $this->editor())->maxCount);
    }

    #[Test]
    public function withoutConfiguredCountTheRequestLimitApplies(): void
    {
        $reader = new RecordContextReader($this->finder(['pages:12' => self::PAGE]));

        self::assertSame(5, $reader->read(self::request([]), $this->editor())->maxCount);
    }

    #[Test]
    public function siteRootIsRecognised(): void
    {
        self::assertTrue((new RecordContext('pages', 'slug', ['is_siteroot' => 1], '', '', '', 5))->isSiteRoot());
        self::assertTrue((new RecordContext('pages', 'slug', ['is_siteroot' => '1'], '', '', '', 5))->isSiteRoot());
        self::assertTrue((new RecordContext('pages', 'slug', ['is_siteroot' => true], '', '', '', 5))->isSiteRoot());
        self::assertFalse((new RecordContext('pages', 'slug', ['is_siteroot' => 0], '', '', '', 5))->isSiteRoot());
        self::assertFalse((new RecordContext('pages', 'slug', [], '', '', '', 5))->isSiteRoot());
        self::assertFalse((new RecordContext('tt_content', 'header', [], '', '', '', 0))->isSiteRoot());
    }
}
