<?php

/*
 * This file is part of the package netresearch/t3-cowriter.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace Netresearch\T3Cowriter\Controller;

use JsonException;
use Netresearch\NrLlm\Domain\Model\LlmConfiguration;
use Netresearch\NrLlm\Domain\Repository\LlmConfigurationRepository;
use Netresearch\NrLlm\Provider\Exception\ProviderException;
use Netresearch\NrLlm\Service\LlmServiceManagerInterface;
use Netresearch\NrLlm\Service\Option\ChatOptions;
use Netresearch\T3Cowriter\Domain\DTO\CompleteRequest;
use Netresearch\T3Cowriter\Domain\DTO\CompleteResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Log\LoggerInterface;
use Throwable;
use TYPO3\CMS\Backend\Attribute\AsController;
use TYPO3\CMS\Core\Http\JsonResponse;

/**
 * AJAX controller for LLM interactions via nr-llm extension.
 *
 * Provides backend API endpoints for chat and completion requests,
 * routing them through the centralized LlmServiceManager.
 *
 * SECURITY: All LLM output is HTML-escaped to prevent XSS attacks.
 */
#[AsController]
final class AjaxController
{
    /**
     * System prompt for the cowriter assistant.
     */
    private const string SYSTEM_PROMPT = <<<'PROMPT'
        You are a professional writing assistant integrated into a CMS editor.
        Your task is to improve, enhance, or generate text based on the user's request.
        Respond ONLY with the improved/generated text, without any explanations,
        markdown formatting, or additional commentary.
        PROMPT;

    public function __construct(
        private readonly LlmServiceManagerInterface $llmServiceManager,
        private readonly LlmConfigurationRepository $configurationRepository,
        private readonly LoggerInterface $logger,
    ) {}

    /**
     * Handle chat requests with conversation history.
     *
     * Expects JSON body with:
     * - messages: array of {role: string, content: string}
     * - options: optional array with temperature, maxTokens, etc.
     */
    public function chatAction(ServerRequestInterface $request): ResponseInterface
    {
        try {
            $body = json_decode(
                $request->getBody()->getContents(),
                true,
                512,
                JSON_THROW_ON_ERROR,
            );
        } catch (JsonException) {
            return new JsonResponse(['error' => 'Invalid JSON in request body'], 400);
        }

        if (!is_array($body)) {
            return new JsonResponse(['error' => 'Invalid JSON structure'], 400);
        }

        /** @var array<int, array{role: string, content: string}> $messages */
        $messages = isset($body['messages']) && is_array($body['messages']) ? $body['messages'] : [];
        /** @var array<string, mixed> $optionsData */
        $optionsData = isset($body['options']) && is_array($body['options']) ? $body['options'] : [];
        $options     = $this->createChatOptions($optionsData);

        if ($messages === []) {
            return new JsonResponse(['error' => 'Messages array is required'], 400);
        }

        try {
            $response = $this->llmServiceManager->chat($messages, $options);

            // Escape HTML to prevent XSS attacks (defense in depth for all string values)
            return new JsonResponse([
                'content'      => htmlspecialchars($response->content, ENT_QUOTES | ENT_HTML5, 'UTF-8'),
                'model'        => htmlspecialchars($response->model ?? '', ENT_QUOTES | ENT_HTML5, 'UTF-8'),
                'finishReason' => htmlspecialchars($response->finishReason ?? '', ENT_QUOTES | ENT_HTML5, 'UTF-8'),
            ]);
        } catch (ProviderException $e) {
            $this->logger->error('Chat provider error', ['exception' => $e->getMessage()]);

            return new JsonResponse(['error' => 'LLM provider error occurred'], 500);
        } catch (Throwable $e) {
            $this->logger->error('Chat action error', [
                'exception' => $e->getMessage(),
                'trace'     => $e->getTraceAsString(),
            ]);

            return new JsonResponse(['error' => 'An unexpected error occurred'], 500);
        }
    }

    /**
     * Handle single completion requests with DTO-based processing.
     *
     * Expects JSON body with:
     * - prompt: string prompt to complete (supports #cw:model prefix)
     * - configuration: optional configuration identifier
     * - options: optional array with temperature, maxTokens, etc.
     *
     * Response includes usage statistics and properly escaped content.
     */
    public function completeAction(ServerRequestInterface $request): ResponseInterface
    {
        $dto = CompleteRequest::fromRequest($request);

        if (!$dto->isValid()) {
            return new JsonResponse(
                CompleteResponse::error('No prompt provided')->jsonSerialize(),
                400,
            );
        }

        // Resolve configuration (from identifier or default)
        $configuration = $this->resolveConfiguration($dto->configuration);
        if ($configuration === null) {
            return new JsonResponse(
                CompleteResponse::error(
                    'No LLM configuration available. Please configure the nr_llm extension.',
                )->jsonSerialize(),
                404,
            );
        }

        try {
            $messages = [
                ['role' => 'system', 'content' => self::SYSTEM_PROMPT],
                ['role' => 'user', 'content' => $dto->prompt],
            ];

            $options = $configuration->toChatOptions();

            // Apply model override if specified via #cw:model-name prefix
            if ($dto->modelOverride !== null) {
                $options = $options->withModel($dto->modelOverride);
            }

            $response = $this->llmServiceManager->chat($messages, $options);

            // CompleteResponse.success() handles HTML escaping
            return new JsonResponse(
                CompleteResponse::success($response)->jsonSerialize(),
            );
        } catch (ProviderException $e) {
            $this->logger->error('Cowriter provider error', [
                'exception' => $e->getMessage(),
            ]);

            // Don't expose provider details to client - log them instead
            return new JsonResponse(
                CompleteResponse::error('LLM provider error occurred. Please try again later.')->jsonSerialize(),
                500,
            );
        } catch (Throwable $e) {
            $this->logger->error('Cowriter unexpected error', [
                'exception' => $e->getMessage(),
                'trace'     => $e->getTraceAsString(),
            ]);

            return new JsonResponse(
                CompleteResponse::error('An unexpected error occurred.')->jsonSerialize(),
                500,
            );
        }
    }

    /**
     * Get available LLM configurations for the frontend.
     *
     * Returns list of active configurations with identifier, name, and default flag.
     *
     * @param ServerRequestInterface $request Required by TYPO3 AJAX action signature
     */
    public function getConfigurationsAction(ServerRequestInterface $request): ResponseInterface
    {
        $configurations = $this->configurationRepository->findActive();

        $list = [];
        foreach ($configurations as $config) {
            if (!$config instanceof LlmConfiguration) {
                continue;
            }
            $list[] = [
                'identifier' => $config->getIdentifier(),
                'name'       => $config->getName(),
                'isDefault'  => $config->isDefault(),
            ];
        }

        return new JsonResponse([
            'success'        => true,
            'configurations' => $list,
        ]);
    }

    /**
     * Resolve configuration by identifier or return default.
     */
    private function resolveConfiguration(?string $identifier): ?LlmConfiguration
    {
        if ($identifier !== null && $identifier !== '') {
            return $this->configurationRepository->findOneByIdentifier($identifier);
        }

        return $this->configurationRepository->findDefault();
    }

    /**
     * Create ChatOptions from array of options.
     *
     * @param array<string, mixed> $options
     */
    private function createChatOptions(array $options): ?ChatOptions
    {
        if ($options === []) {
            return null;
        }

        $temperature = isset($options['temperature']) && is_numeric($options['temperature'])
            ? (float) $options['temperature']
            : null;
        $maxTokens = isset($options['maxTokens']) && is_numeric($options['maxTokens'])
            ? (int) $options['maxTokens']
            : null;
        $topP = isset($options['topP']) && is_numeric($options['topP'])
            ? (float) $options['topP']
            : null;
        $frequencyPenalty = isset($options['frequencyPenalty']) && is_numeric($options['frequencyPenalty'])
            ? (float) $options['frequencyPenalty']
            : null;
        $presencePenalty = isset($options['presencePenalty']) && is_numeric($options['presencePenalty'])
            ? (float) $options['presencePenalty']
            : null;
        $responseFormat = isset($options['responseFormat']) && is_string($options['responseFormat'])
            ? $options['responseFormat']
            : null;
        $systemPrompt = isset($options['systemPrompt']) && is_string($options['systemPrompt'])
            ? $options['systemPrompt']
            : null;
        /** @var array<int, string>|null $stopSequences */
        $stopSequences = isset($options['stopSequences']) && is_array($options['stopSequences'])
            ? $options['stopSequences']
            : null;
        $provider = isset($options['provider']) && is_string($options['provider'])
            ? $options['provider']
            : null;
        $model = isset($options['model']) && is_string($options['model'])
            ? $options['model']
            : null;

        return new ChatOptions(
            temperature: $temperature,
            maxTokens: $maxTokens,
            topP: $topP,
            frequencyPenalty: $frequencyPenalty,
            presencePenalty: $presencePenalty,
            responseFormat: $responseFormat,
            systemPrompt: $systemPrompt,
            stopSequences: $stopSequences,
            provider: $provider,
            model: $model,
        );
    }
}
