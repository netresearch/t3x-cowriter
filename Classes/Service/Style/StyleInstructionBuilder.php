<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\T3Cowriter\Service\Style;

use Netresearch\NrLlm\Domain\Model\PromptSnippet;
use Netresearch\NrLlm\Domain\Repository\PromptSnippetRepository;
use Netresearch\NrLlm\Service\Prompt\PromptSnippetComposer;
use Netresearch\T3Cowriter\Domain\DTO\ExecuteTaskRequest;

/**
 * Turns the editor's style choices into one instruction for the request.
 *
 * Audience and tone of voice are nr-llm prompt snippets tagged `audience` and
 * `tone_of_voice`, the tags nr_repurpose uses too, so an operator defines each
 * once. A chosen uid counts only while its snippet is active and carries the
 * tag its selector asked for; anything else is ignored rather than refused,
 * because an operator may switch a snippet off while a dialog is open.
 *
 * Length is relative to the input, or to the content type's target length when
 * the site sets one. The instruction is sent as the last system message before
 * the editor's instruction, so it applies to this request over any tone a
 * configuration sets.
 *
 * @internal
 */
final readonly class StyleInstructionBuilder
{
    public const AUDIENCE_TAG = 'audience';

    public const TONE_TAG = 'tone_of_voice';

    /**
     * Without a target length: one sentence per step; 0 adds nothing.
     */
    private const LENGTH_SENTENCES = [
        -2 => 'Make the text much shorter than the input.',
        -1 => 'Make the text somewhat shorter than the input.',
        1  => 'Make the text somewhat longer than the input.',
        2  => 'Make the text much longer than the input.',
    ];

    /**
     * With a target length: the factor each step applies to it.
     */
    private const LENGTH_FACTORS = [-2 => 0.5, -1 => 0.75, 0 => 1.0, 1 => 1.25, 2 => 1.5];

    public function __construct(
        private PromptSnippetRepository $snippets,
        private PromptSnippetComposer $composer,
        private TargetLengthResolverInterface $targetLength,
    ) {}

    public function build(ExecuteTaskRequest $request): StyleInstruction
    {
        $sections = $this->composer->composeSections([
            'Audience'      => $this->snippet($request->audience, self::AUDIENCE_TAG),
            'Tone of voice' => $this->snippet($request->tone, self::TONE_TAG),
        ]);

        $targetWords = $this->targetLength->targetWords($request->recordContext);
        $length      = $targetWords !== null
            ? sprintf('Aim for about %d words.', (int) round($targetWords * self::LENGTH_FACTORS[$request->length]))
            : (self::LENGTH_SENTENCES[$request->length] ?? '');

        $parts = array_values(array_filter([$sections, $length], static fn (string $part): bool => $part !== ''));
        if ($parts === []) {
            return new StyleInstruction('', $targetWords);
        }

        return new StyleInstruction("For this request:\n\n" . implode("\n\n", $parts), $targetWords);
    }

    /**
     * The snippets an editor may choose from, for the dialog.
     *
     * @return array{audiences: list<array{uid: int, name: string}>, tones: list<array{uid: int, name: string}>}
     */
    public function options(): array
    {
        return [
            'audiences' => $this->optionList(self::AUDIENCE_TAG),
            'tones'     => $this->optionList(self::TONE_TAG),
        ];
    }

    private function snippet(int $uid, string $tag): ?PromptSnippet
    {
        if ($uid < 1) {
            return null;
        }

        foreach ($this->snippets->findByUids([$uid]) as $snippet) {
            if (in_array($tag, $snippet->getTagList(), true)) {
                return $snippet;
            }
        }

        return null;
    }

    /**
     * @return list<array{uid: int, name: string}>
     */
    private function optionList(string $tag): array
    {
        $options = [];
        foreach ($this->snippets->findActiveByTag($tag) as $snippet) {
            $options[] = ['uid' => (int) $snippet->getUid(), 'name' => $snippet->getName()];
        }

        return $options;
    }
}
