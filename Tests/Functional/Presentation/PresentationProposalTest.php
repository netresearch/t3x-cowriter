<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\T3Cowriter\Tests\Functional\Presentation;

use Netresearch\NrLlm\Domain\Model\LlmConfiguration;
use Netresearch\NrLlm\Domain\Repository\LlmConfigurationRepository;
use Netresearch\NrLlm\Testing\FakeCompletionService;
use Netresearch\T3Cowriter\Controller\PresentationController;
use Netresearch\T3Cowriter\Service\FieldSuggestion\RecordFinder;
use Netresearch\T3Cowriter\Service\Presentation\ContentLanguageLabel;
use Netresearch\T3Cowriter\Service\Presentation\PresentationConfiguration;
use Netresearch\T3Cowriter\Service\Presentation\PresentationElementReader;
use Netresearch\T3Cowriter\Service\Presentation\PresentationSwitcher;
use Netresearch\T3Cowriter\Service\Presentation\PresentationSwitchToken;
use Netresearch\T3Cowriter\Service\Presentation\PresentationWriter;
use Netresearch\T3Cowriter\Service\Presentation\SectionProposalService;
use Netresearch\T3Cowriter\Service\RateLimiterInterface;
use Netresearch\T3Cowriter\Service\RateLimitResult;
use Netresearch\T3Cowriter\Tests\Support\ConfigurationAccessDouble;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Psr\Log\NullLogger;
use Symfony\Component\Yaml\Yaml;
use TYPO3\CMS\Backend\Routing\UriBuilder;
use TYPO3\CMS\Core\Configuration\ExtensionConfiguration;
use TYPO3\CMS\Core\Context\Context;
use TYPO3\CMS\Core\Core\Environment;
use TYPO3\CMS\Core\Crypto\HashService;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Http\ServerRequest;
use TYPO3\CMS\Core\Http\StreamFactory;
use TYPO3\CMS\Core\Localization\LanguageServiceFactory;
use TYPO3\CMS\Core\Site\SiteFinder;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;

/**
 * What the proposal tells the editor before anything is created: the title
 * of the introduction in the language of the content, and the warnings about
 * translations and images.
 */
#[CoversClass(PresentationController::class)]
#[CoversClass(ContentLanguageLabel::class)]
#[CoversClass(PresentationElementReader::class)]
final class PresentationProposalTest extends FunctionalTestCase
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
            ],
        ],
    ];

    protected function setUp(): void
    {
        parent::setUp();

        $directory = Environment::getConfigPath() . '/sites/main';
        GeneralUtility::mkdir_deep($directory);
        GeneralUtility::writeFile($directory . '/config.yaml', Yaml::dump([
            'rootPageId' => 1,
            'base'       => 'https://example.org/',
            'languages'  => [
                ['languageId' => 0, 'title' => 'English', 'locale' => 'en_US.UTF-8', 'base' => '/'],
                ['languageId' => 1, 'title' => 'Deutsch', 'locale' => 'de_DE.UTF-8', 'base' => '/de/'],
            ],
        ], 99, 2));

        $this->importCSVDataSet(__DIR__ . '/Fixtures/elements.csv');
        $this->importCSVDataSet(__DIR__ . '/Fixtures/images.csv');
    }

    /**
     * An editor whose backend speaks German.
     */
    private function germanEditor(int $uid): void
    {
        $backendUser               = $this->setUpBackendUser($uid);
        $backendUser->user['lang'] = 'de';
        $GLOBALS['LANG']           = $this->get(LanguageServiceFactory::class)->createFromUserPreferences($backendUser);
    }

    private function controller(): PresentationController
    {
        $rateLimiter = $this->createStub(RateLimiterInterface::class);
        $rateLimiter->method('checkLimit')->willReturn(new RateLimitResult(true, 20, 19, time() + 60));
        $configurationRepository = $this->createStub(LlmConfigurationRepository::class);
        $configurationRepository->method('findDefault')->willReturn(new LlmConfiguration());
        $finder = new RecordFinder(GeneralUtility::makeInstance(ConnectionPool::class));

        return new PresentationController(
            new PresentationConfiguration($this->get(ExtensionConfiguration::class)),
            new PresentationElementReader($finder),
            new SectionProposalService(new FakeCompletionService()),
            new PresentationWriter($finder, new NullLogger()),
            new PresentationSwitcher($finder, new NullLogger()),
            new PresentationSwitchToken(new HashService()),
            ConfigurationAccessDouble::selector($configurationRepository),
            $rateLimiter,
            $this->get(UriBuilder::class),
            GeneralUtility::makeInstance(Context::class),
            new NullLogger(),
            new ContentLanguageLabel($this->get(SiteFinder::class), $this->get(LanguageServiceFactory::class)),
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function propose(int $uid): array
    {
        $request = (new ServerRequest('https://example.com/typo3/ajax/cowriter/presentation', 'POST'))
            ->withBody((new StreamFactory())->createStream(json_encode(['uid' => $uid], JSON_THROW_ON_ERROR)));
        $response = $this->controller()->proposeAction($request);
        /** @var array<string, mixed> $data */
        $data = json_decode((string) $response->getBody(), true, 512, JSON_THROW_ON_ERROR);
        self::assertTrue($data['success'] ?? false, (string) $response->getBody());

        return $data;
    }

    /**
     * @param array<string, mixed> $proposal
     */
    private function firstTitle(array $proposal): mixed
    {
        $sections = $proposal['sections'] ?? null;

        return is_array($sections) && is_array($sections[0] ?? null) ? ($sections[0]['title'] ?? null) : null;
    }

    #[Test]
    public function theIntroductionIsNamedInTheLanguageOfTheContent(): void
    {
        $this->germanEditor(2);

        self::assertSame('Introduction', $this->firstTitle($this->propose(10)), 'an English element for a German editor');
        self::assertSame('Einleitung', $this->firstTitle($this->propose(18)), 'the German translation');
    }

    #[Test]
    public function theProposalWarnsAboutTranslationsAndAConnectedTranslation(): void
    {
        $this->germanEditor(2);

        self::assertSame(['translations'], $this->propose(10)['warnings']);
        self::assertSame(['connectedTranslation'], $this->propose(18)['warnings']);
        self::assertSame([], $this->propose(16)['warnings']);
    }

    #[Test]
    public function theProposalCountsTheImagesAndWarnsWhenTheyCannotBeTaken(): void
    {
        $this->germanEditor(2);
        $proposal = $this->propose(19);
        self::assertSame([[], 2], [$proposal['warnings'], $proposal['images']]);

        // Editor 6 may not modify file references.
        $this->germanEditor(6);
        $proposal = $this->propose(19);
        self::assertSame([['imagesNotTaken'], 2], [$proposal['warnings'], $proposal['images']]);
    }
}
