<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\T3Cowriter\Controller;

use Netresearch\NrLlm\Specialized\Image\DallEImageService;
use Netresearch\T3Cowriter\Domain\DTO\ImageGenerationRequest;
use Netresearch\T3Cowriter\Service\BackendLabels;
use Netresearch\T3Cowriter\Service\FieldSuggestion\FieldSuggestionException;
use Netresearch\T3Cowriter\Service\FieldSuggestion\RecordContextReader;
use Netresearch\T3Cowriter\Service\Image\ImageGenerationConfiguration;
use Netresearch\T3Cowriter\Service\Image\ImagePurpose;
use Netresearch\T3Cowriter\Service\Transparency\AboutBuilder;
use Netresearch\T3Cowriter\Service\Transparency\ModelUse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;
use TYPO3\CMS\Core\Http\JsonResponse;

/**
 * Read-only AJAX route behind the image dialog: what the image is for, a
 * proposal for its description built from the record and its page, and the
 * "About this suggestion" section with the model the generation route will
 * use. No model is asked, nothing is written, and the request does not count
 * against the rate limit.
 *
 * The record is read with the checks of a field suggestion (the user may
 * edit the table, the field, the record and content on its page). A record
 * the user may not edit and one that does not exist get the same answer: no
 * purpose and no proposal, so the route does not tell which uids exist.
 *
 * @internal
 */
final readonly class ImageContextController
{
    public function __construct(
        private ImageGenerationConfiguration $imageConfiguration,
        private DallEImageService $imageService,
        private RecordContextReader $recordContextReader,
        private AboutBuilder $about,
        private ImagePurpose $purpose = new ImagePurpose(),
        private BackendLabels $labels = new BackendLabels(),
    ) {}

    public function contextAction(ServerRequestInterface $request): ResponseInterface
    {
        if (!$this->imageConfiguration->isEnabled()) {
            return new JsonResponse(['success' => false, 'error' => $this->labels->get('error.image.disabled')], 403);
        }

        $backendUser = $GLOBALS['BE_USER'] ?? null;
        if (!$backendUser instanceof BackendUserAuthentication) {
            return new JsonResponse(['success' => false, 'error' => 'No backend user session.'], 403);
        }

        $query = $request->getQueryParams();
        $table = $this->name($query['table'] ?? null);
        $field = $this->name($query['field'] ?? null);

        $described = ['purpose' => '', 'field' => '', 'proposal' => ''];
        if ($table !== '' && $field !== '') {
            try {
                $context   = $this->recordContextReader->readForImage($table, $field, $this->number($query['uid'] ?? null), $this->number($query['pid'] ?? null), $backendUser);
                $described = $this->purpose->describe($context, $field);
            } catch (FieldSuggestionException) {
                // Not the editor's record, or no record: no purpose, no proposal.
            }
        }

        // The model the generation route resolves the same way: the setting,
        // else nr-llm's default image model, else the fallback.
        $configured = $this->imageConfiguration->model();
        $model      = $configured ?? $this->imageService->resolveDefaultModel(ImageGenerationConfiguration::FALLBACK_MODEL);

        return new JsonResponse([
            'success'  => true,
            'purpose'  => $described['purpose'],
            'field'    => $described['field'],
            'proposal' => $described['proposal'],
            'about'    => $this->about->imageGeneration(
                ModelUse::withoutConfiguration($model, ''),
                $configured !== null,
                ImageGenerationRequest::MAX_PROMPT_LENGTH,
                $backendUser,
            ),
        ]);
    }

    /**
     * A table or field name as TCA writes them; anything else is ''.
     */
    private function name(mixed $value): string
    {
        return is_string($value) && preg_match('/^[a-zA-Z0-9_]{1,64}$/', $value) === 1 ? $value : '';
    }

    /**
     * A positive uid or pid; "NEW…" ids of unsaved records and anything else are 0.
     */
    private function number(mixed $value): int
    {
        return is_string($value) && ctype_digit($value) ? (int) $value : (is_int($value) ? max(0, $value) : 0);
    }
}
