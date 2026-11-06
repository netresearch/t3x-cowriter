<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\T3Cowriter\Controller;

use Netresearch\NrLlm\Domain\DTO\BudgetCheckResult;
use Netresearch\NrLlm\Domain\Model\LlmConfiguration;
use Netresearch\T3Cowriter\Domain\DTO\ExecuteTaskRequest;
use Netresearch\T3Cowriter\Domain\DTO\VariantCreateRequest;
use Netresearch\T3Cowriter\Domain\DTO\VariantProposeRequest;
use Netresearch\T3Cowriter\Domain\DTO\VariantShowRequest;
use Netresearch\T3Cowriter\Service\BackendLabels;
use Netresearch\T3Cowriter\Service\BudgetRefusal;
use Netresearch\T3Cowriter\Service\ConfigurationSelector;
use Netresearch\T3Cowriter\Service\LlmErrorClassifier;
use Netresearch\T3Cowriter\Service\RateLimiterInterface;
use Netresearch\T3Cowriter\Service\RateLimitResult;
use Netresearch\T3Cowriter\Service\Style\StyleInstructionBuilder;
use Netresearch\T3Cowriter\Service\Transparency\AboutBuilder;
use Netresearch\T3Cowriter\Service\Variant\AudienceContexts;
use Netresearch\T3Cowriter\Service\Variant\VariantConfiguration;
use Netresearch\T3Cowriter\Service\Variant\VariantElement;
use Netresearch\T3Cowriter\Service\Variant\VariantElementReader;
use Netresearch\T3Cowriter\Service\Variant\VariantException;
use Netresearch\T3Cowriter\Service\Variant\VariantProposal;
use Netresearch\T3Cowriter\Service\Variant\VariantProposalService;
use Netresearch\T3Cowriter\Service\Variant\VariantShowToken;
use Netresearch\T3Cowriter\Service\Variant\VariantWriter;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Log\LoggerInterface;
use Throwable;
use TYPO3\CMS\Backend\Routing\UriBuilder;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;
use TYPO3\CMS\Core\Context\Context;
use TYPO3\CMS\Core\Http\JsonResponse;

/**
 * "Create an audience variant" of a text element, in three steps.
 *
 * propose: the model rewrites the element's header and text for the
 * audience (and tone) the editor chose from the operator's nr-llm prompt
 * snippets. Nothing is written; the answer goes to the editor for review,
 * with the contexts he may give the variant when EXT:contexts is installed.
 *
 * create: after the editor reviewed and confirmed, copies the element directly
 * after itself through DataHandler as the editor, with the reviewed header and
 * text, hidden, and visible only in the chosen context. The original stays as
 * it is.
 *
 * show: makes the variant visible and, if the editor asks, hides the original
 * in the variant's context; only for the variant the creation made
 * (VariantShowToken), and only for a variant with a context — without one a
 * visible variant would stand next to its original for every visitor.
 *
 * Every refusal that needs no model comes first — rate limit, the admin
 * switch, the request, the element and the editor's permissions — so a
 * refused request costs nothing.
 *
 * @internal
 */
final readonly class VariantController
{
    use ContentElementActionTrait;
    use RateLimitedControllerTrait;

    public function __construct(
        private VariantConfiguration $variantConfiguration,
        private VariantElementReader $elementReader,
        private VariantProposalService $proposalService,
        private VariantWriter $writer,
        private VariantShowToken $showToken,
        private AudienceContexts $contexts,
        private StyleInstructionBuilder $styleInstructions,
        private ConfigurationSelector $configurationSelector,
        private RateLimiterInterface $rateLimiter,
        private UriBuilder $uriBuilder,
        private Context $context,
        private LoggerInterface $logger,
        private LlmErrorClassifier $errorClassifier = new LlmErrorClassifier(),
        private BackendLabels $labels = new BackendLabels(),
        private BudgetRefusal $budgetRefusal = new BudgetRefusal(),
        private ?AboutBuilder $about = null,
    ) {}

    public function proposeAction(ServerRequestInterface $request): ResponseInterface
    {
        $admission = $this->admitModelRequest($request, $this->variantConfiguration->isEnabled(), 'variant.error.disabled');
        if ($admission instanceof ResponseInterface) {
            return $admission;
        }

        [$rateLimitResult, $body] = $admission;
        $dto                      = VariantProposeRequest::fromArray($body);
        if (!$dto->isValid()) {
            return $this->failure(self::INVALID_REQUEST, $rateLimitResult, 400);
        }

        $backendUser = $GLOBALS['BE_USER'] ?? null;
        if (!$backendUser instanceof BackendUserAuthentication) {
            return $this->failure(self::NO_BACKEND_USER, $rateLimitResult, 403);
        }

        try {
            $element = $this->elementReader->read($dto->uid, $backendUser);
            $this->elementReader->refuseForTranslations($element, $backendUser);
            if (trim(strip_tags($element->bodytext)) === '') {
                throw VariantException::noText();
            }

            if (mb_strlen($element->bodytext, 'UTF-8') > VariantProposalService::MAX_TEXT_LENGTH) {
                throw VariantException::tooLong();
            }

            $styleMessage = $this->styleMessage($dto);
        } catch (VariantException $e) {
            return $this->failure($this->labels->get($e->getLabelKey()), $rateLimitResult, $e->getHttpStatus());
        }

        $proposal = $this->proposeWithModel($element, $styleMessage, $rateLimitResult);
        if ($proposal instanceof ResponseInterface) {
            return $proposal;
        }

        $payload = [
            'success'     => true,
            'fingerprint' => $element->fingerprint(),
            // Raw model output: the dialog sanitises it before showing it.
            'header'      => $proposal->header,
            'bodytext'    => $proposal->bodytext,
            'contexts'    => $this->contexts->mayAssign($backendUser) ? $this->contexts->available() : [],
            'contextNote' => $this->contextNote($backendUser),
        ];
        if ($this->about instanceof AboutBuilder) {
            $payload['about'] = $this->about->variant($proposal->modelUse, $backendUser);
        }

        return $this->jsonResponseWithRateLimitHeaders($payload, $rateLimitResult);
    }

    public function createAction(ServerRequestInterface $request): ResponseInterface
    {
        $admission = $this->admitModelRequest($request, $this->variantConfiguration->isEnabled(), 'variant.error.disabled');
        if ($admission instanceof ResponseInterface) {
            return $admission;
        }

        [$rateLimitResult, $body] = $admission;
        $dto                      = VariantCreateRequest::fromArray($body);
        if (!$dto->isValid()) {
            return $this->failure(self::INVALID_REQUEST, $rateLimitResult, 400);
        }

        $backendUser = $GLOBALS['BE_USER'] ?? null;
        if (!$backendUser instanceof BackendUserAuthentication) {
            return $this->failure(self::NO_BACKEND_USER, $rateLimitResult, 403);
        }

        try {
            // Everything is checked again: the proposal's verdict is not trusted.
            $element = $this->elementReader->read($dto->uid, $backendUser);
            $this->elementReader->refuseForTranslations($element, $backendUser);
            if (!hash_equals($element->fingerprint(), $dto->fingerprint)) {
                throw VariantException::changed();
            }

            if ($dto->context > 0 && (!$this->contexts->mayAssign($backendUser) || $this->contexts->title($dto->context) === null)) {
                throw VariantException::contextNotAvailable();
            }

            $uid = $this->writer->create($element, $dto->header, $dto->bodytext, $dto->context, $backendUser);
        } catch (VariantException $e) {
            return $this->failure($this->labels->get($e->getLabelKey()), $rateLimitResult, $e->getHttpStatus());
        }

        $pageUrl  = $this->pageUrl($element->pageUid, $element->languageId, $uid);
        $noteKey  = $this->contexts->isInstalled() ? 'variant.createdWithoutContext' : 'variant.createdWithoutContexts';
        $withNote = $dto->context === 0;

        return $this->jsonResponseWithRateLimitHeaders(
            [
                'success'      => true,
                'uid'          => $uid,
                'originalUid'  => $element->uid,
                'context'      => $dto->context,
                'contextTitle' => $dto->context > 0 ? (string) $this->contexts->title($dto->context) : '',
                'showToken'    => $dto->context > 0 ? $this->showToken->issue($uid, $element->uid, $dto->context, $this->userId(), $backendUser->workspace) : '',
                'note'         => $withNote ? $this->labels->get($noteKey) : '',
                'pageUrl'      => $pageUrl,
                'editUrl'      => $this->editUrl($uid, $pageUrl),
            ],
            $rateLimitResult,
        );
    }

    /**
     * Not counted against the rate limit of the model routes: the show step
     * asks no model, needs the token of a variant this user just created, and
     * only sets the hidden field of the variant and the context setting of
     * its original.
     */
    public function showAction(ServerRequestInterface $request): ResponseInterface
    {
        if (!$this->variantConfiguration->isEnabled()) {
            return new JsonResponse(['success' => false, 'error' => $this->labels->get('variant.error.disabled')], 403);
        }

        $body = $this->parseJsonBody($request);
        $dto  = VariantShowRequest::fromArray($body ?? []);
        if ($body === null || !$dto->isValid()) {
            return new JsonResponse(['success' => false, 'error' => self::INVALID_REQUEST], 400);
        }

        $backendUser = $GLOBALS['BE_USER'] ?? null;
        if (!$backendUser instanceof BackendUserAuthentication) {
            return new JsonResponse(['success' => false, 'error' => self::NO_BACKEND_USER], 403);
        }

        try {
            if (!$this->showToken->isValid($dto->token, $dto->variantUid, $dto->originalUid, $dto->context, $this->userId(), $backendUser->workspace)) {
                throw VariantException::notAvailable();
            }

            // Checked before anything is written: without the right on the
            // settings field the original would stay visible next to the
            // variant that is already shown.
            if ($dto->hideOriginal && !$this->contexts->mayAssign($backendUser)) {
                throw VariantException::contextNotAvailable();
            }

            $this->writer->show($dto->variantUid, $dto->originalUid, $dto->context, $dto->hideOriginal, $backendUser);
        } catch (VariantException $e) {
            return new JsonResponse(['success' => false, 'error' => $this->labels->get($e->getLabelKey())], $e->getHttpStatus());
        }

        return new JsonResponse(['success' => true]);
    }

    /**
     * The audience instruction from the chosen snippets. The audience must be
     * one the dialog offers: a variant without one is just a rewrite.
     *
     * @throws VariantException
     */
    private function styleMessage(VariantProposeRequest $dto): string
    {
        $audiences = array_column($this->styleInstructions->options()['audiences'], 'uid');
        if (!in_array($dto->audience, $audiences, true)) {
            throw VariantException::noAudience();
        }

        $instruction = $this->styleInstructions->build(new ExecuteTaskRequest(
            taskUid: 0,
            context: '',
            contextType: '',
            instruction: '',
            configuration: null,
            audience: $dto->audience,
            tone: $dto->tone,
        ));
        if ($instruction->message === '') {
            throw VariantException::noAudience();
        }

        return $instruction->message;
    }

    private function proposeWithModel(VariantElement $element, string $styleMessage, RateLimitResult $rateLimitResult): VariantProposal|ResponseInterface
    {
        $selection     = $this->configurationSelector->trySelect(null);
        $configuration = $selection->configuration;
        if (!$configuration instanceof LlmConfiguration) {
            return $this->failure($this->labels->get($selection->errorLabel), $rateLimitResult, $selection->status);
        }

        try {
            $proposal = $this->proposalService->propose($element, $styleMessage, $configuration, $this->userId());
        } catch (Throwable $e) {
            $refusal = BudgetRefusal::find($e);
            if ($refusal instanceof BudgetCheckResult) {
                return $this->budgetRefusal->response($refusal, $rateLimitResult);
            }

            // Provider messages can carry request details: log them, never return them.
            $this->logger->error('Audience variant: the rewrite failed', ['exception' => $e->getMessage()]);

            return $this->failure($this->modelErrorMessage($e, 'variant.error.noRewrite'), $rateLimitResult, 502);
        }

        if ($proposal->isEmpty()) {
            $refusal = VariantException::noRewrite();

            return $this->failure($this->labels->get($refusal->getLabelKey()), $rateLimitResult, $refusal->getHttpStatus());
        }

        return $proposal;
    }

    /**
     * Why no context can be chosen, when contexts is installed but the
     * editor may not assign one; '' otherwise.
     */
    private function contextNote(BackendUserAuthentication $backendUser): string
    {
        if (!$this->contexts->isInstalled()) {
            return $this->labels->get('variant.noContexts');
        }

        if ($backendUser->workspace !== 0) {
            return $this->labels->get('variant.contextsNotInWorkspace');
        }

        if (!$this->contexts->mayAssign($backendUser)) {
            return $this->labels->get('variant.contextsNotAllowed');
        }

        return $this->contexts->available() === [] ? $this->labels->get('variant.contextsNone') : '';
    }
}
