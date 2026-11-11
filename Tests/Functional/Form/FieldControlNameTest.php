<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\T3Cowriter\Tests\Functional\Form;

use DOMDocument;
use DOMElement;
use DOMNode;
use DOMXPath;
use Netresearch\T3Cowriter\Form\FieldControl\FieldSuggestionsControl;
use Netresearch\T3Cowriter\Form\FieldControl\PresentationControl;
use Netresearch\T3Cowriter\Form\FieldControl\VariantControl;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use TYPO3\CMS\Backend\Form\FormDataCompiler;
use TYPO3\CMS\Backend\Form\FormDataGroup\TcaDatabaseRecord;
use TYPO3\CMS\Backend\Form\NodeFactory;
use TYPO3\CMS\Backend\Routing\Route;
use TYPO3\CMS\Core\Core\SystemEnvironmentBuilder;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Http\NormalizedParams;
use TYPO3\CMS\Core\Http\ServerRequest;
use TYPO3\CMS\Core\Localization\LanguageServiceFactory;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;

/**
 * Every Cowriter button FormEngine renders has an accessible name. Core
 * renders a field control as a link around its icon and puts the control's
 * title on the icon, which is aria-hidden, so the link needs its own name
 * (NEXT-189: a role query for "Create an audience variant" found nothing).
 *
 * The form of a text element carries every field control of the extension:
 * the suggestions at the header, the presentation and the variant at the
 * rich text. The test finds the buttons in the rendered form and checks that
 * it found one kind per field control class, so a new control is covered or
 * fails here.
 */
#[CoversClass(FieldSuggestionsControl::class)]
#[CoversClass(PresentationControl::class)]
#[CoversClass(VariantControl::class)]
final class FieldControlNameTest extends FunctionalTestCase
{
    protected array $coreExtensionsToLoad = ['rte_ckeditor', 'seo', 'workspaces', 'extensionmanager', 'install'];

    protected array $testExtensionsToLoad = [
        'netresearch/nr-vault',
        'netresearch/nr-llm',
        'netresearch/t3-cowriter',
        'bk2k/bootstrap-package',
    ];

    protected array $configurationToUseInTestInstance = [
        'EXTENSIONS' => [
            't3_cowriter' => [
                'presentation' => ['enable' => '1'],
                'variant'      => ['enable' => '1'],
            ],
        ],
    ];

    protected function setUp(): void
    {
        parent::setUp();
        // Element 10 is a text element with headings, user 1 an administrator.
        $this->importCSVDataSet(__DIR__ . '/../Presentation/Fixtures/elements.csv');
        // The variant button needs an audience to offer.
        GeneralUtility::makeInstance(ConnectionPool::class)->getConnectionForTable('tx_nrllm_promptsnippet')->insert('tx_nrllm_promptsnippet', [
            'uid'        => 31,
            'pid'        => 0,
            'identifier' => 'students',
            'name'       => 'Students',
            'tags'       => 'audience',
            'snippet'    => 'Students on a small budget.',
            'is_active'  => 1,
        ]);

        $request = (new ServerRequest('https://example.com/typo3/record/edit', 'GET'))
            ->withAttribute('applicationType', SystemEnvironmentBuilder::REQUESTTYPE_BE);
        $GLOBALS['TYPO3_REQUEST'] = $request->withAttribute('normalizedParams', NormalizedParams::createFromRequest($request));
    }

    protected function tearDown(): void
    {
        unset($GLOBALS['TYPO3_REQUEST']);
        parent::tearDown();
    }

    #[Test]
    public function everyCowriterButtonInTheFormHasAName(): void
    {
        $buttons = $this->cowriterButtons($this->formHtml(1, 10));

        $kinds = [];
        foreach ($buttons as $button) {
            $id   = $button->getAttribute('id');
            $name = $this->accessibleName($button);
            self::assertNotSame('', $name, $id . ' has an accessible name');
            self::assertStringNotContainsString('LLL:', $name, $id . ' has a translated name');
            $kinds[(string) preg_replace('/^(t3js-cowriter-[a-z]+)-.*$/', '$1', $id)] = true;
        }

        $controlClasses = glob(dirname(__DIR__, 3) . '/Classes/Form/FieldControl/*.php');
        self::assertIsArray($controlClasses);
        self::assertCount(count($controlClasses), $kinds, 'one kind of button per field control class: ' . implode(', ', array_keys($kinds)));
    }

    #[Test]
    public function theButtonsAreNamedLikeTheirTooltips(): void
    {
        $names = array_map($this->accessibleName(...), $this->cowriterButtons($this->formHtml(1, 10)));

        self::assertContains('Create an audience variant', $names);
        self::assertContains('Present as tabs or accordion', $names);
        self::assertContains('Suggest values with AI', $names);
    }

    private function formHtml(int $userUid, int $elementUid): string
    {
        $backendUser     = $this->setUpBackendUser($userUid);
        $GLOBALS['LANG'] = $this->get(LanguageServiceFactory::class)->createFromUserPreferences($backendUser);

        $formData = $this->get(FormDataCompiler::class)->compile(
            [
                'request'    => $GLOBALS['TYPO3_REQUEST']->withAttribute('route', new Route('/record/edit', ['packageName' => 'typo3/cms-backend'])),
                'tableName'  => 'tt_content',
                'vanillaUid' => $elementUid,
                'command'    => 'edit',
            ],
            $this->get(TcaDatabaseRecord::class),
        );
        $formData['renderType'] = 'fullRecordContainer';

        return (string) $this->get(NodeFactory::class)->create($formData)->render()['html'];
    }

    /**
     * The links and buttons the Cowriter renders (their ids start with
     * t3js-cowriter-).
     *
     * @return list<DOMElement>
     */
    private function cowriterButtons(string $html): array
    {
        $document = new DOMDocument();
        libxml_use_internal_errors(true);
        $document->loadHTML('<?xml encoding="utf-8"?><body>' . $html . '</body>');
        libxml_clear_errors();

        $nodes = (new DOMXPath($document))->query(
            "//*[starts-with(@id, 't3js-cowriter-')][self::a or self::button or @role='button']",
        );
        self::assertNotFalse($nodes);

        $buttons = [];
        foreach ($nodes as $node) {
            if ($node instanceof DOMElement) {
                $buttons[] = $node;
            }
        }

        self::assertNotSame([], $buttons, 'the form renders Cowriter buttons');

        return $buttons;
    }

    /**
     * The name a screen reader announces, as far as these buttons can get
     * one: aria-label, otherwise the text outside aria-hidden parts.
     */
    private function accessibleName(DOMElement $element): string
    {
        $label = trim($element->getAttribute('aria-label'));

        return $label !== '' ? $label : trim((string) preg_replace('/\s+/', ' ', $this->visibleText($element)));
    }

    private function visibleText(DOMNode $node): string
    {
        if ($node instanceof DOMElement && $node->getAttribute('aria-hidden') === 'true') {
            return '';
        }

        if (!$node->hasChildNodes()) {
            return $node->nodeType === XML_TEXT_NODE ? $node->textContent : '';
        }

        $text = '';
        foreach ($node->childNodes as $child) {
            $text .= $this->visibleText($child);
        }

        return $text;
    }
}
