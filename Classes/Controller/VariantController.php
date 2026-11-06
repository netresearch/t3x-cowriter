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
use Netresearch\T3Cowriter\Service\LlmErrorKind;
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
        $rateLimitResult = $this->rateLimiter->checkLimit((string) $this->userId());
        if (!$rateLimitResult->allowed) {
            return $this->rateLimitedResponse($rateLimitResult);
        }

        $refusal = $this->refuseUnlessEnabled($rateLimitResult);
        if ($refusal instanceof ResponseInterface) {
            return $refusal;
        }

        $body = $this->parseJsonBody($request);
        $dto  = VariantProposeRequest::fromArray($body ?? []);
        if ($body === null || !$dto->isValid()) {
            return $this->failure('Invalid request.', $rateLimitResult, 400);
        }

        $backendUser = $GLOBALS['BE_USER'] ?? null;
        if (!$backendUser instanceof BackendUserAuthentication) {
            return $this->failure('No backend user session.', $rateLimitResult, 403);
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
        $rateLimitResult = $this->rateLimiter->checkLimit((string) $this->userId());
        if (!$rateLimitResult->allowed) {
            return $this->rateLimitedResponse($rateLimitResult);
        }

        $refusal = $this->refuseUnlessEnabled($rateLimitResult);
        if ($refusal instanceof ResponseInterface) {
            return $refusal;
        }

        $body = $this->parseJsonBody($request);
        $dto  = VariantCreateRequest::fromArray($body ?? []);
        if ($body === null || !$dto->isValid()) {
            return $this->failure('Invalid request.', $rateLimitResult, 400);
        }

        $backendUser = $GLOBALS['BE_USER'] ?? null;
        if (!$backendUser instanceof BackendUserAuthentication) {
            return $this->failure('No backend user session.', $rateLimitResult, 403);
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

        $pageUrl = $this->pageUrl($element->pageUid, $element->languageId, $uid);

        return $this->jsonResponseWithRateLimitHeaders(
            [
                'success'      => true,
                'uid'          => $uid,
                'originalUid'  => $element->uid,
                'context'      => $dto->context,
                'contextTitle' => $dto->context > 0 ? (string) $this->contexts->title($dto->context) : '',
                'showToken'    => $dto->context > 0 ? $this->showToken->issue($uid, $element->uid, $dto->context, $this->userId(), $backendUser->workspace) : '',
                'note'         => $dto->context > 0 ? '' : $this->labels->get($this->contexts->isInstalled() ? 'variant.createdWithoutContext' : 'variant.createdWithoutContexts'),
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
            return new JsonResponse(['success' => false, 'error' => 'Invalid request.'], 400);
        }

        $backendUser = $GLOBALS['BE_USER'] ?? null;
        if (!$backendUser instanceof BackendUserAuthentication) {
            return new JsonResponse(['success' => false, 'error' => 'No backend user session.'], 403);
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

            return $this->failure($this->errorMessage($e), $rateLimitResult, 502);
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

    private function refuseUnlessEnabled(RateLimitResult $rateLimitResult): ?ResponseInterface
    {
        return $this->variantConfiguration->isEnabled()
            ? null
            : $this->failure($this->labels->get('variant.error.disabled'), $rateLimitResult, 403);
    }

    private function failure(string $message, RateLimitResult $rateLimitResult, int $status): ResponseInterface
    {
        return $this->jsonResponseWithRateLimitHeaders(['success' => false, 'error' => $message], $rateLimitResult, $status);
    }

    /**
     * The edit form of the variant; closing it leads to $returnUrl.
     */
    private function editUrl(int $uid, string $returnUrl): string
    {
        $parameters = ['edit' => ['tt_content' => [$uid => 'edit']]];
        if ($returnUrl !== '') {
            $parameters['returnUrl'] = $returnUrl;
        }

        try {
            return (string) $this->uriBuilder->buildUriFromRoute('record_edit', $parameters);
        } catch (Throwable) {
            return '';
        }
    }

    /**
     * The Page module for the element's page and language, at the variant
     * (see PresentationController::pageUrl()).
     */
    private function pageUrl(int $pageUid, int $languageId, int $uid): string
    {
        try {
            $url = (string) $this->uriBuilder->buildUriFromRoute(
                'web_layout',
                ['id' => $pageUid, 'language' => $languageId, 'languages' => [$languageId]],
            );
        } catch (Throwable) {
            return '';
        }

        return $url . '#element-tt_content-' . $uid;
    }

    /**
     * The editor-facing message for a failed model call.
     */
    private function errorMessage(Throwable $exception): string
    {
        return match ($this->errorClassifier->classify($exception)) {
            LlmErrorKind::Configuration    => $this->labels->get('error.checkSetupStatus', $this->labels->get('error.notConfigured')),
            LlmErrorKind::Authentication   => $this->labels->get('error.providerAuthentication'),
            LlmErrorKind::RateLimit        => $this->labels->get('error.providerRateLimit'),
            LlmErrorKind::GuardrailRefusal => LlmErrorClassifier::guardrailReason($exception) ?? $this->labels->get('variant.error.noRewrite'),
            LlmErrorKind::Unknown          => $this->labels->get('variant.error.noRewrite'),
        };
    }

    private function userId(): int
    {
        $userId = $this->context->getPropertyFromAspect('backend.user', 'id', 0);

        return is_numeric($userId) ? (int) $userId : 0;
    }
}
