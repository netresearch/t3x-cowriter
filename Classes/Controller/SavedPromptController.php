<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\T3Cowriter\Controller;

use Netresearch\T3Cowriter\Domain\DTO\SavedPrompt;
use Netresearch\T3Cowriter\Domain\DTO\SavePromptRequest;
use Netresearch\T3Cowriter\Service\BackendLabels;
use Netresearch\T3Cowriter\Service\Prompt\SavedPromptRepository;
use Netresearch\T3Cowriter\Service\RateLimiterInterface;
use Netresearch\T3Cowriter\Service\RateLimitResult;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Log\LoggerInterface;
use Throwable;
use TYPO3\CMS\Core\Context\Context;

/**
 * AJAX endpoints for the prompts editors save in the Cowriter dialog:
 * list the visible prompts, save one, delete an own one.
 *
 * @internal
 */
final readonly class SavedPromptController
{
    use RateLimitedControllerTrait;

    public function __construct(
        private SavedPromptRepository $prompts,
        private RateLimiterInterface $rateLimiter,
        private Context $context,
        private LoggerInterface $logger,
        private BackendLabels $labels = new BackendLabels(),
    ) {}

    public function listAction(ServerRequestInterface $request): ResponseInterface
    {
        $userId          = $this->userId();
        $rateLimitResult = $this->rateLimiter->checkLimit((string) $userId);
        if (!$rateLimitResult->allowed) {
            return $this->rateLimitedResponse($rateLimitResult);
        }

        try {
            $prompts = array_map(
                static fn (SavedPrompt $prompt): array => $prompt->toArray(),
                $this->prompts->visibleTo($userId),
            );
        } catch (Throwable $e) {
            return $this->failure('Failed to list saved prompts', $e, $rateLimitResult);
        }

        return $this->jsonResponseWithRateLimitHeaders(['success' => true, 'prompts' => $prompts], $rateLimitResult);
    }

    public function saveAction(ServerRequestInterface $request): ResponseInterface
    {
        $userId          = $this->userId();
        $rateLimitResult = $this->rateLimiter->checkLimit((string) $userId);
        if (!$rateLimitResult->allowed) {
            return $this->rateLimitedResponse($rateLimitResult);
        }

        $body = $this->parseJsonBody($request);
        $dto  = SavePromptRequest::fromArray($body ?? []);
        if ($body === null || !$dto->isValid()) {
            return $this->jsonResponseWithRateLimitHeaders(
                ['success' => false, 'error' => $this->labels->get('error.promptInvalid')],
                $rateLimitResult,
                400,
            );
        }

        try {
            if ($this->prompts->countOwnedBy($userId) >= SavedPromptRepository::MAX_PER_USER) {
                return $this->jsonResponseWithRateLimitHeaders(
                    ['success' => false, 'error' => $this->labels->get('error.promptLimit')],
                    $rateLimitResult,
                    409,
                );
            }

            $isAdmin = $this->context->getPropertyFromAspect('backend.user', 'isAdmin', false) === true;
            $prompt  = $this->prompts->add($userId, $isAdmin, $dto);
        } catch (Throwable $e) {
            return $this->failure('Failed to save a prompt', $e, $rateLimitResult);
        }

        return $this->jsonResponseWithRateLimitHeaders(
            ['success' => true, 'prompt' => $prompt->toArray()],
            $rateLimitResult,
        );
    }

    public function deleteAction(ServerRequestInterface $request): ResponseInterface
    {
        $userId          = $this->userId();
        $rateLimitResult = $this->rateLimiter->checkLimit((string) $userId);
        if (!$rateLimitResult->allowed) {
            return $this->rateLimitedResponse($rateLimitResult);
        }

        $uid = $this->parseJsonBody($request)['uid'] ?? null;
        if (!is_int($uid) || $uid < 1) {
            return $this->jsonResponseWithRateLimitHeaders(
                ['success' => false, 'error' => $this->labels->get('error.promptInvalid')],
                $rateLimitResult,
                400,
            );
        }

        try {
            $deleted = $this->prompts->deleteOwn($uid, $userId);
        } catch (Throwable $e) {
            return $this->failure('Failed to delete a prompt', $e, $rateLimitResult);
        }

        if (!$deleted) {
            return $this->jsonResponseWithRateLimitHeaders(
                ['success' => false, 'error' => $this->labels->get('error.promptNotFound')],
                $rateLimitResult,
                404,
            );
        }

        return $this->jsonResponseWithRateLimitHeaders(['success' => true], $rateLimitResult);
    }

    private function userId(): int
    {
        $userId = $this->context->getPropertyFromAspect('backend.user', 'id', 0);

        return is_numeric($userId) ? (int) $userId : 0;
    }

    private function failure(string $message, Throwable $e, RateLimitResult $rateLimitResult): ResponseInterface
    {
        $this->logger->error($message, ['exception' => $e->getMessage()]);

        return $this->jsonResponseWithRateLimitHeaders(
            ['success' => false, 'error' => $this->labels->get('error.prompts')],
            $rateLimitResult,
            500,
        );
    }
}
