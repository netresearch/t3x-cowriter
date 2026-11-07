<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\T3Cowriter\Tests\Unit\Service\Overview;

use Netresearch\NrLlm\Domain\Model\LlmConfiguration;
use Netresearch\NrLlm\Domain\Model\Model;
use Netresearch\NrLlm\Domain\Model\PromptSnippet;
use Netresearch\NrLlm\Domain\Model\Provider;
use Netresearch\NrLlm\Domain\Model\Task;
use Netresearch\NrLlm\Domain\Repository\LlmConfigurationRepository;
use Netresearch\NrLlm\Domain\Repository\ModelRepository;
use Netresearch\NrLlm\Domain\Repository\PromptSnippetRepository;
use Netresearch\NrLlm\Domain\Repository\ProviderRepository;
use Netresearch\NrLlm\Domain\Repository\TaskRepository;
use Netresearch\NrLlm\Provider\Middleware\MiddlewarePipeline;
use Netresearch\NrLlm\Service\BudgetServiceInterface;
use Netresearch\NrLlm\Service\ConfigurationResolver;
use Netresearch\NrLlm\Service\Feature\TranslationServiceInterface;
use Netresearch\NrLlm\Service\Guardrail\InputGuardrailScreener;
use Netresearch\NrLlm\Service\LlmConfigurationServiceInterface;
use Netresearch\NrLlm\Service\Site\SiteLlmSettings;
use Netresearch\NrLlm\Service\UsageTrackerServiceInterface;
use Netresearch\NrLlm\Specialized\Image\DallEImageService;
use Netresearch\NrLlm\Specialized\Pricing\SpecializedCostCalculatorInterface;
use Netresearch\NrVault\Service\VaultServiceInterface;
use Netresearch\T3Cowriter\Service\ConfigurationSelector;
use Netresearch\T3Cowriter\Service\DiagnosticService;
use Netresearch\T3Cowriter\Service\FieldSuggestion\FieldSuggestionConfiguration;
use Netresearch\T3Cowriter\Service\Image\GeneratedImageStorageInterface;
use Netresearch\T3Cowriter\Service\Image\ImageGenerationConfiguration;
use Netresearch\T3Cowriter\Service\InlineSuggestion\InlineSuggestionConfiguration;
use Netresearch\T3Cowriter\Service\Overview\ReadinessAction;
use Netresearch\T3Cowriter\Service\Overview\ReadinessReason;
use Netresearch\T3Cowriter\Service\Overview\ReadinessRow;
use Netresearch\T3Cowriter\Service\Overview\ReadinessService;
use Netresearch\T3Cowriter\Service\Overview\ReadinessState;
use Netresearch\T3Cowriter\Service\Presentation\PresentationConfiguration;
use Netresearch\T3Cowriter\Service\Variant\VariantConfiguration;
use Netresearch\T3Cowriter\Tests\Support\TestQueryResult;
use Netresearch\T3Cowriter\Tests\Support\XliffLanguageServiceTrait;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Psr\Log\NullLogger;
use stdClass;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;
use TYPO3\CMS\Core\Configuration\ExtensionConfiguration;
use TYPO3\CMS\Core\Exception\SiteNotFoundException;
use TYPO3\CMS\Core\Resource\Folder;
use TYPO3\CMS\Core\Site\Entity\Site;
use TYPO3\CMS\Core\Site\Entity\SiteSettings;
use TYPO3\CMS\Core\Site\SiteFinder;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Extbase\Persistence\ObjectStorage;

/**
 * Readiness follows the path each action takes at runtime, with real nr-llm
 * domain objects, a real ConfigurationSelector and a real ConfigurationResolver.
 * Only the repositories, the access service and the vault are stubbed.
 */
#[CoversClass(ReadinessService::class)]
#[CoversClass(ReadinessRow::class)]
#[CoversClass(ReadinessReason::class)]
#[CoversClass(ReadinessState::class)]
#[CoversClass(PresentationConfiguration::class)]
#[CoversClass(VariantConfiguration::class)]
final class ReadinessServiceTest extends TestCase
{
    use XliffLanguageServiceTrait;

    /** @var array<string, string> cowriter settings by path */
    private array $settings = [];

    private ?LlmConfiguration $default = null;

    /** @var array<string, LlmConfiguration> */
    private array $byIdentifier = [];

    private bool $hasAccess = true;

    /** @var list<string> configurations the viewer may not use */
    private array $denied = [];

    /** @var list<Task> tasks of the dialog's category */
    private array $tasks = [];

    private bool $openAiKeyStored = false;

    private bool $hasUploadFolder = true;

    private bool $hasAudience = true;

    /** @var array<string, array{identifier: string, name: string, available: bool}> */
    private array $translators = [];

    /** @var array<string, string> site identifier => configuration the site names */
    private array $siteConfigurations = [];

    protected function tearDown(): void
    {
        GeneralUtility::purgeInstances();
        unset($GLOBALS['TCA']);
        parent::tearDown();
    }

    #[Test]
    public function writingIsReadyWhenTheDefaultConfigurationsModelAndProviderAreUsable(): void
    {
        $this->default = $this->configuration('default', $this->model('GPT', $this->provider('OpenAI', 'openai', true)));

        $row = $this->service()->row(ReadinessAction::Dialog);

        self::assertSame(ReadinessState::Ready, $row->state);
        self::assertSame(ReadinessReason::ConfigurationReady, $row->reason);
        self::assertSame(['default', 'GPT', 'OpenAI'], $row->arguments);
    }

    /**
     * The old status page counted "a provider with a key" anywhere; the
     * default path uses one specific provider.
     */
    #[Test]
    public function writingIsNotReadyWhenTheDefaultsProviderHasNoKeyWhileAnotherProviderHasOne(): void
    {
        $keyless       = $this->provider('Keyless', 'openai', false);
        $keyed         = $this->provider('Keyed', 'claude', true);
        $this->default = $this->configuration('default', $this->model('GPT', $keyless));

        $this->useXliffLanguageService();
        self::assertTrue($this->diagnostics([$keyless, $keyed])->runAll()->ok, 'the instance-wide checks pass');
        unset($GLOBALS['LANG']);

        GeneralUtility::purgeInstances();
        $row = $this->service()->row(ReadinessAction::Dialog);

        self::assertSame(ReadinessState::NotReady, $row->state);
        self::assertSame(ReadinessReason::ProviderKeyMissing, $row->reason);
        self::assertSame(['Keyless'], $row->arguments);
        self::assertSame('nrllm_providers', $row->fixRoute());
    }

    #[Test]
    public function aProviderThatNeedsNoKeyIsReadyWithoutOne(): void
    {
        $this->default = $this->configuration('default', $this->model('Llama', $this->provider('Local', 'ollama', false)));

        self::assertSame(ReadinessState::Ready, $this->service()->row(ReadinessAction::Dialog)->state);
    }

    #[Test]
    public function writingIsNotReadyWhenTheViewerMayNotUseTheDefault(): void
    {
        $this->default   = $this->configuration('default', $this->model('GPT', $this->provider('OpenAI', 'openai', true)));
        $this->hasAccess = false;

        $row = $this->service()->row(ReadinessAction::Dialog);

        self::assertSame(ReadinessState::NotReady, $row->state);
        self::assertSame(ReadinessReason::ConfigurationDenied, $row->reason);
        GeneralUtility::purgeInstances();
    }

    #[Test]
    public function writingIsNotReadyWithoutADefault(): void
    {
        $row = $this->service()->row(ReadinessAction::Dialog);

        self::assertSame(ReadinessState::NotReady, $row->state);
        self::assertSame(ReadinessReason::NoConfiguration, $row->reason);
        self::assertSame('nrllm_configurations', $row->fixRoute());
    }

    #[Test]
    public function anInactiveModelIsNotReady(): void
    {
        $model = $this->model('GPT', $this->provider('OpenAI', 'openai', true));
        $model->setIsActive(false);

        $this->default = $this->configuration('default', $model);

        $row = $this->service()->row(ReadinessAction::Dialog);

        self::assertSame(ReadinessReason::ModelInactive, $row->reason);
        self::assertSame(['GPT'], $row->arguments);
        GeneralUtility::purgeInstances();
    }

    #[Test]
    public function anInactiveProviderIsNotReady(): void
    {
        $provider = $this->provider('OpenAI', 'openai', true);
        $provider->setIsActive(false);

        $this->default = $this->configuration('default', $this->model('GPT', $provider));

        self::assertSame(ReadinessReason::ProviderInactive, $this->service()->row(ReadinessAction::Dialog)->reason);
        GeneralUtility::purgeInstances();
    }

    #[Test]
    public function aModelWithoutProviderIsNotReady(): void
    {
        $this->default = $this->configuration('default', $this->model('GPT', null));

        self::assertSame(ReadinessReason::ProviderMissing, $this->service()->row(ReadinessAction::Dialog)->reason);
    }

    #[Test]
    public function aModelChosenPerRequestIsNotChecked(): void
    {
        $this->default = $this->configuration('default', $this->model('GPT', $this->provider('OpenAI', 'openai', true)));
        $this->default->setModelSelectionMode('criteria');

        $row = $this->service()->row(ReadinessAction::Dialog);

        self::assertSame(ReadinessState::NotChecked, $row->state);
        self::assertSame(ReadinessReason::ModelChosenPerRequest, $row->reason);
        GeneralUtility::purgeInstances();
    }

    #[Test]
    public function anUnknownAdapterIsNotChecked(): void
    {
        $this->default = $this->configuration('default', $this->model('GPT', $this->provider('Odd', 'no-such-adapter', false)));

        $row = $this->service()->row(ReadinessAction::Dialog);

        self::assertSame(ReadinessState::NotChecked, $row->state);
        self::assertSame(ReadinessReason::AdapterUnknown, $row->reason);
    }

    #[Test]
    public function visionIsReadyWhenTheGenericDefaultsModelSeesImages(): void
    {
        $this->default = $this->configuration('default', $this->model('GPT', $this->provider('OpenAI', 'openai', true), ['chat', 'vision']));

        $row = $this->service()->row(ReadinessAction::Vision);

        self::assertSame(ReadinessState::Ready, $row->state);
        self::assertSame(['default', 'GPT', 'OpenAI'], $row->arguments);
    }

    #[Test]
    public function visionIsNotReadyWhenTheDefaultsModelCannotSeeImages(): void
    {
        $this->default = $this->configuration('default', $this->model('GPT', $this->provider('OpenAI', 'openai', true), ['chat']));

        $row = $this->service()->row(ReadinessAction::Vision);

        self::assertSame(ReadinessState::NotReady, $row->state);
        self::assertSame(ReadinessReason::ModelLacksVision, $row->reason);
        self::assertSame(['GPT'], $row->arguments);
        GeneralUtility::purgeInstances();
    }

    /**
     * nr-llm's generic call skips a group-restricted default, while the
     * cowriter's own selector runs it for a member of the group.
     */
    #[Test]
    public function aGroupRestrictedDefaultServesWritingButNotTheGenericPath(): void
    {
        $this->default = $this->configuration('default', $this->model('GPT', $this->provider('OpenAI', 'openai', true), ['chat', 'vision']));
        $groups        = new ObjectStorage();
        $groups->attach(new stdClass());

        $this->default->setBeGroups($groups);

        $service = $this->service();

        self::assertSame(ReadinessState::Ready, $service->row(ReadinessAction::Dialog)->state);

        $vision = $service->row(ReadinessAction::Vision);
        self::assertSame(ReadinessState::NotReady, $vision->state);
        self::assertSame(ReadinessReason::NoGenericDefault, $vision->reason);
        self::assertSame('nrllm_configurations', $vision->fixRoute());

        self::assertSame(ReadinessReason::NoGenericDefault, $service->row(ReadinessAction::Translation)->reason);
    }

    /**
     * Without a default nr-llm's generic call names no provider and is
     * refused, so this is measured, not left unchecked.
     */
    #[Test]
    public function visionIsNotReadyWithoutADefault(): void
    {
        $row = $this->service()->row(ReadinessAction::Vision);

        self::assertSame(ReadinessState::NotReady, $row->state);
        self::assertSame(ReadinessReason::NoGenericDefault, $row->reason);
    }

    #[Test]
    public function translationNamesAnAvailableSpecializedTranslator(): void
    {
        $this->translators = [
            'llm'   => ['identifier' => 'llm', 'name' => 'Language model', 'available' => true],
            'deepl' => ['identifier' => 'deepl', 'name' => 'DeepL', 'available' => true],
        ];
        $this->default = $this->usable('default');

        $row = $this->service()->row(ReadinessAction::Translation);

        self::assertSame(ReadinessState::Ready, $row->state);
        self::assertSame(ReadinessReason::TranslatorAvailable, $row->reason);
        self::assertSame(['DeepL'], $row->arguments);
    }

    #[Test]
    public function translationFallsBackToTheGenericDefaultWhenNoTranslatorIsAvailable(): void
    {
        $this->translators = [
            'llm'   => ['identifier' => 'llm', 'name' => 'Language model', 'available' => true],
            'deepl' => ['identifier' => 'deepl', 'name' => 'DeepL', 'available' => false],
        ];
        $this->default = $this->configuration('default', $this->model('GPT', $this->provider('OpenAI', 'openai', false)));

        $row = $this->service()->row(ReadinessAction::Translation);

        self::assertSame(ReadinessState::NotReady, $row->state);
        self::assertSame(ReadinessReason::ProviderKeyMissing, $row->reason);
    }

    /**
     * Pairs DeepL does not support go through the generic call; without a
     * default it can run, those pairs fail.
     */
    #[Test]
    public function translationIsPartlyReadyWhenTheOtherPairsHaveNoGenericDefault(): void
    {
        $this->translators = ['deepl' => ['identifier' => 'deepl', 'name' => 'DeepL', 'available' => true]];

        $row = $this->service()->row(ReadinessAction::Translation);

        self::assertSame(ReadinessState::Partly, $row->state);
        self::assertSame(ReadinessReason::TranslatorWithoutFallback, $row->reason);
        self::assertSame(['DeepL'], $row->arguments);
        self::assertSame('nrllm_configurations', $row->fixRoute());
    }

    #[Test]
    public function translationIsNotCheckedWhenTheGenericDefaultChoosesItsModelPerRequest(): void
    {
        $this->translators = ['deepl' => ['identifier' => 'deepl', 'name' => 'DeepL', 'available' => true]];
        $this->default     = $this->usable('default');
        $this->default->setModelSelectionMode('criteria');

        $row = $this->service()->row(ReadinessAction::Translation);

        self::assertSame(ReadinessState::NotChecked, $row->state);
        self::assertSame(ReadinessReason::TranslatorFallbackUnknown, $row->reason);
        GeneralUtility::purgeInstances();
    }

    /**
     * A default limited to other groups: the picker still offers the viewer
     * configurations that run, so writing works once they pick one.
     */
    #[Test]
    public function theDialogIsPartlyReadyWhenTheDefaultIsDeniedButThePickerOffersAUsableConfiguration(): void
    {
        $this->default             = $this->usable('default');
        $this->denied              = ['default'];
        $this->byIdentifier['own'] = $this->usable('own');
        $this->byIdentifier['bad'] = $this->unusable('bad');

        $row = $this->service()->row(ReadinessAction::Dialog);

        self::assertSame(ReadinessState::Partly, $row->state);
        self::assertSame(ReadinessReason::PickableReady, $row->reason);
        self::assertSame(['own'], $row->arguments);
        self::assertSame('nrllm_configurations', $row->fixRoute());
        GeneralUtility::purgeInstances();
    }

    #[Test]
    public function theDialogIsPartlyReadyWithoutADefaultWhenThePickerOffersAUsableConfiguration(): void
    {
        $this->byIdentifier['own'] = $this->usable('own');

        self::assertSame(ReadinessState::Partly, $this->service()->row(ReadinessAction::Dialog)->state);
        GeneralUtility::purgeInstances();
    }

    #[Test]
    public function theDialogKeepsTheDefaultsReasonWhenNothingPickableRuns(): void
    {
        $this->default             = $this->unusable('default');
        $this->byIdentifier['bad'] = $this->unusable('bad');

        $row = $this->service()->row(ReadinessAction::Dialog);

        self::assertSame(ReadinessState::NotReady, $row->state);
        self::assertSame(ReadinessReason::ProviderKeyMissing, $row->reason);
        GeneralUtility::purgeInstances();
    }

    #[Test]
    public function theDialogIsNotCheckedWhenOnlyAPickableConfigurationThatChoosesItsModelPerRequestRemains(): void
    {
        $this->denied               = ['default'];
        $this->default              = $this->usable('default');
        $this->byIdentifier['auto'] = $this->usable('auto');
        $this->byIdentifier['auto']->setModelSelectionMode('criteria');

        $row = $this->service()->row(ReadinessAction::Dialog);

        self::assertSame(ReadinessState::NotChecked, $row->state);
        self::assertSame(ReadinessReason::PickableNotChecked, $row->reason);
        self::assertSame(['auto'], $row->arguments);
        GeneralUtility::purgeInstances();
    }

    /**
     * No default, but every task names its own configuration: tasks run.
     */
    #[Test]
    public function tasksAreReadyWhenEveryTaskRunsOnItsOwnConfigurationWithoutADefault(): void
    {
        $this->tasks = [$this->task('Summarize', $this->usable('a')), $this->task('Shorten', $this->usable('b'))];

        $row = $this->service()->row(ReadinessAction::Tasks);

        self::assertSame(ReadinessState::Ready, $row->state);
        self::assertSame(ReadinessReason::TasksReady, $row->reason);
        self::assertSame(['2'], $row->arguments);
        GeneralUtility::purgeInstances();
    }

    /**
     * Only active tasks count; one without its own configuration falls back
     * to the default and fails with it.
     */
    #[Test]
    public function tasksArePartlyReadyAndNameTheTasksThatCannotRun(): void
    {
        $this->default = $this->unusable('default');
        $this->tasks   = [
            $this->task('Summarize', $this->usable('a')),
            $this->task('Shorten', null),
            $this->task('Retired', null, false),
        ];

        $row = $this->service()->row(ReadinessAction::Tasks);

        self::assertSame(ReadinessState::Partly, $row->state);
        self::assertSame(ReadinessReason::TasksFailing, $row->reason);
        self::assertSame(['1', '2', 'Shorten'], $row->arguments);
        self::assertSame('nrllm_tasks', $row->fixRoute());
        GeneralUtility::purgeInstances();
    }

    #[Test]
    public function tasksAreNotReadyWhenNoneCanRun(): void
    {
        $pinned       = $this->usable('pinned');
        $this->denied = ['pinned'];
        $this->tasks  = [$this->task('Summarize', $pinned), $this->task('Shorten', null)];

        $row = $this->service()->row(ReadinessAction::Tasks);

        self::assertSame(ReadinessState::NotReady, $row->state);
        self::assertSame(['2', '2', 'Summarize, Shorten'], $row->arguments);
        GeneralUtility::purgeInstances();
    }

    #[Test]
    public function manyTasksThatCannotRunAreCountedNotNamed(): void
    {
        // A usable default would be in the picker and rescue every task, so
        // the default cannot run here either.
        $this->default = $this->unusable('default');
        $this->tasks   = [$this->task('Plain', null)];
        for ($i = 1; $i <= ReadinessService::NAMED_FAILING_TASKS; ++$i) {
            $this->tasks[] = $this->task('Broken ' . $i, $this->unusable('broken' . $i));
        }

        $row = $this->service()->row(ReadinessAction::Tasks);

        self::assertSame(ReadinessState::NotReady, $row->state);
        self::assertSame(ReadinessReason::TasksFailingMany, $row->reason);
        self::assertSame(['4', '4'], $row->arguments);
        GeneralUtility::purgeInstances();
    }

    #[Test]
    public function tasksThatCanOnlyBeCheckedPerRequestAreNotChecked(): void
    {
        $auto = $this->usable('auto');
        $auto->setModelSelectionMode('criteria');

        $this->tasks = [$this->task('Summarize', $this->usable('a')), $this->task('Shorten', $auto)];

        $row = $this->service()->row(ReadinessAction::Tasks);

        self::assertSame(ReadinessState::NotChecked, $row->state);
        self::assertSame(ReadinessReason::TasksNotChecked, $row->reason);
        self::assertSame(['1', '2'], $row->arguments);
        GeneralUtility::purgeInstances();
    }

    #[Test]
    public function tasksAreOffWithoutAnActiveTask(): void
    {
        $this->tasks = [$this->task('Retired', $this->usable('a'), false)];

        $row = $this->service()->row(ReadinessAction::Tasks);

        self::assertSame(ReadinessState::Off, $row->state);
        self::assertSame(ReadinessReason::NoTasks, $row->reason);
        self::assertSame('nrllm_tasks', $row->fixRoute());
        GeneralUtility::purgeInstances();
    }

    #[Test]
    public function imageGenerationIsOffWithoutItsSwitch(): void
    {
        $row = $this->service()->row(ReadinessAction::Image);

        self::assertSame(ReadinessState::Off, $row->state);
        self::assertSame(ReadinessReason::SwitchedOff, $row->reason);
        self::assertSame('tools_toolssettings', $row->fixRoute());
    }

    #[Test]
    public function imageGenerationNeedsNrLlmsOpenAiKey(): void
    {
        $this->settings['image/enable'] = '1';

        $row = $this->service()->row(ReadinessAction::Image);

        self::assertSame(ReadinessState::NotReady, $row->state);
        self::assertSame(ReadinessReason::ImageKeyMissing, $row->reason);
    }

    #[Test]
    public function imageGenerationNeedsAnUploadFolder(): void
    {
        $this->settings['image/enable'] = '1';
        $this->openAiKeyStored          = true;
        $this->hasUploadFolder          = false;

        self::assertSame(ReadinessReason::NoUploadFolder, $this->service()->row(ReadinessAction::Image)->reason);
    }

    #[Test]
    public function imageGenerationIsReadyWithItsModel(): void
    {
        $this->settings['image/enable'] = '1';
        $this->settings['image/model']  = 'gpt-image-2';
        $this->openAiKeyStored          = true;

        $row = $this->service()->row(ReadinessAction::Image);

        self::assertSame(ReadinessState::Ready, $row->state);
        self::assertSame(['gpt-image-2'], $row->arguments);
    }

    #[Test]
    public function inlineSuggestionsAreOffWithoutTheirSwitch(): void
    {
        self::assertSame(ReadinessState::Off, $this->service()->row(ReadinessAction::InlineSuggestions)->state);
    }

    #[Test]
    public function inlineSuggestionsNeedTheirConfigurationToResolve(): void
    {
        $this->settings['inlineSuggestions/enable']        = '1';
        $this->settings['inlineSuggestions/configuration'] = 'fast';
        $this->default                                     = $this->configuration('default', $this->model('GPT', $this->provider('OpenAI', 'openai', true)));

        $row = $this->service()->row(ReadinessAction::InlineSuggestions);

        self::assertSame(ReadinessState::NotReady, $row->state);
        self::assertSame(ReadinessReason::ConfigurationNotFound, $row->reason);
        self::assertSame(['fast'], $row->arguments);
        GeneralUtility::purgeInstances();
    }

    #[Test]
    public function inlineSuggestionsRunOnTheirConfiguration(): void
    {
        $this->settings['inlineSuggestions/enable']        = '1';
        $this->settings['inlineSuggestions/configuration'] = 'fast';
        $this->byIdentifier['fast']                        = $this->configuration('fast', $this->model('Mini', $this->provider('OpenAI', 'openai', true)));

        $row = $this->service()->row(ReadinessAction::InlineSuggestions);

        self::assertSame(ReadinessState::Ready, $row->state);
        self::assertSame(['fast', 'Mini', 'OpenAI'], $row->arguments);
    }

    #[Test]
    public function fieldSuggestionsAreOffWithoutFields(): void
    {
        $this->settings['fieldSuggestions/fields'] = '';

        $row = $this->service()->row(ReadinessAction::FieldSuggestions);

        self::assertSame(ReadinessState::Off, $row->state);
        self::assertSame(ReadinessReason::NoSuggestionFields, $row->reason);
    }

    #[Test]
    public function fieldSuggestionsFollowTheDefaultPath(): void
    {
        $this->settings['fieldSuggestions/fields'] = 'default';

        self::assertSame(ReadinessReason::NoConfiguration, $this->service()->row(ReadinessAction::FieldSuggestions)->reason);
    }

    #[Test]
    public function presentationIsOffWithoutItsSwitch(): void
    {
        self::assertSame(ReadinessState::Off, $this->service()->row(ReadinessAction::Presentation)->state);
    }

    #[Test]
    public function presentationNeedsBootstrapPackage(): void
    {
        $this->settings['presentation/enable'] = '1';
        $GLOBALS['TCA']                        = [];

        $row = $this->service()->row(ReadinessAction::Presentation);

        self::assertSame(ReadinessState::NotReady, $row->state);
        self::assertSame(ReadinessReason::BootstrapPackageMissing, $row->reason);
    }

    #[Test]
    public function theVariantIsOffWithoutItsSwitch(): void
    {
        $row = $this->service()->row(ReadinessAction::Variant);

        self::assertSame(ReadinessState::Off, $row->state);
        self::assertSame(ReadinessReason::SwitchedOff, $row->reason);
    }

    #[Test]
    public function theVariantNeedsAnAudienceSnippet(): void
    {
        $this->settings['variant/enable'] = '1';
        $this->hasAudience                = false;

        $row = $this->service()->row(ReadinessAction::Variant);

        self::assertSame(ReadinessState::NotReady, $row->state);
        self::assertSame(ReadinessReason::NoAudienceSnippets, $row->reason);
        self::assertSame('nrllm_snippets', $row->reason->fixRoute());
    }

    #[Test]
    public function theVariantFollowsTheDefaultPath(): void
    {
        $this->settings['variant/enable'] = '1';

        self::assertSame(ReadinessReason::NoConfiguration, $this->service()->row(ReadinessAction::Variant)->reason);
    }

    #[Test]
    public function rowsListEveryActionOnce(): void
    {
        $GLOBALS['TCA'] = [];

        self::assertSame(
            ReadinessAction::cases(),
            array_map(static fn (ReadinessRow $row): ReadinessAction => $row->action, $this->service()->rows()),
        );
    }

    #[Test]
    public function aSiteThatNamesAnUnusableConfigurationGetsItsOwnRow(): void
    {
        $this->siteConfigurations = ['main' => 'gone', 'other' => ''];
        $GLOBALS['BE_USER']       = null;

        $rows = $this->service()->siteRows($this->viewer(true));

        self::assertCount(1, $rows);
        self::assertSame('main', $rows[0]->site);
        self::assertSame(ReadinessState::NotReady, $rows[0]->state);
        self::assertSame(ReadinessReason::SiteConfigurationUnusable, $rows[0]->reason);
        unset($GLOBALS['BE_USER']);
    }

    #[Test]
    public function aSiteThatNamesAUsableConfigurationIsReady(): void
    {
        $this->siteConfigurations   = ['main' => 'site'];
        $this->byIdentifier['site'] = $this->configuration('site', $this->model('GPT', $this->provider('OpenAI', 'openai', true)));
        $GLOBALS['BE_USER']         = null;

        $rows = $this->service()->siteRows($this->viewer(true));

        self::assertSame(ReadinessState::Ready, $rows[0]->state);
        self::assertSame(['site', 'GPT', 'OpenAI'], $rows[0]->arguments);
        unset($GLOBALS['BE_USER']);
    }

    /**
     * A site row is the viewer's: a configuration the site names but the
     * viewer may not use is refused for them.
     */
    #[Test]
    public function aSiteRowIsEvaluatedWithTheViewersAccess(): void
    {
        $this->siteConfigurations   = ['main' => 'site'];
        $this->byIdentifier['site'] = $this->usable('site');
        $this->denied               = ['site'];
        $GLOBALS['BE_USER']         = null;

        $rows = $this->service()->siteRows($this->viewer(true));

        self::assertSame(ReadinessAction::Tasks, $rows[0]->action);
        self::assertSame(ReadinessState::NotReady, $rows[0]->state);
        self::assertSame(ReadinessReason::ConfigurationDenied, $rows[0]->reason);
        unset($GLOBALS['BE_USER']);
    }

    /**
     * An editor whose web mounts do not reach a site's root gets no row for
     * it; the configuration it names is not shown either.
     */
    #[Test]
    public function anEditorGetsNoRowForASiteOutsideTheirWebMounts(): void
    {
        $this->siteConfigurations = ['main' => 'gone', 'other' => 'gone'];
        $GLOBALS['BE_USER']       = null;

        $rows = $this->service()->siteRows($this->viewer(false, [2]));

        self::assertCount(1, $rows);
        self::assertSame('other', $rows[0]->site);
        unset($GLOBALS['BE_USER']);
    }

    /**
     * A mount below a site root is inside that site; a mount on a page of no
     * site reaches none.
     */
    #[Test]
    public function anEditorMountedBelowASiteRootGetsThatSitesRow(): void
    {
        $this->siteConfigurations = ['main' => 'gone', 'other' => 'gone'];
        $GLOBALS['BE_USER']       = null;

        $rows = $this->service()->siteRows($this->viewer(false, [], [11, 9]));

        self::assertSame(['main'], array_map(static fn (ReadinessRow $row): string => $row->site, $rows));
        unset($GLOBALS['BE_USER']);
    }

    /**
     * A configuration picked in the dialog wins over a task's own one, so the
     * failing tasks still run once the editor picks a ready one.
     */
    #[Test]
    public function failingTasksArePartlyReadyWhenThePickerOffersAReadyConfiguration(): void
    {
        $this->default             = $this->unusable('default');
        $this->byIdentifier['own'] = $this->usable('own');
        $this->tasks               = [$this->task('Summarize', $this->default), $this->task('Shorten', null)];

        $row = $this->service()->row(ReadinessAction::Tasks);

        self::assertSame(ReadinessState::Partly, $row->state);
        self::assertSame(ReadinessReason::TasksFailingPickable, $row->reason);
        self::assertSame(['2', '2', 'own'], $row->arguments);
        self::assertSame('nrllm_tasks', $row->fixRoute());
        GeneralUtility::purgeInstances();
    }

    /**
     * @param list<int> $mountedRoots site roots the viewer is mounted on
     * @param list<int> $mountedBelow pages below a root (10 + root uid) the viewer is mounted on
     */
    private function viewer(bool $admin, array $mountedRoots = [], array $mountedBelow = []): BackendUserAuthentication
    {
        $viewer = $this->createStub(BackendUserAuthentication::class);
        $viewer->method('isAdmin')->willReturn($admin);
        $viewer->method('isInWebMount')->willReturnCallback(
            static fn (mixed $id): ?int => in_array($id, $mountedRoots, true) ? $id : null,
        );
        $viewer->method('getWebmounts')->willReturn(array_merge($mountedRoots, $mountedBelow));

        return $viewer;
    }

    private function service(): ReadinessService
    {
        $repository = $this->createStub(LlmConfigurationRepository::class);
        $repository->method('findDefault')->willReturnCallback(fn (): ?LlmConfiguration => $this->default);
        $repository->method('findOneByIdentifier')->willReturnCallback(
            fn (string $identifier): ?LlmConfiguration => $this->byIdentifier[$identifier] ?? null,
        );

        $access = $this->createStub(LlmConfigurationServiceInterface::class);
        $access->method('hasAccess')->willReturnCallback($this->mayUse(...));
        $access->method('getAccessibleConfigurations')->willReturnCallback(
            fn (): array => array_values(array_filter([$this->default, ...array_values($this->byIdentifier)], fn (?LlmConfiguration $configuration): bool => $configuration instanceof LlmConfiguration && $this->mayUse($configuration))),
        );

        $taskRepository = $this->createStub(TaskRepository::class);
        $taskRepository->method('findByCategory')->willReturnCallback(
            fn (string $category): TestQueryResult => new TestQueryResult($category === 'content' ? $this->tasks : []),
        );

        $sites = [];
        foreach (array_keys($this->siteConfigurations) as $identifier) {
            $site = $this->createStub(Site::class);
            $site->method('getIdentifier')->willReturn($identifier);
            $site->method('getRootPageId')->willReturn($identifier === 'main' ? 1 : 2);
            $site->method('getSettings')->willReturn(SiteSettings::createFromSettingsTree(['nrllm' => ['configuration' => $this->siteConfigurations[$identifier]]]));
            $sites[$identifier] = $site;
        }

        $siteFinder = $this->createStub(SiteFinder::class);
        $siteFinder->method('getAllSites')->willReturn($sites);
        $siteFinder->method('getSiteByIdentifier')->willReturnCallback(static fn (string $identifier): Site => $sites[$identifier]);
        // Page 1 and 11 lie in "main", 2 and 12 in "other".
        $siteFinder->method('getSiteByPageId')->willReturnCallback(
            static function (int $pageUid) use ($sites): Site {
                $identifier = match ($pageUid % 10) {
                    1       => 'main',
                    2       => 'other',
                    default => '',
                };
                if (!isset($sites[$identifier])) {
                    throw new SiteNotFoundException('No site for page ' . $pageUid, 1730700000);
                }

                return $sites[$identifier];
            },
        );

        $siteSettings = new SiteLlmSettings($siteFinder);
        $resolver     = new ConfigurationResolver($repository, $siteSettings);

        $translation = $this->createStub(TranslationServiceInterface::class);
        $translation->method('getAvailableTranslators')->willReturnCallback(fn (): array => $this->translators);

        $storage = $this->createStub(GeneratedImageStorageInterface::class);
        $storage->method('targetFolder')->willReturnCallback(fn (): ?Folder => $this->hasUploadFolder ? $this->createStub(Folder::class) : null);

        $extensionConfiguration = $this->extensionConfiguration();
        $snippetRepository      = $this->createStub(PromptSnippetRepository::class);
        $snippetRepository->method('findActiveByTag')->willReturnCallback(
            fn (string $tag): array => $tag === 'audience' && $this->hasAudience ? [$this->createStub(PromptSnippet::class)] : [],
        );

        return new ReadinessService(
            new ConfigurationSelector($repository, $access, $resolver),
            $resolver,
            $translation,
            $this->imageService($extensionConfiguration),
            $storage,
            new ImageGenerationConfiguration($extensionConfiguration),
            new InlineSuggestionConfiguration($extensionConfiguration),
            new FieldSuggestionConfiguration($extensionConfiguration),
            new PresentationConfiguration($extensionConfiguration),
            $siteFinder,
            $taskRepository,
            new VariantConfiguration($extensionConfiguration),
            $snippetRepository,
        );
    }

    private function mayUse(LlmConfiguration $configuration): bool
    {
        return $this->hasAccess && !in_array($configuration->getIdentifier(), $this->denied, true);
    }

    private function task(string $name, ?LlmConfiguration $configuration, bool $active = true): Task
    {
        $task = new Task();
        $task->setName($name);
        $task->setCategory('content');
        $task->setConfiguration($configuration);
        $task->setIsActive($active);

        return $task;
    }

    private function usable(string $identifier): LlmConfiguration
    {
        return $this->configuration($identifier, $this->model('GPT', $this->provider('Local', 'ollama', false)));
    }

    private function unusable(string $identifier): LlmConfiguration
    {
        return $this->configuration($identifier, $this->model('GPT', $this->provider('Keyless', 'openai', false)));
    }

    private function extensionConfiguration(): ExtensionConfiguration
    {
        $configuration = $this->createStub(ExtensionConfiguration::class);
        $configuration->method('get')->willReturnCallback(
            fn (string $extension, string $path = ''): mixed => $extension === 'nr_llm'
                ? ['providers' => ['openai' => ['apiKeyIdentifier' => 'openaiKey']]]
                : ($this->settings[$path] ?? null),
        );

        return $configuration;
    }

    private function imageService(ExtensionConfiguration $extensionConfiguration): DallEImageService
    {
        $vault = $this->createStub(VaultServiceInterface::class);
        $vault->method('exists')->willReturnCallback(fn (): bool => $this->openAiKeyStored);

        return new DallEImageService(
            $vault,
            $this->createStub(RequestFactoryInterface::class),
            $this->createStub(StreamFactoryInterface::class),
            $extensionConfiguration,
            $this->createStub(UsageTrackerServiceInterface::class),
            new NullLogger(),
            $this->createStub(SpecializedCostCalculatorInterface::class),
            $this->createStub(BudgetServiceInterface::class),
            new MiddlewarePipeline([]),
            new InputGuardrailScreener([]),
        );
    }

    /**
     * A provider whose key, when it has one, is a vault identifier the vault
     * resolves: Provider::hasApiKey() reads it through GeneralUtility.
     */
    private function provider(string $name, string $adapter, bool $withKey): Provider
    {
        $provider = new Provider();
        $provider->setName($name);
        $provider->setIdentifier(strtolower($name));
        $provider->setAdapterType($adapter);
        $provider->setIsActive(true);
        if ($withKey) {
            $provider->setApiKey('providerKey' . $name);
            $vault = $this->createStub(VaultServiceInterface::class);
            $vault->method('retrieve')->willReturn('secret');
            GeneralUtility::addInstance(VaultServiceInterface::class, $vault);
        }

        return $provider;
    }

    /**
     * @param list<string> $capabilities
     */
    private function model(string $name, ?Provider $provider, array $capabilities = ['chat']): Model
    {
        $model = new Model();
        $model->setName($name);
        $model->setIdentifier(strtolower($name));
        $model->setIsActive(true);
        $model->setProvider($provider);
        $model->setCapabilitiesArray($capabilities);

        return $model;
    }

    private function configuration(string $identifier, Model $model): LlmConfiguration
    {
        $configuration = new LlmConfiguration();
        $configuration->setIdentifier($identifier);
        $configuration->setName($identifier);
        $configuration->setIsActive(true);
        $configuration->setLlmModel($model);

        return $configuration;
    }

    /**
     * @param list<Provider> $providers
     */
    private function diagnostics(array $providers): DiagnosticService
    {
        $providerRepository = $this->createStub(ProviderRepository::class);
        $providerRepository->method('findAll')->willReturn(new TestQueryResult($providers));
        $providerRepository->method('countActive')->willReturn(count($providers));
        $providerRepository->method('findActive')->willReturn(new TestQueryResult($providers));

        $modelRepository = $this->createStub(ModelRepository::class);
        $modelRepository->method('findAll')->willReturn(new TestQueryResult([new Model()]));
        $modelRepository->method('countActive')->willReturn(1);

        $configurationRepository = $this->createStub(LlmConfigurationRepository::class);
        $configurationRepository->method('findAll')->willReturn(new TestQueryResult([$this->default]));
        $configurationRepository->method('countActive')->willReturn(1);
        $configurationRepository->method('findDefault')->willReturn($this->default);

        return new DiagnosticService($providerRepository, $modelRepository, $configurationRepository);
    }
}
