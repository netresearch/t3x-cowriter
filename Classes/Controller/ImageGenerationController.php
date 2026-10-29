<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\T3Cowriter\Controller;

use Netresearch\NrLlm\Domain\DTO\BudgetCheckResult;
use Netresearch\NrLlm\Domain\Model\LlmConfiguration;
use Netresearch\NrLlm\Exception\InvalidArgumentException as NrLlmInvalidArgumentException;
use Netresearch\NrLlm\Specialized\Exception\ServiceQuotaExceededException;
use Netresearch\NrLlm\Specialized\Exception\ServiceUnavailableException;
use Netresearch\NrLlm\Specialized\Image\DallEImageService;
use Netresearch\NrLlm\Specialized\Option\ImageGenerationOptions;
use Netresearch\T3Cowriter\Domain\DTO\ImageGenerationRequest;
use Netresearch\T3Cowriter\Service\BackendLabels;
use Netresearch\T3Cowriter\Service\BudgetRefusal;
use Netresearch\T3Cowriter\Service\CallerSource;
use Netresearch\T3Cowriter\Service\ConfigurationSelector;
use Netresearch\T3Cowriter\Service\Image\GeneratedImageStorageInterface;
use Netresearch\T3Cowriter\Service\Image\ImageGenerationConfiguration;
use Netresearch\T3Cowriter\Service\Image\ImageRejectedException;
use Netresearch\T3Cowriter\Service\Image\StoredImage;
use Netresearch\T3Cowriter\Service\RateLimiterInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Log\LoggerInterface;
use Throwable;
use TYPO3\CMS\Core\Context\Context;
use TYPO3\CMS\Core\Resource\Folder;

/**
 * AJAX controller for "image by prompt" in the rich text editor: generates an
 * image with nr-llm's OpenAI image service and stores it in FAL as the editor.
 *
 * Every refusal that does not need the model comes first — rate limit, the
 * admin switch, the request, the pinned configuration, a writable folder — so
 * a refused request costs nothing. The model and the switch come from the
 * extension configuration only.
 *
 * @internal
 */
final readonly class ImageGenerationController
{
    use RateLimitedControllerTrait;

    /**
     * Longest alt text proposed from the prompt, in characters.
     */
    public const MAX_ALT_LENGTH = 250;

    public function __construct(
        private DallEImageService $imageService,
        private GeneratedImageStorageInterface $imageStorage,
        private ImageGenerationConfiguration $imageConfiguration,
        private ConfigurationSelector $configurationSelector,
        private RateLimiterInterface $rateLimiter,
        private Context $context,
        private LoggerInterface $logger,
        private BackendLabels $labels = new BackendLabels(),
        private BudgetRefusal $budgetRefusal = new BudgetRefusal(),
    ) {}

    public function generateAction(ServerRequestInterface $request): ResponseInterface
    {
        /** @var int|string $userId */
        $userId          = $this->context->getPropertyFromAspect('backend.user', 'id', 0);
        $rateLimitResult = $this->rateLimiter->checkLimit((string) $userId);

        if (!$rateLimitResult->allowed) {
            return $this->rateLimitedResponse($rateLimitResult);
        }

        if (!$this->imageConfiguration->isEnabled()) {
            return $this->jsonResponseWithRateLimitHeaders(
                ['success' => false, 'error' => $this->labels->get('error.image.disabled')],
                $rateLimitResult,
                403,
            );
        }

        $body = $this->parseJsonBody($request);
        if ($body === null) {
            return $this->jsonResponseWithRateLimitHeaders(
                ['success' => false, 'error' => 'Invalid JSON in request body.'],
                $rateLimitResult,
                400,
            );
        }

        $imageRequest = ImageGenerationRequest::fromRequestBody($body);

        if (!$imageRequest->isValid()) {
            return $this->jsonResponseWithRateLimitHeaders(
                ['success' => false, 'error' => $this->labels->get('error.image.promptTooLong', ImageGenerationRequest::MAX_PROMPT_LENGTH)],
                $rateLimitResult,
                400,
            );
        }

        if ($imageRequest->prompt === '') {
            return $this->jsonResponseWithRateLimitHeaders(
                ['success' => false, 'error' => $this->labels->get('error.image.noPrompt')],
                $rateLimitResult,
                400,
            );
        }

        // A pinned configuration must exist, be active and be one the editor
        // may use; it only attributes the call (usage, per-configuration
        // budget). Without a pin the call is attributed to the editor alone.
        $configuration = null;
        if ($imageRequest->configuration !== null) {
            $selection     = $this->configurationSelector->trySelect($imageRequest->configuration);
            $configuration = $selection->configuration;
            if (!$configuration instanceof LlmConfiguration) {
                return $this->jsonResponseWithRateLimitHeaders(
                    ['success' => false, 'error' => $this->labels->get($selection->errorLabel)],
                    $rateLimitResult,
                    $selection->status,
                );
            }
        }

        $folder = $this->imageStorage->targetFolder();
        if (!$folder instanceof Folder) {
            return $this->jsonResponseWithRateLimitHeaders(
                ['success' => false, 'error' => $this->labels->get('error.image.noFolder')],
                $rateLimitResult,
                403,
            );
        }

        // The setting wins; without one, nr-llm's model registry (the active
        // image model flagged as default) decides, then the fallback.
        $model = $this->imageConfiguration->model()
            ?? $this->imageService->resolveDefaultModel(ImageGenerationConfiguration::FALLBACK_MODEL);

        try {
            $options = $this->options($model, $configuration, (int) $userId);
        } catch (NrLlmInvalidArgumentException $e) {
            $this->logger->error('Image generation: the image model setting is invalid', [
                'model'     => $model,
                'exception' => $e->getMessage(),
            ]);

            return $this->jsonResponseWithRateLimitHeaders(
                ['success' => false, 'error' => $this->labels->get('error.image.invalidModel', $model)],
                $rateLimitResult,
                500,
            );
        }

        try {
            $result = $this->imageService->generate($imageRequest->prompt, $options);
        } catch (Throwable $e) {
            $refusal = BudgetRefusal::find($e);
            if ($refusal instanceof BudgetCheckResult) {
                return $this->budgetRefusal->response($refusal, $rateLimitResult);
            }

            $this->logger->error('Image generation failed', [
                'exception' => $e->getMessage(),
                'model'     => $model,
            ]);

            return $this->jsonResponseWithRateLimitHeaders(
                ['success' => false, 'error' => $this->generationError($e)],
                $rateLimitResult,
                $e instanceof ServiceQuotaExceededException ? 429 : 500,
            );
        }

        // Only the bytes are stored. The temporary URL a DALL·E answer may
        // carry is never fetched.
        try {
            $stored = $this->imageStorage->store($result->base64, $imageRequest->prompt, $folder);
        } catch (ImageRejectedException $e) {
            $this->logger->warning('Image generation: the returned image was rejected', [
                'reason'    => $e->reason,
                'exception' => $e->getMessage(),
            ]);

            return $this->jsonResponseWithRateLimitHeaders(
                ['success' => false, 'error' => $this->labels->get('error.image.rejected')],
                $rateLimitResult,
                502,
            );
        } catch (Throwable $e) {
            $this->logger->error('Image generation: storing the image failed', [
                'folder'    => $folder->getCombinedIdentifier(),
                'exception' => $e->getMessage(),
            ]);

            return $this->jsonResponseWithRateLimitHeaders(
                ['success' => false, 'error' => $this->labels->get('error.image.storeFailed')],
                $rateLimitResult,
                500,
            );
        }

        return $this->jsonResponseWithRateLimitHeaders(
            $this->successPayload($stored, $imageRequest->prompt, $result->revisedPrompt),
            $rateLimitResult,
        );
    }

    /**
     * Options for the image service. gpt-image-* always answers with base64;
     * for the DALL·E models base64 is requested explicitly. The size is the
     * square default every model accepts.
     *
     * @throws NrLlmInvalidArgumentException when the model names no OpenAI image model
     */
    private function options(string $model, ?LlmConfiguration $configuration, int $userId): ImageGenerationOptions
    {
        $isGptImage = str_starts_with($model, 'gpt-image-');

        return (new ImageGenerationOptions(
            model: $model,
            size: '1024x1024',
            quality: $isGptImage ? null : 'standard',
            style: $isGptImage ? null : 'natural',
            format: 'b64_json',
            configuration: $configuration?->getIdentifier(),
            beUserUid: $userId,
        ))->withCallerSource(CallerSource::EXTENSION, 'generateImage');
    }

    /**
     * @return array<string, mixed>
     */
    private function successPayload(StoredImage $stored, string $prompt, ?string $revisedPrompt): array
    {
        $payload = [
            'success' => true,
            'image'   => [
                'fileUid'   => $stored->fileUid,
                'fileTable' => 'sys_file',
                'url'       => $stored->publicUrl,
                'width'     => $stored->width,
                'height'    => $stored->height,
                'mimeType'  => $stored->mimeType,
                'file'      => $stored->combinedIdentifier,
            ],
            'alt' => self::altText($prompt),
        ];

        if (!in_array($revisedPrompt, [null, '', $prompt], true)) {
            $payload['revisedPrompt'] = $revisedPrompt;
        }

        return $payload;
    }

    /**
     * The proposed alt text: the editor's prompt on one line, shortened at a
     * word boundary. It describes what was asked for, not what was drawn, so
     * the client marks it for review.
     */
    public static function altText(string $prompt): string
    {
        $alt = trim((string) preg_replace('/\s+/u', ' ', $prompt));
        if (mb_strlen($alt) <= self::MAX_ALT_LENGTH) {
            return $alt;
        }

        $cut   = mb_substr($alt, 0, self::MAX_ALT_LENGTH - 1);
        $space = mb_strrpos($cut, ' ');

        return rtrim($space !== false && $space > self::MAX_ALT_LENGTH / 2 ? mb_substr($cut, 0, $space) : $cut) . '…';
    }

    /**
     * The editor-facing message for a failed generation. Provider details stay
     * in the log.
     */
    private function generationError(Throwable $e): string
    {
        if ($e instanceof ServiceUnavailableException && ($e->context['reason'] ?? null) === 'not_configured') {
            return $this->labels->get('error.image.notConfigured');
        }

        if ($e instanceof ServiceQuotaExceededException) {
            return $this->labels->get('error.providerRateLimit');
        }

        return $this->labels->get('error.image.failed');
    }
}
