<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\T3Cowriter\Tests\Functional\Overview;

use Netresearch\T3Cowriter\Controller\Backend\OverviewController;
use Netresearch\T3Cowriter\Service\Overview\ReadinessAction;
use Netresearch\T3Cowriter\Service\Overview\ReadinessReason;
use Netresearch\T3Cowriter\Service\Overview\ReadinessRow;
use Netresearch\T3Cowriter\Service\Overview\ReadinessState;
use Netresearch\T3Cowriter\Service\Overview\UsageOperation;
use Netresearch\T3Cowriter\Service\Overview\UsageOverview;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use TYPO3\CMS\Backend\Module\ModuleProvider;
use TYPO3\CMS\Backend\Routing\Router;
use TYPO3\CMS\Core\Configuration\SiteWriter;
use TYPO3\CMS\Core\Core\SystemEnvironmentBuilder;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Http\NormalizedParams;
use TYPO3\CMS\Core\Http\ServerRequest;
use TYPO3\CMS\Core\Localization\LanguageServiceFactory;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;

/**
 * The overview as the container builds it, on real nr-llm records and nr-llm's
 * real usage reader. Every figure has rows beside it that must not count:
 * another extension's, another user's, and the day before the period.
 */
#[CoversClass(OverviewController::class)]
final class OverviewTest extends FunctionalTestCase
{
    protected array $coreExtensionsToLoad = ['rte_ckeditor', 'install'];

    protected array $testExtensionsToLoad = [
        'netresearch/nr-vault',
        'netresearch/nr-llm',
        'netresearch/t3-cowriter',
    ];

    protected array $configurationToUseInTestInstance = [
        'SYS' => ['systemMaintainers' => [1]],
    ];

    /** 12:00 on the period's last day, in the instance's time zone. */
    private int $now = 0;

    protected function setUp(): void
    {
        parent::setUp();
        $this->importCSVDataSet(__DIR__ . '/Fixtures/overview.csv');

        // A site that names the group-restricted default as its configuration.
        $siteWriter = $this->get(SiteWriter::class);
        self::assertInstanceOf(SiteWriter::class, $siteWriter);
        $siteWriter->write('main', [
            'rootPageId' => 1,
            'base'       => 'https://example.com/',
            'languages'  => [['languageId' => 0, 'title' => 'English', 'locale' => 'en_US.UTF-8', 'base' => '/']],
        ]);
        $siteWriter->writeSettings('main', ['nrllm' => ['configuration' => 'default']]);

        $this->now = strtotime('2026-06-30 12:00:00');

        // A seven-day period starts on 2026-06-24 at 00:00.
        $this->usage('2026-06-24', 't3_cowriter', 3, 2, 200, 0.25);
        $this->usage('2026-06-30', 't3_cowriter', 4, 3, 300, 0.5);
        $this->usage('2026-06-25', 'nr_mcp_agent', 3, 50, 5000, 9.0);
        $this->usage('2026-06-23', 't3_cowriter', 3, 40, 4000, 4.0);

        $this->call('2026-06-24 00:00:00', 't3_cowriter', 'chat', 3, true);
        $this->call('2026-06-26 10:00:00', 't3_cowriter', 'translate', 3, false);
        $this->call('2026-06-27 10:00:00', 't3_cowriter', 'translate', 4, true);
        $this->call('2026-06-27 10:00:00', 't3_cowriter', 'improve', 4, false);
        $this->call('2026-06-27 11:00:00', 'nr_mcp_agent', 'chat', 3, false);
        $this->call('2026-06-23 23:59:59', 't3_cowriter', 'chat', 3, false);
    }

    #[Test]
    public function anAdministratorSeesTheInstallationsCowriterUsage(): void
    {
        $usage = $this->variables(1)['usage'];
        self::assertInstanceOf(UsageOverview::class, $usage);

        self::assertFalse($usage->ownOnly);
        self::assertSame('2026-06-24 00:00', date('Y-m-d H:i', $usage->from));
        self::assertSame(5, $usage->requests);
        self::assertSame(500, $usage->tokens);
        self::assertEqualsWithDelta(0.75, $usage->estimatedCost, 0.0001);
        self::assertSame(['chat' => [1, 0], 'improve' => [1, 1], 'translate' => [2, 1]], $this->perOperation($usage));
        self::assertSame(4, $usage->callCount());
        self::assertSame(2, $usage->failedCount());
    }

    #[Test]
    public function anEditorSeesOnlyTheirOwnCowriterUsage(): void
    {
        $usage = $this->variables(3)['usage'];
        self::assertInstanceOf(UsageOverview::class, $usage);

        self::assertTrue($usage->ownOnly);
        self::assertSame(2, $usage->requests);
        self::assertSame(200, $usage->tokens);
        self::assertEqualsWithDelta(0.25, $usage->estimatedCost, 0.0001);
        self::assertSame(['chat' => [1, 0], 'translate' => [1, 1]], $this->perOperation($usage));
    }

    /**
     * The default's provider has no key, so writing without a pick fails; the
     * picker offers the pilot "Local", which runs.
     */
    #[Test]
    public function theDialogIsPartlyReadyWhenOnlyAPickableConfigurationRuns(): void
    {
        $dialog = $this->row($this->variables(3), ReadinessAction::Dialog);

        self::assertSame(ReadinessState::Partly, $dialog['row']->state);
        self::assertSame(ReadinessReason::PickableReady, $dialog['row']->reason);
        self::assertSame(['Local'], $dialog['row']->arguments);
        // The editor may not open the configuration list.
        self::assertNull($dialog['fixUrl']);
        self::assertTrue($dialog['askAdministrator']);
    }

    /**
     * Outside the pilots' group neither the default nor "Local" is theirs.
     * The picker offers them "Claude", whose provider's adapter this check
     * does not know: not checked, never ready.
     */
    #[Test]
    public function anEditorOutsideTheDefaultsGroupsIsOfferedOnlyWhatCannotBeChecked(): void
    {
        $dialog = $this->row($this->variables(4), ReadinessAction::Dialog);

        self::assertSame(ReadinessState::NotChecked, $dialog['row']->state);
        self::assertSame(ReadinessReason::PickableNotChecked, $dialog['row']->reason);
        self::assertSame(['Claude'], $dialog['row']->arguments);
    }

    /**
     * "Summarize" runs on its own configuration; "Shorten" has none and takes
     * the default, which fails for both editors. The pilot may pick "Local" in
     * the dialog, which then replaces the default; the outsider is offered
     * nothing that runs. The inactive task does not count.
     */
    #[Test]
    public function tasksAreCountedWithTheViewersAccess(): void
    {
        $pilot = $this->row($this->variables(3), ReadinessAction::Tasks);
        self::assertSame(ReadinessState::Partly, $pilot['row']->state);
        self::assertSame(ReadinessReason::TasksFailingPickable, $pilot['row']->reason);
        self::assertSame(['1', '2', 'Local'], $pilot['row']->arguments);
        self::assertTrue($pilot['askAdministrator']);

        $outsider = $this->row($this->variables(4), ReadinessAction::Tasks);
        self::assertSame(ReadinessState::NotReady, $outsider['row']->state);
        self::assertSame(ReadinessReason::TasksFailing, $outsider['row']->reason);
        self::assertSame(['2', '2', 'Summarize, Shorten'], $outsider['row']->arguments);
    }

    /**
     * Both tasks take the default, whose provider has no key. A configuration
     * the editor picks in the dialog wins over the task's own one, so the
     * tasks still run once the pilot picks "Local": partly ready, not "none".
     */
    #[Test]
    public function tasksThatAllFailOnTheirOwnSettingRunWithAPickableConfiguration(): void
    {
        $this->get(ConnectionPool::class)->getConnectionForTable('tx_nrllm_task')
            ->update('tx_nrllm_task', ['configuration_uid' => 200], ['uid' => 300]);

        $pilot = $this->row($this->variables(3), ReadinessAction::Tasks);

        self::assertSame(ReadinessState::Partly, $pilot['row']->state);
        self::assertSame(ReadinessReason::TasksFailingPickable, $pilot['row']->reason);
        self::assertSame(['2', '2', 'Local'], $pilot['row']->arguments);
    }

    /**
     * An editor mounted only in another site's tree does not see the rows of
     * a site they cannot reach, nor the configuration names behind it. An
     * administrator sees every site.
     */
    #[Test]
    public function anEditorSeesOnlyTheSitesOfTheirWebMounts(): void
    {
        self::assertSame([], $this->variables(5)['siteReadiness']);
        self::assertCount(1, $this->variables(1)['siteReadiness']);
    }

    /**
     * An editor sees the site that names its own configuration, checked with
     * their own access.
     */
    #[Test]
    public function anEditorSeesTheSiteRowsCheckedWithTheirAccess(): void
    {
        $pilot = $this->variables(3)['siteReadiness'];
        self::assertCount(1, $pilot);
        self::assertSame('main', $pilot[0]['row']->site);
        self::assertSame(ReadinessReason::ProviderKeyMissing, $pilot[0]['row']->reason);

        $outsider = $this->variables(4)['siteReadiness'];
        self::assertCount(1, $outsider);
        self::assertSame(ReadinessState::NotReady, $outsider[0]['row']->state);
        self::assertSame(ReadinessReason::ConfigurationDenied, $outsider[0]['row']->reason);
    }

    /**
     * nr-llm's generic call refuses a group-restricted default, so image
     * descriptions cannot run even for a member of the group.
     */
    #[Test]
    public function imageDescriptionsNeedADefaultWithoutGroupRestriction(): void
    {
        $vision = $this->row($this->variables(3), ReadinessAction::Vision);

        self::assertSame(ReadinessState::NotReady, $vision['row']->state);
        self::assertSame(ReadinessReason::NoGenericDefault, $vision['row']->reason);
    }

    #[Test]
    public function anAdministratorGetsFixLinksSettingsAndEveryModuleLink(): void
    {
        $variables = $this->variables(1);

        self::assertSame('/typo3/module/nrllm/tasks', $this->path($this->row($variables, ReadinessAction::Tasks)['fixUrl']));
        self::assertNotSame([], $variables['settings']);
        self::assertSame(0, $variables['promptsAwaiting']);
        self::assertSame(OverviewController::ACTION_MODULES, array_column($variables['actions'], 'module'));
    }

    /**
     * An editor gets a link only to what their group grants: of the linked
     * modules that is the AI tasks module alone. No settings, no prompt count.
     */
    #[Test]
    public function anEditorGetsOnlyTheLinksTheyMayOpen(): void
    {
        $variables = $this->variables(3);

        self::assertSame(['nrllm_aitasks'], array_column($variables['actions'], 'module'));
        self::assertSame([], $variables['settings']);
        self::assertNull($variables['promptsAwaiting']);
        self::assertNull($variables['promptsUrl']);
        self::assertNull($variables['providersUrl']);
        self::assertNull($variables['siteReadiness'][0]['fixUrl']);

        self::assertSame([], array_column($this->variables(4)['actions'], 'module'));
    }

    /**
     * The page renders on this TYPO3 version with one h1, as an editor
     * without other people's names, and with the settings only for an
     * administrator.
     */
    #[Test]
    public function thePageRendersForAnEditorAndAnAdministrator(): void
    {
        $editor = $this->render(3);
        self::assertSame(1, substr_count($editor, '<h1'));
        self::assertStringContainsString('cowriter-readiness', $editor);
        self::assertStringNotContainsString('cowriter-configuration', $editor);
        self::assertStringNotContainsString('Otto Outsider', $editor);
        self::assertStringNotContainsString('Ada Admin', $editor);
        self::assertStringContainsString('name="period"', $editor);

        $admin = $this->render(1);
        self::assertSame(1, substr_count($admin, '<h1'));
        self::assertStringContainsString('cowriter-configuration', $admin);
        self::assertStringContainsString('fieldSuggestions.fields', $admin);
    }

    /**
     * @return array<string, mixed>
     */
    private function variables(int $userUid): array
    {
        $user = $this->setUpBackendUser($userUid);

        return $this->controller()->variables(
            $this->request()->withQueryParams(['period' => '7']),
            $user,
            $this->now,
        );
    }

    private function render(int $userUid): string
    {
        $user                     = $this->setUpBackendUser($userUid);
        $GLOBALS['LANG']          = $this->get(LanguageServiceFactory::class)->createFromUserPreferences($user);
        $request                  = $this->request();
        $GLOBALS['TYPO3_REQUEST'] = $request;

        return (string) $this->controller()->indexAction($request)->getBody();
    }

    private function request(): ServerRequest
    {
        $request = (new ServerRequest('https://example.com/typo3/module/cowriter/status', 'GET'))
            ->withAttribute('applicationType', SystemEnvironmentBuilder::REQUESTTYPE_BE)
            ->withAttribute('route', $this->get(Router::class)->getRoute('cowriter_status'));
        $request = $request->withAttribute('normalizedParams', NormalizedParams::createFromRequest($request));

        $module = $this->get(ModuleProvider::class)->getModule('cowriter_status', $GLOBALS['BE_USER']);

        return $request->withAttribute('module', $module);
    }

    private function controller(): OverviewController
    {
        $controller = $this->get(OverviewController::class);
        self::assertInstanceOf(OverviewController::class, $controller);

        return $controller;
    }

    /**
     * @param array<string, mixed> $variables
     *
     * @return array{row: ReadinessRow, fixUrl: ?string, askAdministrator: bool}
     */
    private function row(array $variables, ReadinessAction $action): array
    {
        foreach ($variables['readiness'] as $entry) {
            if ($entry['row']->action === $action) {
                return $entry;
            }
        }

        self::fail('No readiness row for ' . $action->value);
    }

    private function path(?string $url): string
    {
        return (string) parse_url((string) $url, PHP_URL_PATH);
    }

    /**
     * @return array<string, array{int, int}> operation => [calls, failed]
     */
    private function perOperation(UsageOverview $usage): array
    {
        $result = [];
        foreach ($usage->operations as $operation) {
            self::assertInstanceOf(UsageOperation::class, $operation);
            $result[$operation->operation] = [$operation->calls, $operation->failedCalls];
        }

        ksort($result);

        return $result;
    }

    private function usage(string $day, string $extension, int $beUser, int $requests, int $tokens, float $cost): void
    {
        $midnight = (int) strtotime($day . ' 00:00:00');
        $this->get(ConnectionPool::class)->getConnectionForTable('tx_nrllm_service_usage')->insert('tx_nrllm_service_usage', [
            'pid'              => 0,
            'service_type'     => 'chat',
            'service_provider' => 'openai',
            'model_id'         => 'gpt-4o',
            'source_extension' => $extension,
            'be_user'          => $beUser,
            'request_count'    => $requests,
            'tokens_used'      => $tokens,
            'estimated_cost'   => $cost,
            'request_date'     => $midnight,
            'tstamp'           => $midnight,
            'crdate'           => $midnight,
        ]);
    }

    private function call(string $time, string $extension, string $operation, int $beUser, bool $success): void
    {
        $this->get(ConnectionPool::class)->getConnectionForTable('tx_nrllm_telemetry')->insert('tx_nrllm_telemetry', [
            'pid'              => 0,
            'operation'        => 'chat',
            'provider'         => 'openai',
            'be_user'          => $beUser,
            'source_extension' => $extension,
            'source_operation' => $operation,
            'success'          => $success ? 1 : 0,
            'crdate'           => (int) strtotime($time),
        ]);
    }
}
