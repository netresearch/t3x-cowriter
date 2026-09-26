<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\T3Cowriter\Tests\Unit\Service\Style;

use Netresearch\NrLlm\Domain\Model\PromptSnippet;
use Netresearch\NrLlm\Domain\Repository\PromptSnippetRepository;
use Netresearch\NrLlm\Service\Prompt\PromptSnippetComposer;
use Netresearch\T3Cowriter\Domain\DTO\ExecuteTaskRequest;
use Netresearch\T3Cowriter\Service\Style\StyleInstructionBuilder;
use Netresearch\T3Cowriter\Service\Style\TargetLengthResolverInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(StyleInstructionBuilder::class)]
final class StyleInstructionBuilderTest extends TestCase
{
    /** @var array<int, PromptSnippet> active snippets by uid */
    private array $snippets = [];

    private ?int $targetWords = null;

    #[Test]
    public function noChoiceAddsNothing(): void
    {
        $style = $this->builder()->build($this->request());

        self::assertSame('', $style->message);
        self::assertNull($style->targetWords);
    }

    #[Test]
    public function theChosenAudienceAndToneAreComposedIntoOneInstruction(): void
    {
        $this->snippets[3] = $this->snippet(3, 'Experts', 'Readers are engineers.', 'audience');
        $this->snippets[4] = $this->snippet(4, 'Friendly', 'Warm and direct.', 'tone_of_voice, style');

        $style = $this->builder()->build($this->request(audience: 3, tone: 4));

        self::assertSame(
            "For this request:\n\nAudience:\nReaders are engineers.\n\nTone of voice:\nWarm and direct.",
            $style->message,
        );
    }

    #[Test]
    public function aSnippetWithoutTheSelectorsTagIsIgnored(): void
    {
        $this->snippets[3] = $this->snippet(3, 'Persona', 'You are a pirate.', 'persona');

        self::assertSame('', $this->builder()->build($this->request(audience: 3, tone: 3))->message);
    }

    #[Test]
    public function anUnknownOrInactiveSnippetIsIgnored(): void
    {
        self::assertSame('', $this->builder()->build($this->request(audience: 99))->message);
    }

    /**
     * @return iterable<string, array{int, string}>
     */
    public static function lengthSteps(): iterable
    {
        yield 'much shorter' => [-2, 'Make the text much shorter than the input.'];
        yield 'shorter' => [-1, 'Make the text somewhat shorter than the input.'];
        yield 'longer' => [1, 'Make the text somewhat longer than the input.'];
        yield 'much longer' => [2, 'Make the text much longer than the input.'];
    }

    #[Test]
    #[DataProvider('lengthSteps')]
    public function eachLengthStepAddsItsSentence(int $step, string $sentence): void
    {
        self::assertSame("For this request:\n\n" . $sentence, $this->builder()->build($this->request(length: $step))->message);
    }

    /**
     * @return iterable<string, array{int, int}>
     */
    public static function targetSteps(): iterable
    {
        yield 'much shorter' => [-2, 100];
        yield 'shorter' => [-1, 150];
        yield 'unchanged' => [0, 200];
        yield 'longer' => [1, 250];
        yield 'much longer' => [2, 300];
    }

    #[Test]
    #[DataProvider('targetSteps')]
    public function aTargetLengthIsTheBaselineTheStepScales(int $step, int $words): void
    {
        $this->targetWords = 200;

        $style = $this->builder()->build($this->request(length: $step));

        self::assertSame(sprintf("For this request:\n\nAim for about %d words.", $words), $style->message);
        self::assertSame(200, $style->targetWords);
    }

    #[Test]
    public function theOptionsListTheSnippetsOfEachTag(): void
    {
        $repository = $this->createStub(PromptSnippetRepository::class);
        $repository->method('findActiveByTag')->willReturnMap([
            ['audience', [$this->snippet(3, 'Experts', 'x', 'audience')]],
            ['tone_of_voice', [$this->snippet(4, 'Friendly', 'y', 'tone_of_voice')]],
        ]);
        $builder = new StyleInstructionBuilder($repository, new PromptSnippetComposer(), $this->targetLengthResolver());

        self::assertSame(
            ['audiences' => [['uid' => 3, 'name' => 'Experts']], 'tones' => [['uid' => 4, 'name' => 'Friendly']]],
            $builder->options(),
        );
    }

    private function builder(): StyleInstructionBuilder
    {
        $repository = $this->createStub(PromptSnippetRepository::class);
        $repository->method('findByUids')->willReturnCallback(
            fn (array $uids): array => array_values(array_filter(array_map(fn (int $uid): ?PromptSnippet => $this->snippets[$uid] ?? null, $uids))),
        );

        return new StyleInstructionBuilder($repository, new PromptSnippetComposer(), $this->targetLengthResolver());
    }

    private function targetLengthResolver(): TargetLengthResolverInterface
    {
        $words = $this->targetWords;

        return new class ($words) implements TargetLengthResolverInterface {
            public function __construct(private readonly ?int $words) {}

            public function targetWords(?array $recordContext): ?int
            {
                return $this->words;
            }
        };
    }

    private function request(int $audience = 0, int $tone = 0, int $length = 0): ExecuteTaskRequest
    {
        return new ExecuteTaskRequest(1, 'text', 'selection', 'Improve', null, audience: $audience, tone: $tone, length: $length);
    }

    private function snippet(int $uid, string $name, string $text, string $tags): PromptSnippet
    {
        $snippet = $this->createStub(PromptSnippet::class);
        $snippet->method('getUid')->willReturn($uid);
        $snippet->method('getName')->willReturn($name);
        $snippet->method('getSnippet')->willReturn($text);
        $snippet->method('getTagList')->willReturn(array_map('trim', explode(',', $tags)));

        return $snippet;
    }
}
