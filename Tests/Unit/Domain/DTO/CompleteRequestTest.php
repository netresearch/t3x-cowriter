<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\T3Cowriter\Tests\Unit\Domain\DTO;

use Netresearch\T3Cowriter\Domain\DTO\CompleteRequest;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\StreamInterface;

#[CoversClass(CompleteRequest::class)]
#[AllowMockObjectsWithoutExpectations]
final class CompleteRequestTest extends TestCase
{
    #[Test]
    public function fromRequestExtractsPromptCorrectly(): void
    {
        $request = $this->createRequestWithJsonBody(['prompt' => 'Improve this text']);

        $dto = CompleteRequest::fromRequest($request);

        $this->assertSame('Improve this text', $dto->prompt);
        $this->assertNull($dto->configuration);
        $this->assertNull($dto->modelOverride);
    }

    #[Test]
    public function fromRequestExtractsConfigurationIdentifier(): void
    {
        $request = $this->createRequestWithJsonBody([
            'prompt'        => 'Test',
            'configuration' => 'my-config',
        ]);

        $dto = CompleteRequest::fromRequest($request);

        $this->assertSame('my-config', $dto->configuration);
    }

    #[Test]
    public function fromRequestParsesModelOverridePrefix(): void
    {
        $request = $this->createRequestWithJsonBody([
            'prompt' => '#cw:gpt-4o Improve this text',
        ]);

        $dto = CompleteRequest::fromRequest($request);

        $this->assertSame('gpt-4o', $dto->modelOverride);
        $this->assertSame('Improve this text', $dto->prompt);
    }

    #[Test]
    public function fromRequestParsesModelOverrideWithComplexModelName(): void
    {
        $request = $this->createRequestWithJsonBody([
            'prompt' => '#cw:claude-3-5-sonnet-20241022 Write a haiku',
        ]);

        $dto = CompleteRequest::fromRequest($request);

        $this->assertSame('claude-3-5-sonnet-20241022', $dto->modelOverride);
        $this->assertSame('Write a haiku', $dto->prompt);
    }

    #[Test]
    public function fromRequestIgnoresInvalidModelOverridePrefix(): void
    {
        $request = $this->createRequestWithJsonBody([
            'prompt' => '#cw: Invalid because space after colon',
        ]);

        $dto = CompleteRequest::fromRequest($request);

        // No model override because there's no model name immediately after #cw:
        $this->assertNull($dto->modelOverride);
        $this->assertSame('#cw: Invalid because space after colon', $dto->prompt);
    }

    #[Test]
    public function fromRequestPreservesPromptWithoutPrefix(): void
    {
        $request = $this->createRequestWithJsonBody([
            'prompt' => 'Just a regular prompt without prefix',
        ]);

        $dto = CompleteRequest::fromRequest($request);

        $this->assertNull($dto->modelOverride);
        $this->assertSame('Just a regular prompt without prefix', $dto->prompt);
    }

    #[Test]
    public function isValidReturnsTrueForValidPrompt(): void
    {
        $dto = new CompleteRequest(
            prompt: 'Valid prompt',
            configuration: null,
            modelOverride: null,
        );

        $this->assertTrue($dto->isValid());
    }

    #[Test]
    public function isValidReturnsFalseForEmptyPrompt(): void
    {
        $dto = new CompleteRequest(
            prompt: '',
            configuration: null,
            modelOverride: null,
        );

        $this->assertFalse($dto->isValid());
    }

    #[Test]
    public function isValidReturnsFalseForWhitespaceOnlyPrompt(): void
    {
        $dto = new CompleteRequest(
            prompt: '   ',
            configuration: null,
            modelOverride: null,
        );

        $this->assertFalse($dto->isValid());
    }

    #[Test]
    #[DataProvider('nonStringPromptProvider')]
    public function fromRequestHandlesNonStringPromptValues(array $body, string $expectedPrompt): void
    {
        $request = $this->createRequestWithJsonBody($body);

        $dto = CompleteRequest::fromRequest($request);

        $this->assertSame($expectedPrompt, $dto->prompt);
    }

    /**
     * @return iterable<string, array{array<string, mixed>, string}>
     */
    public static function nonStringPromptProvider(): iterable
    {
        yield 'missing prompt' => [[], ''];
        yield 'null prompt' => [['prompt' => null], ''];
        yield 'integer prompt' => [['prompt' => 123], '123'];
        yield 'float prompt' => [['prompt' => 1.5], '1.5'];
        yield 'boolean true' => [['prompt' => true], '1'];
        yield 'boolean false' => [['prompt' => false], ''];
        yield 'array prompt' => [['prompt' => ['nested']], ''];
    }

    #[Test]
    #[DataProvider('validModelNameProvider')]
    public function fromRequestAcceptsValidModelNames(string $modelName): void
    {
        $request = $this->createRequestWithJsonBody([
            'prompt' => '#cw:' . $modelName . ' Test prompt',
        ]);

        $dto = CompleteRequest::fromRequest($request);

        $this->assertSame($modelName, $dto->modelOverride);
        $this->assertSame('Test prompt', $dto->prompt);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function validModelNameProvider(): iterable
    {
        yield 'simple name' => ['gpt-4o'];
        yield 'with version' => ['claude-3-5-sonnet-20241022'];
        yield 'with underscore' => ['gpt_4_turbo'];
        yield 'with dot' => ['gpt-4.5'];
        yield 'with colon' => ['openai:gpt-4'];
        yield 'with slash' => ['mistral/mixtral-8x7b'];
        yield 'complex name' => ['openrouter:anthropic/claude-3-opus'];
    }

    #[Test]
    #[DataProvider('invalidModelNameProvider')]
    public function fromRequestRejectsInvalidModelNames(string $modelName): void
    {
        $request = $this->createRequestWithJsonBody([
            'prompt' => '#cw:' . $modelName . ' Test prompt',
        ]);

        $dto = CompleteRequest::fromRequest($request);

        // Invalid model name should be ignored, prompt kept as-is
        $this->assertNull($dto->modelOverride);
        $this->assertSame('#cw:' . $modelName . ' Test prompt', $dto->prompt);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function invalidModelNameProvider(): iterable
    {
        yield 'starts with hyphen' => ['-gpt-4'];
        yield 'contains semicolon' => ['gpt;4'];
        yield 'contains ampersand' => ['gpt&4'];
        yield 'contains pipe' => ['gpt|4'];
        yield 'contains backtick' => ['gpt`4'];
        yield 'contains dollar' => ['$model'];
        yield 'contains angle bracket' => ['<script>'];
        yield 'contains quote' => ["model'name"];
        yield 'contains double quote' => ['model"name'];
    }

    #[Test]
    public function isValidReturnsTrueForPromptAtExactMaxLength(): void
    {
        $maxLengthPrompt = str_repeat('a', 32768);
        $dto             = new CompleteRequest(
            prompt: $maxLengthPrompt,
            configuration: null,
            modelOverride: null,
        );

        $this->assertTrue($dto->isValid());
    }

    #[Test]
    public function isValidReturnsFalseForPromptExceedingMaxLength(): void
    {
        $tooLongPrompt = str_repeat('a', 32769);
        $dto           = new CompleteRequest(
            prompt: $tooLongPrompt,
            configuration: null,
            modelOverride: null,
        );

        $this->assertFalse($dto->isValid());
    }

    #[Test]
    public function fromRequestUsesFormDataWhenNoJsonBody(): void
    {
        $bodyMock = $this->createMock(StreamInterface::class);
        $bodyMock->method('getContents')->willReturn('');

        $request = $this->createMock(ServerRequestInterface::class);
        $request->method('getBody')->willReturn($bodyMock);
        $request->method('getParsedBody')->willReturn([
            'prompt'        => 'From form data',
            'configuration' => 'form-config',
        ]);

        $dto = CompleteRequest::fromRequest($request);

        $this->assertSame('From form data', $dto->prompt);
        $this->assertSame('form-config', $dto->configuration);
    }

    // ===========================================
    // Cycle 32: Edge Case Coverage Tests
    // ===========================================

    #[Test]
    public function fromRequestWithModelOverrideAndMultipleSpaces(): void
    {
        $request = $this->createRequestWithJsonBody([
            'prompt' => '#cw:gpt-4o   Write with multiple spaces',
        ]);

        $dto = CompleteRequest::fromRequest($request);

        $this->assertSame('gpt-4o', $dto->modelOverride);
        $this->assertSame('Write with multiple spaces', $dto->prompt);
    }

    #[Test]
    public function fromRequestTrimsPromptAfterModelOverrideExtraction(): void
    {
        // Kills UnwrapTrim mutant: trim(substr(...)) → substr(...)
        // The prompt after prefix extraction has trailing whitespace
        $request = $this->createRequestWithJsonBody([
            'prompt' => '#cw:gpt-4o  hello  ',
        ]);

        $dto = CompleteRequest::fromRequest($request);

        $this->assertSame('gpt-4o', $dto->modelOverride);
        // With trim: 'hello' (trailing spaces removed)
        // Without trim: 'hello  ' (trailing spaces preserved)
        $this->assertSame('hello', $dto->prompt);
    }

    #[Test]
    public function fromRequestWithModelOverrideButOnlyWhitespaceAfterPrefix(): void
    {
        $request = $this->createRequestWithJsonBody([
            'prompt' => '#cw:gpt-4o   ',
        ]);

        $dto = CompleteRequest::fromRequest($request);

        $this->assertSame('gpt-4o', $dto->modelOverride);
        $this->assertSame('', $dto->prompt);
        $this->assertFalse($dto->isValid());
    }

    #[Test]
    public function fromRequestWithModelNameStartingWithDigit(): void
    {
        $request = $this->createRequestWithJsonBody([
            'prompt' => '#cw:9gpt-4 Test prompt',
        ]);

        $dto = CompleteRequest::fromRequest($request);

        // Digit-first model names are valid per pattern [a-zA-Z0-9]
        $this->assertSame('9gpt-4', $dto->modelOverride);
        $this->assertSame('Test prompt', $dto->prompt);
    }

    #[Test]
    public function isValidHandlesUnicodeContentLength(): void
    {
        // Multi-byte characters: each emoji is 1 character but multiple bytes
        $maxLengthUnicode = str_repeat('🎉', 32768);
        $dto              = new CompleteRequest(
            prompt: $maxLengthUnicode,
            configuration: null,
            modelOverride: null,
        );

        $this->assertTrue($dto->isValid());

        $tooLongUnicode = str_repeat('🎉', 32769);
        $dto2           = new CompleteRequest(
            prompt: $tooLongUnicode,
            configuration: null,
            modelOverride: null,
        );

        $this->assertFalse($dto2->isValid());
    }

    #[Test]
    public function fromRequestWithNullParsedBodyAndEmptyJsonContents(): void
    {
        $bodyMock = $this->createMock(StreamInterface::class);
        $bodyMock->method('getContents')->willReturn('');

        $request = $this->createMock(ServerRequestInterface::class);
        $request->method('getBody')->willReturn($bodyMock);
        $request->method('getParsedBody')->willReturn(null);

        $dto = CompleteRequest::fromRequest($request);

        $this->assertSame('', $dto->prompt);
        $this->assertNull($dto->configuration);
        $this->assertNull($dto->modelOverride);
    }

    #[Test]
    public function fromRequestWithNullParsedBodyAndInvalidJsonContents(): void
    {
        $bodyMock = $this->createMock(StreamInterface::class);
        $bodyMock->method('getContents')->willReturn('not json at all');

        $request = $this->createMock(ServerRequestInterface::class);
        $request->method('getBody')->willReturn($bodyMock);
        $request->method('getParsedBody')->willReturn(null);

        $dto = CompleteRequest::fromRequest($request);

        $this->assertSame('', $dto->prompt);
    }

    #[Test]
    public function fromRequestWithEmptyStringConfiguration(): void
    {
        $request = $this->createRequestWithJsonBody([
            'prompt'        => 'Test',
            'configuration' => '',
        ]);

        $dto = CompleteRequest::fromRequest($request);

        // Empty string configuration should be treated as null
        $this->assertNull($dto->configuration);
    }

    #[Test]
    public function fromRequestWithArrayConfiguration(): void
    {
        $request = $this->createRequestWithJsonBody([
            'prompt'        => 'Test',
            'configuration' => ['nested' => 'value'],
        ]);

        $dto = CompleteRequest::fromRequest($request);

        // Non-scalar configuration should be treated as null
        $this->assertNull($dto->configuration);
    }

    private function createRequestWithJsonBody(array $data): ServerRequestInterface
    {
        $bodyStub = $this->createStub(StreamInterface::class);
        $bodyStub->method('getContents')->willReturn(json_encode($data));

        $request = $this->createStub(ServerRequestInterface::class);
        $request->method('getBody')->willReturn($bodyStub);
        $request->method('getParsedBody')->willReturn(null);

        return $request;
    }
}
