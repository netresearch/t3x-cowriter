<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\T3Cowriter\Tests\Unit\Service\Transparency;

use Netresearch\NrLlm\Domain\Model\CompletionResponse;
use Netresearch\NrLlm\Domain\Model\LlmConfiguration;
use Netresearch\NrLlm\Domain\Model\UsageStatistics;
use Netresearch\T3Cowriter\Service\BackendLabels;
use Netresearch\T3Cowriter\Service\Transparency\AboutBuilder;
use Netresearch\T3Cowriter\Service\Transparency\ContextSources;
use Netresearch\T3Cowriter\Service\Transparency\ModelUse;
use Netresearch\T3Cowriter\Tests\Support\XliffLanguageServiceTrait;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use TYPO3\CMS\Backend\Module\ModuleProvider;
use TYPO3\CMS\Backend\Routing\UriBuilder;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;
use TYPO3\CMS\Core\Http\Uri;

#[CoversClass(AboutBuilder::class)]
#[CoversClass(ContextSources::class)]
#[CoversClass(ModelUse::class)]
#[CoversClass(BackendLabels::class)]
final class AboutBuilderTest extends TestCase
{
    use XliffLanguageServiceTrait;

    /** @var list<array{0: string, 1: array<string, mixed>}> */
    private array $built = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->useXliffLanguageService();
    }

    /**
     * @param list<string> $registered module identifiers ModuleProvider knows
     */
    private function builder(array $registered = ['system_settings'], bool $granted = true): AboutBuilder
    {
        $uriBuilder = $this->createStub(UriBuilder::class);
        $uriBuilder->method('buildUriFromRoute')->willReturnCallback(function (string $route, array $parameters = []): Uri {
            $this->built[] = [$route, $parameters];

            return new Uri('/typo3/' . $route);
        });
        $modules = $this->createStub(ModuleProvider::class);
        $modules->method('isModuleRegistered')->willReturnCallback(static fn (string $id): bool => in_array($id, $registered, true));
        $modules->method('accessGranted')->willReturn($granted);

        return new AboutBuilder($uriBuilder, $modules);
    }

    private function user(bool $admin = false): BackendUserAuthentication
    {
        $user = $this->createStub(BackendUserAuthentication::class);
        $user->method('isAdmin')->willReturn($admin);
        $user->method('check')->willReturn(true);

        return $user;
    }

    private function modelUse(): ModelUse
    {
        $configuration = $this->createStub(LlmConfiguration::class);
        $configuration->method('getIdentifier')->willReturn('fake-general');
        $configuration->method('getName')->willReturn('Fake general');
        $configuration->method('getUid')->willReturn(7);

        return ModelUse::fromResponse(
            $configuration,
            new CompletionResponse(content: '{}', model: 'gpt-x-2026-01', usage: UsageStatistics::fromTokens(1, 1), provider: 'openai'),
        );
    }

    #[Test]
    public function modelUseTakesTheModelTheResponseReports(): void
    {
        $use = $this->modelUse();

        self::assertSame(['fake-general', 'Fake general', 7, 'gpt-x-2026-01', 'openai'], [
            $use->configurationIdentifier, $use->configurationName, $use->configurationUid, $use->model, $use->provider,
        ]);
        self::assertEquals(new ModelUse('', '', 0, '', ''), ModelUse::fromResponse(null, null));
        self::assertEquals(new ModelUse('', '', 0, 'gpt-image-2', 'openai'), ModelUse::withoutConfiguration(' gpt-image-2 ', ' openai'));
    }

    #[Test]
    public function fieldSuggestionsNameOriginConfigurationModelSourcesSettingsAndLinks(): void
    {
        $about = $this->builder()->fieldSuggestions(
            $this->modelUse(),
            new ContextSources(pageTitle: true, fieldValues: true, pageFields: 12, recordFields: 1, contentElements: 5, parentTitle: true, siblingTitles: 3),
            ['header' => 'Header', 'subheader' => 'Subheader'],
            3,
            20,
            $this->user(true),
        );

        self::assertSame([
            'origin'        => 'The Cowriter extension (t3_cowriter), feature "Field suggestions"',
            'feature'       => 'Field suggestions',
            'configuration' => 'Fake general (fake-general)',
            'model'         => 'gpt-x-2026-01 (openai)',
            'note'          => '',
            'sources'       => [
                'Page title',
                'Stored and typed value of the field',
                '12 page fields',
                '1 other field of this record',
                'Content of 5 elements',
                'Title of the parent page',
                'Titles of 3 neighbouring pages',
            ],
            'settings' => [
                ['label' => 'Feature', 'value' => 'Field suggestions, switched on'],
                ['label' => 'Fields with suggestions you may edit', 'value' => 'Header, Subheader'],
                ['label' => 'Number of suggestions', 'value' => '3'],
                ['label' => 'Limits', 'value' => '20 requests per minute; page content up to 6000 characters; titles of up to 5 neighbouring pages; up to 16000 characters of data in total, not counting the values of the fields'],
            ],
            'links' => [
                ['label' => 'Cowriter extension configuration (Settings, Configure extensions)', 'url' => '/typo3/system_settings'],
                ['label' => 'Edit this nr-llm configuration', 'url' => '/typo3/record_edit'],
            ],
        ], $about);
        self::assertSame(['record_edit', ['edit' => ['tx_nrllm_configuration' => [7 => 'edit']]]], $this->built[1]);
    }

    #[Test]
    public function singularSourcesHaveTheirOwnWordingAndZeroCountsAreLeftOut(): void
    {
        $about = $this->builder()->fieldOverview(
            $this->modelUse(),
            new ContextSources(fieldValues: true, pageFields: 1, contentElements: 1, siblingTitles: 1, reviewedFields: true),
            [],
            20,
            $this->user(),
        );

        self::assertSame(['Current values of the reviewed fields', '1 page field', 'Content of 1 element', 'Title of 1 neighbouring page'], $about['sources']);
        // No field the editor may edit: the row is left out rather than empty.
        self::assertSame(['Feature', 'Limits'], array_column($about['settings'], 'label'));
    }

    #[Test]
    public function anEditorGetsNoRecordLinkAndNoSettingsLinkWithoutModuleAccess(): void
    {
        $about = $this->builder(['system_settings'], false)->fieldSuggestions($this->modelUse(), new ContextSources(), [], 3, 20, $this->user());

        self::assertSame([], $about['links']);
    }

    #[Test]
    public function aSystemMaintainerWhoIsNoAdminGetsOnlyTheSettingsLink(): void
    {
        $about = $this->builder()->fieldSuggestions($this->modelUse(), new ContextSources(), [], 3, 20, $this->user());

        self::assertSame(['/typo3/system_settings'], array_column($about['links'], 'url'));
    }

    #[Test]
    public function theSettingsModuleOfTypo3Version13IsUsedWhereTheNewOneIsMissing(): void
    {
        $about = $this->builder(['tools_toolssettings'])->fieldSuggestions($this->modelUse(), new ContextSources(), [], 3, 20, $this->user());

        self::assertSame(['/typo3/tools_toolssettings'], array_column($about['links'], 'url'));
    }

    #[Test]
    public function aRouteThatCannotBeBuiltGivesNoLink(): void
    {
        $uriBuilder = $this->createStub(UriBuilder::class);
        $uriBuilder->method('buildUriFromRoute')->willThrowException(new RuntimeException('no route'));
        $modules = $this->createStub(ModuleProvider::class);
        $modules->method('isModuleRegistered')->willReturn(true);
        $modules->method('accessGranted')->willReturn(true);

        $about = (new AboutBuilder($uriBuilder, $modules))->fieldSuggestions($this->modelUse(), new ContextSources(), [], 3, 20, $this->user(true));

        self::assertSame([], $about['links']);
    }

    #[Test]
    public function aMissingModelIsSaidAndAConfigurationShowsOnceWhenNameAndIdentifierMatch(): void
    {
        $about = $this->builder()->fieldSuggestions(new ModelUse('general', 'general', 0, '', ''), new ContextSources(), [], 3, 20, $this->user(true));

        self::assertSame('general', $about['configuration']);
        self::assertSame('not reported by nr-llm', $about['model']);
        // Without a configuration uid there is no record to link.
        self::assertSame(['/typo3/system_settings'], array_column($about['links'], 'url'));
        self::assertSame('Fake (fake)', $this->builder()->fieldSuggestions(new ModelUse('fake', 'Fake', 0, 'm', ''), new ContextSources(), [], 1, 1, $this->user())['configuration']);
        self::assertSame('Fake', $this->builder()->fieldSuggestions(new ModelUse('', 'Fake', 0, 'm', ''), new ContextSources(), [], 1, 1, $this->user())['configuration']);
        self::assertSame('m', $this->builder()->fieldSuggestions(new ModelUse('', '', 0, 'm', ''), new ContextSources(), [], 1, 1, $this->user())['model']);
    }

    #[Test]
    public function imageGenerationNamesWhereTheModelComesFromAndSendsOnlyTheDescription(): void
    {
        $fromSetting = $this->builder()->imageGeneration(ModelUse::withoutConfiguration('gpt-image-2', ''), true, 1000, $this->user());
        $fromDefault = $this->builder()->imageGeneration(ModelUse::withoutConfiguration('gpt-image-2', ''), false, 1000, $this->user());

        self::assertSame('', $fromSetting['configuration']);
        self::assertSame('gpt-image-2', $fromSetting['model']);
        self::assertSame(['Only your description of the image'], $fromSetting['sources']);
        self::assertSame([
            ['label' => 'Feature', 'value' => 'Generate image, switched on'],
            ['label' => 'Image model', 'value' => 'gpt-image-2 (set in the extension configuration)'],
            ['label' => 'Limits', 'value' => 'Description up to 1000 characters; the image is stored in your upload folder'],
        ], $fromSetting['settings']);
        self::assertSame('gpt-image-2 (default image model of nr-llm)', $fromDefault['settings'][1]['value']);
    }

    #[Test]
    public function presentationFromHeadingsSaysNoModelWasAsked(): void
    {
        $about = $this->builder()->presentation(null, 6, $this->user(true));

        self::assertSame('', $about['configuration']);
        self::assertSame('', $about['model']);
        self::assertSame([], $about['sources']);
        self::assertSame('No language model was asked: the sections follow the h2 and h3 headings of the text.', $about['note']);
        self::assertSame('Limits', $about['settings'][1]['label']);
        self::assertSame('2 to 12 sections; texts of up to 80 blocks; the language model is asked only for a text without h2 or h3 headings', $about['settings'][1]['value']);
    }

    #[Test]
    public function presentationFromTheModelNamesModelAndTheBlocksSent(): void
    {
        $about = $this->builder()->presentation($this->modelUse(), 6, $this->user());

        self::assertSame('gpt-x-2026-01 (openai)', $about['model']);
        self::assertSame('', $about['note']);
        self::assertSame(['Heading of this element', 'The beginning of each of the 6 text blocks of this element'], $about['sources']);
    }
}
