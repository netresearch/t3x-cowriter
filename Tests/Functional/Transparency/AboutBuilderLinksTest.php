<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\T3Cowriter\Tests\Functional\Transparency;

use Netresearch\T3Cowriter\Service\Transparency\AboutBuilder;
use Netresearch\T3Cowriter\Service\Transparency\ContextSources;
use Netresearch\T3Cowriter\Service\Transparency\ModelUse;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;

/**
 * The links of the "About this suggestion" section against TYPO3's real
 * module registry, module access and route builder: the Settings module
 * (system_settings on 14.3, tools_toolssettings on 13.4) only for system
 * maintainers, the nr-llm configuration record only for administrators.
 *
 * Users: 1 admin and system maintainer, 4 admin without being system
 * maintainer, 2 editor, 5 editor who may modify tx_nrllm_configuration.
 */
#[CoversClass(AboutBuilder::class)]
#[CoversClass(ModelUse::class)]
#[CoversClass(ContextSources::class)]
final class AboutBuilderLinksTest extends FunctionalTestCase
{
    private const CONFIGURATION_UID = 5;

    protected array $coreExtensionsToLoad = ['rte_ckeditor', 'install'];

    protected array $testExtensionsToLoad = [
        'netresearch/nr-vault',
        'netresearch/nr-llm',
        'netresearch/t3-cowriter',
    ];

    protected array $configurationToUseInTestInstance = [
        'SYS' => ['systemMaintainers' => [1]],
    ];

    protected function setUp(): void
    {
        parent::setUp();
        $this->importCSVDataSet(__DIR__ . '/Fixtures/neighbourhood.csv');
    }

    /**
     * @return array<string, string> label => url
     */
    private function linksFor(int $userId): array
    {
        $backendUser = $this->setUpBackendUser($userId);
        $about       = $this->get(AboutBuilder::class)->fieldSuggestions(
            new ModelUse('default', 'Default configuration', self::CONFIGURATION_UID, 'gpt-test', 'openai'),
            new ContextSources(pageTitle: true),
            ['description' => 'Description'],
            3,
            20,
            $backendUser,
        );

        self::assertIsArray($about['links']);
        $links = [];
        foreach ($about['links'] as $link) {
            $links[$link['label']] = $link['url'];
        }

        return $links;
    }

    private function settingsUrl(array $links): ?string
    {
        return $links['Cowriter extension configuration (Settings, Configure extensions)'] ?? null;
    }

    private function configurationUrl(array $links): ?string
    {
        return $links['Edit this nr-llm configuration'] ?? null;
    }

    #[Test]
    public function systemMaintainerGetsTheSettingsModuleAndTheConfigurationRecord(): void
    {
        $links = $this->linksFor(1);

        self::assertCount(2, $links);
        // 14.3: /module/system/settings, 13.4: /module/tools/settings.
        self::assertMatchesRegularExpression('#/module/(system|tools)/settings#', (string) $this->settingsUrl($links));

        $recordUrl = (string) $this->configurationUrl($links);
        self::assertStringContainsString('/record/edit', $recordUrl);
        self::assertStringContainsString(
            http_build_query(['edit' => ['tx_nrllm_configuration' => [self::CONFIGURATION_UID => 'edit']]]),
            $recordUrl,
        );
    }

    #[Test]
    public function adminWhoIsNotSystemMaintainerGetsOnlyTheConfigurationRecord(): void
    {
        $links = $this->linksFor(4);

        self::assertNull($this->settingsUrl($links));
        self::assertStringContainsString('/record/edit', (string) $this->configurationUrl($links));
        self::assertCount(1, $links);
    }

    #[Test]
    public function editorGetsNoLink(): void
    {
        self::assertSame([], $this->linksFor(2));
    }

    #[Test]
    public function editorWhoMayModifyConfigurationsStillGetsNoLink(): void
    {
        self::assertSame([], $this->linksFor(5));
    }

    #[Test]
    public function noConfigurationRecordMeansNoRecordLink(): void
    {
        $backendUser = $this->setUpBackendUser(1);
        $about       = $this->get(AboutBuilder::class)->imageGeneration(ModelUse::withoutConfiguration('gpt-image-2', ''), true, 4000, $backendUser);

        self::assertCount(1, $about['links']);
        self::assertMatchesRegularExpression('#/module/(system|tools)/settings#', $about['links'][0]['url']);
    }
}
