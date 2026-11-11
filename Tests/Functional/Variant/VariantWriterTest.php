<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\T3Cowriter\Tests\Functional\Variant;

use Netresearch\T3Cowriter\Service\FieldSuggestion\RecordFinder;
use Netresearch\T3Cowriter\Service\FieldSuggestion\Tca;
use Netresearch\T3Cowriter\Service\Variant\AudienceContexts;
use Netresearch\T3Cowriter\Service\Variant\VariantElement;
use Netresearch\T3Cowriter\Service\Variant\VariantElementReader;
use Netresearch\T3Cowriter\Service\Variant\VariantException;
use Netresearch\T3Cowriter\Service\Variant\VariantWriter;
use Netresearch\T3Cowriter\Tests\Support\RecordingLogger;
use Netresearch\T3Cowriter\Tests\Support\TranslateOnSaveHook;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;
use TYPO3\CMS\Core\Configuration\SiteWriter;
use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Localization\LanguageServiceFactory;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;

/**
 * The audience variant written through DataHandler as the editor, with
 * EXT:contexts installed: the copy, its context, showing it and hiding the
 * original in that context, and the three ways the contexts hook would drop
 * or lose a setting without an error (an integer value, a partial map, a
 * missing field right).
 *
 * Fixture: elements 10 and 12 on page 2, 12 has the contexts 6 (enabled)
 * and 7 (disabled); 13 has the German translation 14; 15 and 16 are free
 * translations. Page 1 is the root of a site with English and German; page
 * 4 is the German translation of page 2. Contexts 5 and 6 are assignable, 7 is hidden in the backend,
 * 8 is disabled.
 */
#[CoversClass(VariantElementReader::class)]
#[CoversClass(VariantWriter::class)]
#[CoversClass(VariantElement::class)]
#[CoversClass(AudienceContexts::class)]
#[CoversClass(VariantException::class)]
#[CoversClass(Tca::class)]
final class VariantWriterTest extends FunctionalTestCase
{
    private const HEADER = 'Chairs for students';

    protected array $coreExtensionsToLoad = ['rte_ckeditor', 'workspaces'];

    protected array $testExtensionsToLoad = [
        'netresearch/nr-vault',
        'netresearch/nr-llm',
        'netresearch/contexts',
        'netresearch/t3-cowriter',
    ];

    protected function setUp(): void
    {
        parent::setUp();
        $this->importCSVDataSet(__DIR__ . '/Fixtures/elements.csv');

        // A site with German and a German page 2: DataHandler copies a
        // translation along only onto a page of a site that has its language
        // and a translation of the page in it.
        GeneralUtility::makeInstance(ConnectionPool::class)->getConnectionForTable('pages')->insert('pages', [
            'uid'              => 4,
            'pid'              => 1,
            'title'            => 'Produkte',
            'slug'             => '/produkte',
            'doktype'          => 1,
            'sys_language_uid' => 1,
            'l10n_parent'      => 2,
            'perms_userid'     => 1,
            'perms_groupid'    => 1,
            'perms_user'       => 31,
            'perms_group'      => 31,
            'perms_everybody'  => 31,
        ]);
        $this->get(SiteWriter::class)->write('main', [
            'rootPageId' => 1,
            'base'       => 'https://example.com/',
            'languages'  => [
                ['languageId' => 0, 'title' => 'English', 'locale' => 'en_US.UTF-8', 'base' => '/'],
                ['languageId' => 1, 'title' => 'Deutsch', 'locale' => 'de_DE.UTF-8', 'base' => '/de/'],
            ],
        ]);
    }

    private function finder(): RecordFinder
    {
        return new RecordFinder(GeneralUtility::makeInstance(ConnectionPool::class));
    }

    private function contexts(): AudienceContexts
    {
        return new AudienceContexts(GeneralUtility::makeInstance(ConnectionPool::class));
    }

    private function reader(): VariantElementReader
    {
        return new VariantElementReader($this->finder());
    }

    private function writer(?RecordingLogger $logger = null): VariantWriter
    {
        return new VariantWriter($this->finder(), $this->contexts(), $logger ?? new RecordingLogger());
    }

    private function editor(int $uid, int $workspace = 0): BackendUserAuthentication
    {
        $backendUser = $this->setUpBackendUser($uid);
        // A copy prefixes the header in the editor's language, as the clipboard does.
        $GLOBALS['LANG'] = $this->get(LanguageServiceFactory::class)->createFromUserPreferences($backendUser);
        if ($workspace > 0) {
            $backendUser->setWorkspace($workspace);
            self::assertSame($workspace, $backendUser->workspace, 'the editor works in the workspace');
        }

        return $backendUser;
    }

    private function create(int $uid, int $context, BackendUserAuthentication $backendUser): int
    {
        $logger  = new RecordingLogger();
        $element = $this->reader()->read($uid, $backendUser);

        try {
            return $this->writer($logger)->create($element, self::HEADER, '<p>Cheap and sturdy.</p>', $context, $backendUser);
        } catch (VariantException $e) {
            // DataHandler's own words, when it refused.
            self::fail($e->getMessage() . ' ' . json_encode($logger->records, JSON_THROW_ON_ERROR));
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function row(int $uid): array
    {
        $queryBuilder = GeneralUtility::makeInstance(ConnectionPool::class)->getQueryBuilderForTable('tt_content');
        $queryBuilder->getRestrictions()->removeAll();
        $row = $queryBuilder->select('*')->from('tt_content')
            ->where($queryBuilder->expr()->eq('uid', $queryBuilder->createNamedParameter($uid, Connection::PARAM_INT)))
            ->executeQuery()
            ->fetchAssociative();
        self::assertIsArray($row, 'tt_content ' . $uid . ' exists');

        return $row;
    }

    private function countContent(): int
    {
        $queryBuilder = GeneralUtility::makeInstance(ConnectionPool::class)->getQueryBuilderForTable('tt_content');
        $queryBuilder->getRestrictions()->removeAll();

        return (int) $queryBuilder->count('uid')->from('tt_content')
            ->where($queryBuilder->expr()->eq('deleted', 0))
            ->executeQuery()
            ->fetchOne();
    }

    #[Test]
    public function theVariantIsAHiddenCopyAfterTheOriginalVisibleOnlyInItsContext(): void
    {
        $before = $this->row(10);
        $uid    = $this->create(10, 5, $this->editor(2));
        $row    = $this->row($uid);

        self::assertSame(2, (int) $row['pid']);
        self::assertSame('text', $row['CType']);
        self::assertSame(1, (int) $row['hidden']);
        self::assertSame(self::HEADER, $row['header']);
        self::assertStringContainsString('Cheap and sturdy.', (string) $row['bodytext']);
        self::assertGreaterThan((int) $before['sorting'], (int) $row['sorting'], 'placed after the original');
        self::assertLessThan((int) $this->row(11)['sorting'], (int) $row['sorting'], 'placed before the next element');

        // The frontend restriction of contexts reads these two columns only.
        self::assertSame('5', (string) $row['tx_contexts_enable']);
        self::assertSame('', (string) $row['tx_contexts_disable']);

        self::assertSame($before, $this->row(10), 'the original is not touched');
    }

    #[Test]
    public function theVariantDropsTheContextSettingsItsCopyTookOver(): void
    {
        $uid = $this->create(12, 5, $this->editor(2));
        $row = $this->row($uid);

        self::assertSame('5', (string) $row['tx_contexts_enable']);
        self::assertSame('', (string) $row['tx_contexts_disable']);
    }

    #[Test]
    public function withoutAContextTheVariantKeepsTheSettingsOfItsOriginal(): void
    {
        $uid = $this->create(12, 0, $this->editor(2));
        $row = $this->row($uid);

        self::assertSame(1, (int) $row['hidden']);
        self::assertSame('6', (string) $row['tx_contexts_enable']);
        self::assertSame('7', (string) $row['tx_contexts_disable']);
    }

    #[Test]
    public function showingMakesTheVariantVisibleAndHidesTheOriginalInItsContextOnly(): void
    {
        $editor = $this->editor(2);
        $uid    = $this->create(12, 5, $editor);

        $this->writer()->show($uid, 12, 5, true, $editor);

        self::assertSame(0, (int) $this->row($uid)['hidden']);
        $original = $this->row(12);
        self::assertSame(0, (int) $original['hidden'], 'the original stays visible outside the context');
        // Its other contexts survive: the hook rebuilds both columns from
        // the submitted map.
        self::assertSame('6', (string) $original['tx_contexts_enable']);
        self::assertSame(['5', '7'], $this->sortedList($original['tx_contexts_disable']));
    }

    #[Test]
    public function anOriginalTheEditorMayNoLongerEditIsNotHalfSwitched(): void
    {
        $editor = $this->editor(2);
        $uid    = $this->create(10, 5, $editor);
        GeneralUtility::makeInstance(ConnectionPool::class)->getConnectionForTable('tt_content')
            ->update('tt_content', ['editlock' => 1], ['uid' => 10]);

        try {
            $this->writer()->show($uid, 10, 5, true, $editor);
            self::fail('the show step must be refused');
        } catch (VariantException $e) {
            self::assertSame('variant.error.notAvailable', $e->getLabelKey());
        }

        self::assertSame(1, (int) $this->row($uid)['hidden'], 'the variant is not shown next to the original');
        self::assertSame('', (string) $this->row(10)['tx_contexts_disable']);
    }

    #[Test]
    public function anEditorWhoLostTheHiddenFieldDoesNotHideTheOriginal(): void
    {
        $uid = $this->create(10, 5, $this->editor(2));
        // Editor 4 may still set contexts, so without the pre-check the
        // original would be hidden in the context while DataHandler drops
        // the variant's hidden field: nothing visible there.
        $editor = $this->editor(4);

        try {
            $this->writer()->show($uid, 10, 5, true, $editor);
            self::fail('the show step must be refused');
        } catch (VariantException $e) {
            self::assertSame('variant.error.notAvailable', $e->getLabelKey());
        }

        self::assertSame(1, (int) $this->row($uid)['hidden'], 'the variant stays hidden');
        self::assertSame('', (string) $this->row(10)['tx_contexts_disable'], 'the original is not hidden in the context');
    }

    #[Test]
    public function anOriginalThatGotATranslationSinceIsNotHidden(): void
    {
        $editor = $this->editor(2);
        $uid    = $this->create(10, 5, $editor);
        GeneralUtility::makeInstance(ConnectionPool::class)->getConnectionForTable('tt_content')->insert('tt_content', [
            'uid'              => 30,
            'pid'              => 2,
            'header'           => 'Bürostühle',
            'bodytext'         => '<p>Passen.</p>',
            'CType'            => 'text',
            'sys_language_uid' => 1,
            'l18n_parent'      => 10,
            'sorting'          => 256,
        ]);

        try {
            $this->writer()->show($uid, 10, 5, true, $editor);
            self::fail('the show step must be refused');
        } catch (VariantException $e) {
            self::assertSame('variant.error.originalTranslated', $e->getLabelKey());
        }

        self::assertSame(1, (int) $this->row($uid)['hidden'], 'the variant stays hidden');
        self::assertSame('', (string) $this->row(10)['tx_contexts_disable']);
    }

    #[Test]
    public function showingWithoutHidingLeavesTheOriginalAsItIs(): void
    {
        $editor = $this->editor(2);
        $uid    = $this->create(10, 6, $editor);
        $before = $this->row(10);

        $this->writer()->show($uid, 10, 6, false, $editor);

        self::assertSame(0, (int) $this->row($uid)['hidden']);
        self::assertSame($before, $this->row(10));
    }

    #[Test]
    public function aVariantWithoutItsContextCannotBeShown(): void
    {
        $editor = $this->editor(2);
        $uid    = $this->create(10, 0, $editor);

        $this->expectExceptionObject(VariantException::notAvailable());
        $this->writer()->show($uid, 10, 5, true, $editor);
    }

    #[Test]
    public function aContextTheEditorMayNotSetIsNotLeftHalfWritten(): void
    {
        $editor = $this->editor(3);
        self::assertFalse($this->contexts()->mayAssign($editor));
        $count = $this->countContent();

        // The controller refuses this before the writer; the writer's
        // read-back is the second line of defence: the hook dropped the
        // setting, so the copy is deleted again.
        $element = $this->reader()->read(10, $editor);

        try {
            $this->writer()->create($element, 'Chairs', '<p>Text</p>', 5, $editor);
            self::fail('the creation must fail');
        } catch (VariantException $e) {
            self::assertSame('variant.error.createFailed', $e->getLabelKey());
        }

        self::assertSame($count, $this->countContent(), 'no copy is left behind');
    }

    #[Test]
    public function onlyAssignableContextsAreOffered(): void
    {
        self::assertTrue($this->contexts()->isInstalled());
        self::assertSame(
            [['uid' => 6, 'title' => 'Campaign'], ['uid' => 5, 'title' => 'Mobile channel']],
            $this->contexts()->available(),
        );
        self::assertNull($this->contexts()->title(7));
        self::assertNull($this->contexts()->title(8));
        self::assertTrue($this->contexts()->mayAssign($this->editor(2)));
    }

    #[Test]
    public function noContextIsAssignedInAWorkspace(): void
    {
        self::assertFalse($this->contexts()->mayAssign($this->editor(2, 1)));
    }

    #[Test]
    public function inAWorkspaceTheVariantIsAVersionAndNotLive(): void
    {
        $uid = $this->create(10, 0, $this->editor(2, 1));
        $row = $this->row($uid);

        self::assertSame(1, (int) $row['t3ver_wsid']);
        self::assertSame(self::HEADER, $row['header']);
    }

    #[Test]
    public function anEditorWithoutTheHiddenFieldGetsNoVariant(): void
    {
        $this->expectExceptionObject(VariantException::notAvailable());
        $this->reader()->read(10, $this->editor(4));
    }

    #[Test]
    public function anEditorWhoMayNotSetItsAccessRestrictionGetsNoVariant(): void
    {
        // Element 20 is for logged-in users from a start time on; editor 2
        // may not write fe_group or starttime, so a copy would lose both.
        $this->expectExceptionObject(VariantException::notAvailable());
        $this->reader()->read(20, $this->editor(2));
    }

    #[Test]
    public function aCopyThatLostTheAccessRestrictionIsDeletedAgain(): void
    {
        $element = $this->reader()->read(20, $this->editor(1));
        $editor  = $this->editor(2);
        $count   = $this->countContent();

        // The reader refuses editor 2; the writer's read-back is the second
        // line of defence when the copy comes out without the restriction.
        try {
            $this->writer()->create($element, self::HEADER, '<p>Text</p>', 0, $editor);
            self::fail('the creation must fail');
        } catch (VariantException $e) {
            self::assertSame('variant.error.createFailed', $e->getLabelKey());
        }

        self::assertSame($count, $this->countContent(), 'no copy is left behind');
    }

    #[Test]
    public function theVariantKeepsTheAccessRestrictionOfItsOriginal(): void
    {
        $uid = $this->create(20, 0, $this->editor(1));
        $row = $this->row($uid);

        self::assertSame('-2', (string) $row['fe_group']);
        self::assertSame(1735689600, (int) $row['starttime']);
    }

    #[Test]
    public function aPageTheEditorMayNotEditGetsNoVariant(): void
    {
        $this->expectExceptionObject(VariantException::notAvailable());
        $this->reader()->read(18, $this->editor(2));
    }

    #[Test]
    public function anElementThatIsNoTextElementGetsNoVariant(): void
    {
        $this->expectExceptionObject(VariantException::notAvailable());
        $this->reader()->read(17, $this->editor(2));
    }

    #[Test]
    public function anElementWithTranslationsGetsAVariantWithoutTheirOldWording(): void
    {
        // A site that translates every saved element gives each new element
        // a translation at once, so refusing those left no element.
        $editor  = $this->editor(2);
        $element = $this->reader()->read(13, $editor);
        $this->reader()->refuseForTranslations($element);
        $translation = $this->row(14);

        $uid = $this->create(13, 5, $editor);

        self::assertSame(self::HEADER, $this->row($uid)['header']);
        self::assertSame([], $this->translationsOf($uid), 'no translation in the old wording came along');
        self::assertSame($translation, $this->row(14), 'the translation of the original is not touched');
    }

    #[Test]
    public function anExtensionThatTranslatesSavedElementsGetsTheVariantWithItsNewText(): void
    {
        $hooks                                           = &$GLOBALS['TYPO3_CONF_VARS']['SC_OPTIONS']['t3lib/class.t3lib_tcemain.php'];
        $hooks['processDatamapClass']['translateOnSave'] = TranslateOnSaveHook::class;
        $hooks['processCmdmapClass']['translateOnSave']  = TranslateOnSaveHook::class;
        TranslateOnSaveHook::$translated                 = [];

        $uid = $this->create(13, 5, $this->editor(2));

        $saves = array_values(array_filter(
            TranslateOnSaveHook::$translated,
            static fn (array $save): bool => $save['table'] === 'tt_content' && (int) $save['uid'] === $uid,
        ));
        self::assertCount(1, $saves, 'the variant is saved once after the copy command, outside its pause');
        self::assertSame('update', $saves[0]['status']);
        self::assertContains('header', $saves[0]['fields']);
        self::assertContains('bodytext', $saves[0]['fields']);
    }

    #[Test]
    public function aVariantTranslatedLikeItsOriginalIsShownWithItsTranslation(): void
    {
        $editor = $this->editor(2);
        $uid    = $this->create(13, 5, $editor);
        // What translating the saved variant leaves (EXT:autotranslate
        // localizes the hidden copy): a hidden connected translation.
        $this->insertTranslation(31, $uid, 'Lampen für Studierende');

        $this->writer()->show($uid, 13, 5, true, $editor);

        self::assertSame(0, (int) $this->row($uid)['hidden']);
        self::assertSame(0, (int) $this->row(31)['hidden'], 'translated pages show the variant in their language');
        self::assertSame('5', (string) $this->row(13)['tx_contexts_disable'], 'the original is hidden in the context');
        self::assertSame(0, (int) $this->row(14)['hidden'], 'the translation of the original stays as it is');
    }

    #[Test]
    public function aTranslationOfTheVariantTheEditorMayNotEditIsNotHalfSwitched(): void
    {
        $editor = $this->editor(2);
        $uid    = $this->create(13, 5, $editor);
        $this->insertTranslation(31, $uid, 'Lampen für Studierende', ['editlock' => 1]);

        try {
            $this->writer()->show($uid, 13, 5, true, $editor);
            self::fail('the show step must be refused');
        } catch (VariantException $e) {
            self::assertSame('variant.error.notAvailable', $e->getLabelKey());
        }

        self::assertSame(1, (int) $this->row($uid)['hidden'], 'the variant stays hidden');
        self::assertSame('', (string) $this->row(13)['tx_contexts_disable'], 'the original is not hidden in the context');
    }

    #[Test]
    public function aConnectedTranslationIsRefused(): void
    {
        $editor  = $this->editor(2);
        $element = $this->reader()->read(14, $editor);

        $this->expectExceptionObject(VariantException::connectedTranslation());
        $this->reader()->refuseForTranslations($element);
    }

    #[Test]
    public function aFreeTranslationGetsAVariantInItsLanguage(): void
    {
        $editor  = $this->editor(2);
        $element = $this->reader()->read(16, $editor);
        $this->reader()->refuseForTranslations($element);

        $row = $this->row($this->create(16, 5, $editor));
        self::assertSame(1, (int) $row['sys_language_uid']);
        self::assertSame(0, (int) $row['l18n_parent']);
    }

    /**
     * The uids of the translations connected to $uid.
     *
     * @return list<int>
     */
    private function translationsOf(int $uid): array
    {
        $queryBuilder = GeneralUtility::makeInstance(ConnectionPool::class)->getQueryBuilderForTable('tt_content');
        $queryBuilder->getRestrictions()->removeAll();

        return array_map(intval(...), $queryBuilder->select('uid')->from('tt_content')
            ->where(
                $queryBuilder->expr()->eq('l18n_parent', $queryBuilder->createNamedParameter($uid, Connection::PARAM_INT)),
                $queryBuilder->expr()->eq('deleted', 0),
            )
            ->executeQuery()
            ->fetchFirstColumn());
    }

    /**
     * A hidden German translation connected to $parent.
     *
     * @param array<string, int|string> $fields
     */
    private function insertTranslation(int $uid, int $parent, string $header, array $fields = []): void
    {
        GeneralUtility::makeInstance(ConnectionPool::class)->getConnectionForTable('tt_content')->insert('tt_content', $fields + [
            'uid'              => $uid,
            'pid'              => 2,
            'header'           => $header,
            'bodytext'         => '<p>Günstig und stabil.</p>',
            'CType'            => 'text',
            'sys_language_uid' => 1,
            'l18n_parent'      => $parent,
            'hidden'           => 1,
            'sorting'          => 1100,
        ]);
    }

    /**
     * @return list<string>
     */
    private function sortedList(mixed $list): array
    {
        $values = GeneralUtility::trimExplode(',', is_scalar($list) ? (string) $list : '', true);
        sort($values);

        return $values;
    }
}
