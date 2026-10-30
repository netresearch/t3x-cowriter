<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\T3Cowriter\Controller;

use Netresearch\NrLlm\Domain\DTO\BudgetCheckResult;
use Netresearch\NrLlm\Domain\Model\LlmConfiguration;
use Netresearch\T3Cowriter\Domain\DTO\PresentationCreateRequest;
use Netresearch\T3Cowriter\Domain\DTO\PresentationProposeRequest;
use Netresearch\T3Cowriter\Domain\DTO\PresentationSwitchRequest;
use Netresearch\T3Cowriter\Service\BackendLabels;
use Netresearch\T3Cowriter\Service\BudgetRefusal;
use Netresearch\T3Cowriter\Service\ConfigurationSelector;
use Netresearch\T3Cowriter\Service\LlmErrorClassifier;
use Netresearch\T3Cowriter\Service\LlmErrorKind;
use Netresearch\T3Cowriter\Service\Presentation\Block;
use Netresearch\T3Cowriter\Service\Presentation\BlockParser;
use Netresearch\T3Cowriter\Service\Presentation\ContentLanguageLabel;
use Netresearch\T3Cowriter\Service\Presentation\Layout;
use Netresearch\T3Cowriter\Service\Presentation\PresentationConfiguration;
use Netresearch\T3Cowriter\Service\Presentation\PresentationElement;
use Netresearch\T3Cowriter\Service\Presentation\PresentationElementReader;
use Netresearch\T3Cowriter\Service\Presentation\PresentationException;
use Netresearch\T3Cowriter\Service\Presentation\PresentationSwitcher;
use Netresearch\T3Cowriter\Service\Presentation\PresentationSwitchToken;
use Netresearch\T3Cowriter\Service\Presentation\PresentationWriter;
use Netresearch\T3Cowriter\Service\Presentation\SectionBoundary;
use Netresearch\T3Cowriter\Service\Presentation\SectionPlanner;
use Netresearch\T3Cowriter\Service\Presentation\SectionProposalService;
use Netresearch\T3Cowriter\Service\RateLimiterInterface;
use Netresearch\T3Cowriter\Service\RateLimitResult;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Log\LoggerInterface;
use Throwable;
use TYPO3\CMS\Backend\Routing\UriBuilder;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;
use TYPO3\CMS\Core\Context\Context;
use TYPO3\CMS\Core\Http\JsonResponse;

/**
 * "Present as tabs or accordion" for a text element, in two steps.
 *
 * propose: cuts the stored text into sections. At its h2 (or h3) headings
 * when it has them, without a model call; otherwise the model names where the
 * sections start and how they are titled. Nothing is written.
 *
 * create: after the editor confirmed, creates a new tab or accordion element
 * directly after the original, with one item per section, through DataHandler
 * as the editor, hidden. The original element stays as it is.
 *
 * switch: shows the new element and hides the original in one step, only for
 * the pair the creation made (PresentationSwitchToken).
 *
 * Every refusal that needs no model comes first — rate limit, the admin
 * switch, the request, the element and the editor's permissions — so a
 * refused request costs nothing.
 *
 * @internal
 */
final readonly class PresentationController
{
    use RateLimitedControllerTrait;

    /**
     * Characters of a section's text shown in the preview.
     */
    public const PREVIEW_LENGTH = 160;

    public function __construct(
        private PresentationConfiguration $presentationConfiguration,
        private PresentationElementReader $elementReader,
        private SectionProposalService $proposalService,
        private PresentationWriter $writer,
        private PresentationSwitcher $switcher,
        private PresentationSwitchToken $switchToken,
        private ConfigurationSelector $configurationSelector,
        private RateLimiterInterface $rateLimiter,
        private UriBuilder $uriBuilder,
        private Context $context,
        private LoggerInterface $logger,
        private ?ContentLanguageLabel $contentLanguageLabel = null,
        private BlockParser $blockParser = new BlockParser(),
        private SectionPlanner $planner = new SectionPlanner(),
        private LlmErrorClassifier $errorClassifier = new LlmErrorClassifier(),
        private BackendLabels $labels = new BackendLabels(),
        private BudgetRefusal $budgetRefusal = new BudgetRefusal(),
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
        $dto  = PresentationProposeRequest::fromArray($body ?? []);
        if ($body === null || !$dto->isValid()) {
            return $this->failure('Invalid request.', $rateLimitResult, 400);
        }

        $backendUser = $GLOBALS['BE_USER'] ?? null;
        if (!$backendUser instanceof BackendUserAuthentication) {
            return $this->failure('No backend user session.', $rateLimitResult, 403);
        }

        try {
            $element = $this->elementReader->read($dto->uid, $backendUser);
            $blocks  = $this->blockParser->parse($element->bodytext);
            if (count($blocks) < SectionPlanner::MIN_SECTIONS) {
                throw PresentationException::tooShort();
            }

            $sections = $this->planner->fromHeadings($blocks, $this->introductionTitle($element));
            $source   = 'headings';
            if ($sections === []) {
                if (count($blocks) > SectionProposalService::MAX_BLOCKS) {
                    throw PresentationException::tooLong();
                }

                $source = 'model';
            }
        } catch (PresentationException $e) {
            return $this->failure($this->labels->get($e->getLabelKey()), $rateLimitResult, $e->getHttpStatus());
        }

        if ($source === 'model') {
            $proposal = $this->proposeWithModel($blocks, $element, $rateLimitResult);
            if ($proposal instanceof ResponseInterface) {
                return $proposal;
            }

            $sections = $proposal;
        }

        // The layouts are backed enums, encoded as their value.
        return $this->jsonResponseWithRateLimitHeaders(
            [
                'success'     => true,
                'source'      => $source,
                'fingerprint' => $element->fingerprint(),
                'layouts'     => $element->layouts,
                'sections'    => $this->preview($blocks, $sections),
                'warnings'    => $this->warnings($element),
                'images'      => count($element->fileReferences),
            ],
            $rateLimitResult,
        );
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
        $dto  = PresentationCreateRequest::fromArray($body ?? []);
        if ($body === null || !$dto->isValid() || !$dto->layout instanceof Layout) {
            return $this->failure('Invalid request.', $rateLimitResult, 400);
        }

        $backendUser = $GLOBALS['BE_USER'] ?? null;
        if (!$backendUser instanceof BackendUserAuthentication) {
            return $this->failure('No backend user session.', $rateLimitResult, 403);
        }

        try {
            // Everything is checked again: the proposal's verdict is not trusted.
            $element = $this->elementReader->read($dto->uid, $backendUser);
            if (!$element->allows($dto->layout)) {
                throw PresentationException::notAvailable();
            }

            if (!hash_equals($element->fingerprint(), $dto->fingerprint)) {
                throw PresentationException::changed();
            }

            $blocks = $this->blockParser->parse($element->bodytext);
            if (!$this->planner->fits($blocks, $dto->sections)) {
                throw PresentationException::invalidSections();
            }

            $uid = $this->writer->create($element, $dto->layout, $this->planner->contents($blocks, $dto->sections), $backendUser);
        } catch (PresentationException $e) {
            return $this->failure($this->labels->get($e->getLabelKey()), $rateLimitResult, $e->getHttpStatus());
        }

        $pageUrl = $this->pageUrl($element->pageUid, $element->languageId, $uid);
        // No switch for an original with translations or a connected
        // translation (see PresentationSwitcher); the editor reads why.
        $switchRefusal = match (true) {
            $element->translationCount > 0 => PresentationException::switchHasTranslations(),
            $element->connectedTranslation => PresentationException::switchConnectedTranslation(),
            default                        => null,
        };

        return $this->jsonResponseWithRateLimitHeaders(
            [
                'success'     => true,
                'uid'         => $uid,
                'originalUid' => $element->uid,
                'layout'      => $dto->layout->value,
                'switchToken' => $switchRefusal instanceof PresentationException ? '' : $this->switchToken->issue($uid, $element->uid, $this->userId(), $backendUser->workspace),
                'switchNote'  => $switchRefusal instanceof PresentationException ? $this->labels->get($switchRefusal->getLabelKey()) : '',
                'pageUrl'     => $pageUrl,
                'editUrl'     => $this->editUrl($uid, $pageUrl),
            ],
            $rateLimitResult,
        );
    }

    /**
     * Not counted against the rate limit of the model routes: the switch asks
     * no model, needs the token of a pair this user just created, and only
     * sets the hidden field of those two records.
     */
    public function switchAction(ServerRequestInterface $request): ResponseInterface
    {
        if (!$this->presentationConfiguration->isEnabled()) {
            return new JsonResponse(['success' => false, 'error' => $this->labels->get('presentation.error.disabled')], 403);
        }

        $body = $this->parseJsonBody($request);
        $dto  = PresentationSwitchRequest::fromArray($body ?? []);
        if ($body === null || !$dto->isValid()) {
            return new JsonResponse(['success' => false, 'error' => 'Invalid request.'], 400);
        }

        $backendUser = $GLOBALS['BE_USER'] ?? null;
        if (!$backendUser instanceof BackendUserAuthentication) {
            return new JsonResponse(['success' => false, 'error' => 'No backend user session.'], 403);
        }

        try {
            // Only the pair this user's creation made, in this workspace.
            if (!$this->switchToken->isValid($dto->token, $dto->newUid, $dto->originalUid, $this->userId(), $backendUser->workspace)) {
                throw PresentationException::notAvailable();
            }

            $this->switcher->switch($dto->newUid, $dto->originalUid, $backendUser);
        } catch (PresentationException $e) {
            return new JsonResponse(['success' => false, 'error' => $this->labels->get($e->getLabelKey())], $e->getHttpStatus());
        }

        return new JsonResponse(['success' => true]);
    }

    /**
     * @param list<Block> $blocks
     *
     * @return list<SectionBoundary>|ResponseInterface
     */
    private function proposeWithModel(array $blocks, PresentationElement $element, RateLimitResult $rateLimitResult): array|ResponseInterface
    {
        $selection     = $this->configurationSelector->trySelect(null);
        $configuration = $selection->configuration;
        if (!$configuration instanceof LlmConfiguration) {
            return $this->failure($this->labels->get($selection->errorLabel), $rateLimitResult, $selection->status);
        }

        try {
            $sections = $this->proposalService->propose($blocks, $element->header, $configuration, $this->userId());
        } catch (Throwable $e) {
            $refusal = BudgetRefusal::find($e);
            if ($refusal instanceof BudgetCheckResult) {
                return $this->budgetRefusal->response($refusal, $rateLimitResult);
            }

            // Provider messages can carry request details: log them, never return them.
            $this->logger->error('Alternative presentation: the section proposal failed', ['exception' => $e->getMessage()]);

            return $this->failure($this->errorMessage($e), $rateLimitResult, 502);
        }

        if ($sections === []) {
            $refusal = PresentationException::noSplit();

            return $this->failure($this->labels->get($refusal->getLabelKey()), $rateLimitResult, $refusal->getHttpStatus());
        }

        return $sections;
    }

    /**
     * What the editor sees of each section before confirming: the title, the
     * beginning of the text, and the data the creation request sends back.
     *
     * @param list<Block>           $blocks
     * @param list<SectionBoundary> $sections
     *
     * @return list<array<string, mixed>>
     */
    private function preview(array $blocks, array $sections): array
    {
        $preview = [];
        foreach ($this->planner->contents($blocks, $sections) as $index => $content) {
            $text = mb_strlen($content->text, 'UTF-8') > self::PREVIEW_LENGTH
                ? rtrim(mb_substr($content->text, 0, self::PREVIEW_LENGTH - 1, 'UTF-8')) . '…'
                : $content->text;

            $preview[] = [
                'title'           => $content->title,
                'start'           => $sections[$index]->start,
                'consumesHeading' => $sections[$index]->consumesHeading,
                'preview'         => $text,
            ];
        }

        return $preview;
    }

    /**
     * The title of the text before the first heading, in the language of the
     * element (its site language), else in the editor's.
     */
    private function introductionTitle(PresentationElement $element): string
    {
        return $this->contentLanguageLabel?->get('presentation.introduction', $element->pageUid, $element->languageId)
            ?? $this->labels->get('presentation.introduction');
    }

    /**
     * What the editor should know before creating: the original has
     * translations (deleting it would delete them), it is a connected
     * translation (the new element is a free one), or its images cannot be
     * taken over.
     *
     * @return list<string>
     */
    private function warnings(PresentationElement $element): array
    {
        $warnings = [];
        if ($element->translationCount > 0) {
            $warnings[] = 'translations';
        }

        if ($element->connectedTranslation) {
            $warnings[] = 'connectedTranslation';
        }

        if ($element->fileReferences !== [] && $element->layoutsTakingFiles !== $element->layouts) {
            $warnings[] = 'imagesNotTaken';
        }

        return $warnings;
    }

    private function refuseUnlessEnabled(RateLimitResult $rateLimitResult): ?ResponseInterface
    {
        return $this->presentationConfiguration->isEnabled()
            ? null
            : $this->failure($this->labels->get('presentation.error.disabled'), $rateLimitResult, 403);
    }

    private function failure(string $message, RateLimitResult $rateLimitResult, int $status): ResponseInterface
    {
        return $this->jsonResponseWithRateLimitHeaders(['success' => false, 'error' => $message], $rateLimitResult, $status);
    }

    /**
     * The edit form of the new element; closing it leads to $returnUrl.
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
     * The Page module for the element's page and language, at the new
     * element: the page module renders each element with the id
     * "element-tt_content-<uid>" (TYPO3 13.4 and 14.3). 13.4 reads the
     * language from "language", 14.3 from "languages".
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
            LlmErrorKind::Configuration  => $this->labels->get('error.checkSetupStatus', $this->labels->get('error.notConfigured')),
            LlmErrorKind::Authentication => $this->labels->get('error.providerAuthentication'),
            LlmErrorKind::RateLimit      => $this->labels->get('error.providerRateLimit'),
            LlmErrorKind::Unknown        => $this->labels->get('presentation.error.noSplit'),
        };
    }

    private function userId(): int
    {
        $userId = $this->context->getPropertyFromAspect('backend.user', 'id', 0);

        return is_numeric($userId) ? (int) $userId : 0;
    }
}
