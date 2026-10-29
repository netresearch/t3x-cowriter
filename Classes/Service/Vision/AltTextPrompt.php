<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\T3Cowriter\Service\Vision;

use Netresearch\T3Cowriter\Domain\DTO\VisionRequest;
use Netresearch\T3Cowriter\Service\CallerSource;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Throwable;
use TYPO3\CMS\Core\Configuration\ExtensionConfiguration;

/**
 * The prompt an alt-text request sends to the vision model.
 *
 * Instruction, in this order: the prompt the request sends (the endpoint has
 * always let a request replace the instruction; the editor's toolbar button
 * sends none), the extension setting `altText.prompt`, the built-in default.
 * The language hint is appended in every case. It names the language of the
 * record the editor works on, resolved on the server
 * ({@see RecordLanguageResolverInterface}); there is none when that language
 * cannot be resolved, or when the lookup fails (logged as a warning).
 *
 * @internal
 */
final readonly class AltTextPrompt
{
    public const DEFAULT_INSTRUCTION = 'Generate a concise, descriptive alt text for this image.';

    public function __construct(
        private ExtensionConfiguration $extensionConfiguration,
        private RecordLanguageResolverInterface $languageResolver,
        private LoggerInterface $logger = new NullLogger(),
    ) {}

    public function forRequest(VisionRequest $request): string
    {
        $instruction = $request->prompt !== '' ? $request->prompt : $this->configuredInstruction();

        $language = $request->recordContext === null ? null : $this->languageName($request->recordContext);

        return $language === null ? $instruction : $instruction . "\n\nAnswer in " . $language . '.';
    }

    /**
     * The record's language; null when the lookup fails, so a failing lookup
     * costs the hint, not the alt text.
     *
     * @param array{table: string, uid: int} $recordContext
     */
    private function languageName(array $recordContext): ?string
    {
        try {
            return $this->languageResolver->languageName($recordContext['table'], $recordContext['uid']);
        } catch (Throwable $e) {
            $this->logger->warning('Alt text: the language of the record could not be resolved', [
                'table'     => $recordContext['table'],
                'uid'       => $recordContext['uid'],
                'exception' => $e->getMessage(),
            ]);

            return null;
        }
    }

    /**
     * The extension setting `altText.prompt`; the built-in default when it is
     * empty or cannot be read.
     */
    private function configuredInstruction(): string
    {
        try {
            $value = $this->extensionConfiguration->get(CallerSource::EXTENSION, 'altText/prompt');
        } catch (Throwable) {
            return self::DEFAULT_INSTRUCTION;
        }

        $instruction = is_string($value) ? trim($value) : '';

        return $instruction !== '' ? $instruction : self::DEFAULT_INSTRUCTION;
    }
}
