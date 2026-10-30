<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\T3Cowriter\Tests\Unit\EventListener;

use Netresearch\T3Cowriter\EventListener\AddFieldOverviewButtonListener;
use Netresearch\T3Cowriter\EventListener\RegisterFieldSuggestionControlsListener;
use Netresearch\T3Cowriter\Service\FieldSuggestion\Tca;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use TYPO3\CMS\Backend\Routing\Route;
use TYPO3\CMS\Backend\Routing\UriBuilder;
use TYPO3\CMS\Backend\Template\Components\ButtonBar;
use TYPO3\CMS\Backend\Template\Components\Buttons\LinkButton;
use TYPO3\CMS\Backend\Template\Components\ModifyButtonBarEvent;
use TYPO3\CMS\Core\Http\ServerRequest;
use TYPO3\CMS\Core\Http\Uri;
use TYPO3\CMS\Core\Imaging\Icon;
use TYPO3\CMS\Core\Imaging\IconFactory;

#[CoversClass(AddFieldOverviewButtonListener::class)]
#[CoversClass(Tca::class)]
final class AddFieldOverviewButtonListenerTest extends TestCase
{
    protected function setUp(): void
    {
        $control        = [RegisterFieldSuggestionControlsListener::CONTROL_NAME => ['renderType' => RegisterFieldSuggestionControlsListener::NODE_NAME]];
        $GLOBALS['TCA'] = [
            'pages' => ['columns' => [
                'title'     => ['config' => ['type' => 'input']],
                'seo_title' => ['config' => ['type' => 'input', 'fieldControl' => $control]],
            ]],
            'sys_category' => ['columns' => ['title' => ['config' => ['type' => 'input']]]],
        ];
    }

    protected function tearDown(): void
    {
        unset($GLOBALS['TCA'], $GLOBALS['TYPO3_REQUEST']);
    }

    private function subject(): AddFieldOverviewButtonListener
    {
        $uriBuilder = $this->createStub(UriBuilder::class);
        $uriBuilder->method('buildUriFromRoute')->willReturn(new Uri('/typo3/ajax/cowriter/suggestions/all?token=x'));
        $iconFactory = $this->createStub(IconFactory::class);
        $iconFactory->method('getIcon')->willReturn($this->createStub(Icon::class));

        return new AddFieldOverviewButtonListener($uriBuilder, $iconFactory);
    }

    /**
     * @param array<string, mixed>|null $edit
     */
    private function request(?array $edit, string $route = 'record_edit', bool $inBody = false): ServerRequest
    {
        $request = (new ServerRequest('https://example.com/typo3/record/edit'))
            ->withAttribute('route', new Route('/record/edit', ['_identifier' => $route]));
        if ($edit !== null) {
            $request = $inBody ? $request->withParsedBody(['edit' => $edit]) : $request->withQueryParams(['edit' => $edit]);
        }

        return $request;
    }

    /**
     * The buttons after the listener ran; TYPO3 13.4 has no request in the
     * event and the listener reads the global request instead.
     *
     * @return list<LinkButton>
     */
    private function addedButtons(ServerRequest $request): array
    {
        $GLOBALS['TYPO3_REQUEST'] = $request;
        $event                    = new ModifyButtonBarEvent([], $this->createStub(ButtonBar::class), $request);

        ($this->subject())($event);

        $added = [];
        foreach ($event->getButtons()[ButtonBar::BUTTON_POSITION_LEFT] ?? [] as $group) {
            foreach ($group as $button) {
                if ($button instanceof LinkButton) {
                    $added[] = $button;
                }
            }
        }

        return $added;
    }

    #[Test]
    public function aHiddenButtonIsAddedToTheFormOfASingleRecordWithSuggestionFields(): void
    {
        $buttons = $this->addedButtons($this->request(['pages' => ['12' => 'edit']]));

        self::assertCount(1, $buttons);
        $button = $buttons[0];
        self::assertSame(AddFieldOverviewButtonListener::BUTTON_CLASS . ' d-none', $button->getClasses());
        self::assertSame('#', $button->getHref());
        self::assertSame('Suggest values for all fields with AI', $button->getTitle());
        self::assertTrue($button->getShowLabelText());
        self::assertTrue($button->isValid());
        $data = $button->getDataAttributes();
        self::assertSame('/typo3/ajax/cowriter/suggestions/all?token=x', $data['url']);
        self::assertSame('pages', $data['table']);
        self::assertArrayHasKey('label-column-apply', $data);
        self::assertArrayHasKey('label-applied-singular', $data);
    }

    #[Test]
    public function aNewRecordGetsTheButtonToo(): void
    {
        self::assertCount(1, $this->addedButtons($this->request(['pages' => ['3' => 'new']])));
    }

    #[Test]
    public function theEditArgumentOfAPostedFormCounts(): void
    {
        self::assertCount(1, $this->addedButtons($this->request(['pages' => ['12' => 'edit']], inBody: true)));
    }

    #[Test]
    public function otherModulesGetNoButton(): void
    {
        self::assertSame([], $this->addedButtons($this->request(['pages' => ['12' => 'edit']], 'web_layout')));
    }

    #[Test]
    public function aFormWithSeveralRecordsGetsNoButton(): void
    {
        self::assertSame([], $this->addedButtons($this->request(['pages' => ['12,13' => 'edit']])));
        self::assertSame([], $this->addedButtons($this->request(['pages' => ['12' => 'edit', '13' => 'edit']])));
        self::assertSame([], $this->addedButtons($this->request(['pages' => ['12' => 'edit'], 'tt_content' => ['4' => 'edit']])));
    }

    #[Test]
    public function aTableWithoutSuggestionFieldGetsNoButton(): void
    {
        self::assertSame([], $this->addedButtons($this->request(['sys_category' => ['4' => 'edit']])));
        self::assertSame([], $this->addedButtons($this->request(['tx_unknown' => ['4' => 'edit']])));
    }

    #[Test]
    public function withoutEditArgumentThereIsNoButton(): void
    {
        self::assertSame([], $this->addedButtons($this->request(null)));
        self::assertSame([], $this->addedButtons($this->request(['pages' => 'edit'])));
    }

    #[Test]
    public function aDisabledControlStillCountsAsSuggestionField(): void
    {
        // The JavaScript decides by the controls FormEngine rendered; a
        // disabled control renders none, so the button would stay hidden.
        $GLOBALS['TCA']['sys_category']['columns']['title']['config']['fieldControl'] = [
            RegisterFieldSuggestionControlsListener::CONTROL_NAME => ['disabled' => true],
        ];

        self::assertCount(1, $this->addedButtons($this->request(['sys_category' => ['4' => 'edit']])));
    }
}
