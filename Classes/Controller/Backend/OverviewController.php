<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\T3Cowriter\Controller\Backend;

use Netresearch\NrLlm\Domain\ValueObject\UserBudgetStatus;
use Netresearch\NrLlm\Service\Budget\UserBudgetStatusService;
use Netresearch\NrLlm\Service\Formatting\LocalizedNumberFormatter;
use Netresearch\T3Cowriter\Service\Overview\EditorSurfaces;
use Netresearch\T3Cowriter\Service\Overview\ExtensionSettings;
use Netresearch\T3Cowriter\Service\Overview\ReadinessRow;
use Netresearch\T3Cowriter\Service\Overview\ReadinessService;
use Netresearch\T3Cowriter\Service\Overview\ReadinessState;
use Netresearch\T3Cowriter\Service\Overview\UsageOverview;
use Netresearch\T3Cowriter\Service\Overview\UsageService;
use Netresearch\T3Cowriter\Service\Prompt\SavedPromptRepository;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;
use Throwable;
use TYPO3\CMS\Backend\Module\ModuleInterface;
use TYPO3\CMS\Backend\Module\ModuleProvider;
use TYPO3\CMS\Backend\Routing\UriBuilder as BackendUriBuilder;
use TYPO3\CMS\Backend\Template\ModuleTemplateFactory;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;

/**
 * The cowriter's overview module: what it is and where editors find it,
 * whether each action can run for the viewer, how much it was used, the
 * extension settings, and links to where it is set up.
 *
 * Every viewer sees their own readiness, including the per-site rows, usage
 * and budget. Administrators see the installation's usage and the extension
 * settings; a link is rendered only when the viewer may open its module.
 *
 * @internal
 */
final readonly class OverviewController
{
    /**
     * Modules the actions section links to, in this order.
     */
    public const ACTION_MODULES = [
        'nrllm_aitasks', 'nrllm_configurations', 'nrllm_tasks', 'nrllm_models', 'nrllm_providers', 'nrllm_analytics',
        'tools_toolssettings',
    ];

    public const DOCUMENTATION_URL = 'https://docs.typo3.org/p/netresearch/t3-cowriter/main/en-us/Usage/Overview.html';

    public function __construct(
        private ModuleTemplateFactory $moduleTemplateFactory,
        private BackendUriBuilder $uriBuilder,
        private ModuleProvider $moduleProvider,
        private ReadinessService $readiness,
        private UsageService $usage,
        private EditorSurfaces $surfaces,
        private ExtensionSettings $settings,
        private SavedPromptRepository $prompts,
        private UserBudgetStatusService $budgetStatus,
        private LoggerInterface $logger,
    ) {}

    public function indexAction(ServerRequestInterface $request): ResponseInterface
    {
        $user = $GLOBALS['BE_USER'] ?? null;
        if (!$user instanceof BackendUserAuthentication) {
            throw new RuntimeException('The cowriter overview needs a backend user.', 1790200001);
        }

        $moduleTemplate = $this->moduleTemplateFactory->create($request);
        $moduleTemplate->assignMultiple($this->variables($request, $user, time()));

        return $moduleTemplate->renderResponse('Backend/Overview/Index');
    }

    /**
     * @return array<string, mixed>
     */
    public function variables(ServerRequestInterface $request, BackendUserAuthentication $user, int $now): array
    {
        $isAdmin   = $user->isAdmin();
        $userId    = is_numeric($user->user['uid'] ?? null) ? (int) $user->user['uid'] : 0;
        $days      = UsageService::period($request->getQueryParams()['period'] ?? null);
        $formatter = LocalizedNumberFormatter::forBackendUser();
        $usage     = $this->usage($userId, $isAdmin, $days, $now);

        return [
            'isAdmin'       => $isAdmin,
            'surfaces'      => $this->surfaces->all(),
            'readiness'     => $this->readinessRows($this->readiness->rows(), $user),
            'siteReadiness' => $this->readinessRows($this->readiness->siteRows(), $user),
            'providersUrl'  => $this->moduleUrl('nrllm_providers', $user),
            'usage'         => $usage,
            'usageFigures'  => $usage instanceof UsageOverview ? [
                'cost'     => $formatter->usd($usage->estimatedCost),
                'requests' => $formatter->number($usage->requests),
                'tokens'   => $formatter->number($usage->tokens),
                'calls'    => $formatter->number($usage->callCount()),
                'failed'   => $formatter->number($usage->failedCount()),
            ] : [],
            'period'           => $days,
            'periods'          => UsageService::PERIODS,
            'periodForm'       => $this->periodForm(),
            'budget'           => $this->budget($userId, $formatter),
            'settings'         => $isAdmin ? $this->settings->all() : [],
            'promptsAwaiting'  => $isAdmin ? $this->promptsAwaiting() : null,
            'promptsUrl'       => $isAdmin ? $this->moduleUrl('web_list', $user, ['id' => 0, 'table' => SavedPromptRepository::TABLE]) : null,
            'actions'          => $this->actions($user),
            'documentationUrl' => self::DOCUMENTATION_URL,
        ];
    }

    /**
     * @param list<ReadinessRow> $rows
     *
     * @return list<array{row: ReadinessRow, icon: string, badge: string, fixUrl: ?string, askAdministrator: bool}>
     */
    private function readinessRows(array $rows, BackendUserAuthentication $user): array
    {
        $result = [];
        foreach ($rows as $row) {
            $route    = $row->fixRoute();
            $fixUrl   = $route !== null ? $this->moduleUrl($route, $user) : null;
            $result[] = [
                'row'    => $row,
                'icon'   => $row->state->icon(),
                'badge'  => $row->state->badge(),
                'fixUrl' => $fixUrl,
                // An editor cannot open anything that fixes it.
                'askAdministrator' => $fixUrl === null && in_array($row->state, [ReadinessState::NotReady, ReadinessState::Partly], true) && !$user->isAdmin(),
            ];
        }

        return $result;
    }

    private function usage(int $userId, bool $isAdmin, int $days, int $now): ?UsageOverview
    {
        try {
            return $this->usage->forViewer($userId, $isAdmin, $days, $now);
        } catch (Throwable $e) {
            $this->logger->error('Cowriter overview: the usage could not be read', ['exception' => $e->getMessage()]);

            return null;
        }
    }

    private function budget(int $userId, LocalizedNumberFormatter $formatter): ?UserBudgetStatus
    {
        try {
            return $this->budgetStatus->forUser($userId, $formatter);
        } catch (Throwable $e) {
            $this->logger->error('Cowriter overview: the budget could not be read', ['exception' => $e->getMessage()]);

            return null;
        }
    }

    private function promptsAwaiting(): ?int
    {
        try {
            return $this->prompts->countAwaitingApproval();
        } catch (Throwable $e) {
            $this->logger->error('Cowriter overview: shared prompts could not be counted', ['exception' => $e->getMessage()]);

            return null;
        }
    }

    /**
     * The module's own URL split for a GET form: a browser drops the query of
     * a GET form's action, so its parameters travel as hidden fields.
     *
     * @return array{action: string, hidden: array<string, string>}
     */
    private function periodForm(): array
    {
        $uri = $this->uriBuilder->buildUriFromRoute('cowriter_status');
        parse_str($uri->getQuery(), $query);

        $hidden = [];
        foreach ($query as $name => $value) {
            if (is_string($value) && $name !== 'period') {
                $hidden[(string) $name] = $value;
            }
        }

        return ['action' => (string) $uri->withQuery(''), 'hidden' => $hidden];
    }

    /**
     * @return list<array{module: string, url: string}>
     */
    private function actions(BackendUserAuthentication $user): array
    {
        $actions = [];
        foreach (self::ACTION_MODULES as $module) {
            $url = $this->moduleUrl($module, $user);
            if ($url !== null) {
                $actions[] = ['module' => $module, 'url' => $url];
            }
        }

        return $actions;
    }

    /**
     * The URL of a module the user may open; null when the module does not
     * exist or the user lacks access to it.
     *
     * @param array<string, int|string> $parameters
     */
    private function moduleUrl(string $identifier, BackendUserAuthentication $user, array $parameters = []): ?string
    {
        try {
            $module = $this->moduleProvider->accessGranted($identifier, $user)
                ? $this->moduleProvider->getModule($identifier, $user)
                : null;
            if (!$module instanceof ModuleInterface) {
                return null;
            }

            return (string) $this->uriBuilder->buildUriFromRoute($module->getIdentifier(), $parameters);
        } catch (Throwable) {
            return null;
        }
    }
}
