<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\T3Cowriter\Tests\Unit\Domain\DTO;

use Netresearch\T3Cowriter\Domain\DTO\CompleteRequest;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\StreamInterface;
use stdClass;

/**
 * Fuzz tests for CompleteRequest DTO.
 *
 * Exercises fromRequest() and isValid() with adversarial, malformed, and
 * boundary-condition inputs to verify robustness against injection attacks,
 * encoding edge cases, and malformed payloads.
 */
#[CoversClass(CompleteRequest::class)]
#[Group('fuzz')]
final class CompleteRequestFuzzTest extends TestCase
{
    // ===================================================
    // Null byte and control character injection
    // ===================================================

    #[Test]
    #[DataProvider('nullByteInjectionProvider')]
    public function fromRequestHandlesNullByteInjection(string $prompt, string $expectedPrompt): void
    {
        $dto = CompleteRequest::fromRequest($this->createRequestWithJsonBody(['prompt' => $prompt]));

        $this->assertSame($expectedPrompt, $dto->prompt);
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function nullByteInjectionProvider(): iterable
    {
        yield 'null byte in prompt' => ["before\x00after", "before\x00after"];
        yield 'null byte at start' => ["\x00prompt", "\x00prompt"];
        yield 'null byte at end' => ["prompt\x00", "prompt\x00"];
        yield 'multiple null bytes' => ["\x00\x00\x00", "\x00\x00\x00"];
        yield 'null byte in model prefix' => ["#cw:gpt\x00-4o Test", "#cw:gpt\x00-4o Test"];
    }

    #[Test]
    #[DataProvider('controlCharacterProvider')]
    public function fromRequestHandlesControlCharacters(string $prompt): void
    {
        $dto = CompleteRequest::fromRequest($this->createRequestWithJsonBody(['prompt' => $prompt]));

        // Must not throw, must return a CompleteRequest instance
        $this->assertInstanceOf(CompleteRequest::class, $dto);
        // isValid() must not throw either
        $dto->isValid();
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function controlCharacterProvider(): iterable
    {
        yield 'bell character' => ["\x07Ring"];
        yield 'backspace' => ["back\x08space"];
        yield 'form feed' => ["page\x0Cbreak"];
        yield 'vertical tab' => ["vertical\x0Btab"];
        yield 'escape sequence' => ["\x1B[31mred\x1B[0m"];
        yield 'delete character' => ["del\x7Fete"];
        yield 'all low control chars' => [implode('', array_map('chr', range(0, 31)))];
    }

    // ===================================================
    // Unicode edge cases
    // ===================================================

    #[Test]
    #[DataProvider('unicodeEdgeCaseProvider')]
    public function fromRequestHandlesUnicodeEdgeCases(string $prompt): void
    {
        $dto = CompleteRequest::fromRequest($this->createRequestWithJsonBody(['prompt' => $prompt]));

        $this->assertInstanceOf(CompleteRequest::class, $dto);
        $dto->isValid();
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function unicodeEdgeCaseProvider(): iterable
    {
        yield 'BOM marker' => ["\xEF\xBB\xBFprompt with BOM"];
        yield 'right-to-left override' => ["\u{202E}dlrow olleH"];
        yield 'zero-width joiner' => ["test\u{200D}join"];
        yield 'zero-width space' => ["invisible\u{200B}space"];
        yield 'replacement character' => ["\u{FFFD}invalid"];
        yield 'combining diacriticals' => ["e\u{0301}e\u{0308}"];
        yield 'surrogate-like (valid UTF-8)' => ["\u{10000}"];
        yield 'max BMP codepoint' => ["\u{FFFF}"];
        yield 'mathematical bold A' => ["\u{1D400}"];
        yield 'emoji sequences' => ["\u{1F468}\u{200D}\u{1F469}\u{200D}\u{1F467}\u{200D}\u{1F466}"];
        yield 'mixed scripts' => ['Hello Мир 世界 مرحبا'];
        yield 'Mongolian vowel separator' => ["test\u{180E}separator"];
        yield 'ideographic space' => ["test\u{3000}space"];
    }

    // ===================================================
    // Injection attack vectors
    // ===================================================

    #[Test]
    #[DataProvider('xssPayloadProvider')]
    public function fromRequestDoesNotExecuteXssPayloads(string $prompt): void
    {
        $dto = CompleteRequest::fromRequest($this->createRequestWithJsonBody(['prompt' => $prompt]));

        // The DTO should store the raw prompt as-is (escaping happens at output layer)
        $this->assertIsString($dto->prompt);
        $this->assertInstanceOf(CompleteRequest::class, $dto);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function xssPayloadProvider(): iterable
    {
        yield 'script tag' => ['<script>alert("xss")</script>'];
        yield 'img onerror' => ['<img src=x onerror=alert(1)>'];
        yield 'svg onload' => ['<svg onload=alert(1)>'];
        yield 'javascript URI' => ['javascript:alert(1)'];
        yield 'data URI' => ['data:text/html,<script>alert(1)</script>'];
        yield 'event handler' => ['<div onmouseover="alert(1)">'];
        yield 'encoded entities' => ['&lt;script&gt;alert(1)&lt;/script&gt;'];
        yield 'unicode escaped' => ['\u003cscript\u003ealert(1)\u003c/script\u003e'];
    }

    #[Test]
    #[DataProvider('sqlInjectionProvider')]
    public function fromRequestDoesNotBreakOnSqlInjection(string $prompt): void
    {
        $dto = CompleteRequest::fromRequest($this->createRequestWithJsonBody(['prompt' => $prompt]));

        $this->assertIsString($dto->prompt);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function sqlInjectionProvider(): iterable
    {
        yield 'single quote' => ["'; DROP TABLE users; --"];
        yield 'union select' => ["' UNION SELECT * FROM users --"];
        yield 'comment bypass' => ["admin'/**/OR/**/1=1--"];
        yield 'stacked queries' => ["1; WAITFOR DELAY '0:0:5'--"];
    }

    #[Test]
    #[DataProvider('prototypeInjectionProvider')]
    public function fromRequestHandlesPrototypePollution(array $body): void
    {
        $dto = CompleteRequest::fromRequest($this->createRequestWithJsonBody($body));

        $this->assertInstanceOf(CompleteRequest::class, $dto);
        // Must not throw regardless of key names
        $dto->isValid();
    }

    /**
     * @return iterable<string, array{array<string, mixed>}>
     */
    public static function prototypeInjectionProvider(): iterable
    {
        yield '__proto__ key' => [['__proto__' => ['isAdmin' => true], 'prompt' => 'test']];
        yield 'constructor key' => [['constructor' => ['prototype' => []], 'prompt' => 'test']];
        yield 'toString override' => [['toString' => 'evil', 'prompt' => 'test']];
        yield 'deeply nested' => [['prompt' => 'test', 'a' => ['b' => ['c' => ['d' => 'deep']]]]];
    }

    // ===================================================
    // Malformed JSON payloads
    // ===================================================

    #[Test]
    #[DataProvider('malformedJsonProvider')]
    public function fromRequestHandlesMalformedJson(string $rawBody): void
    {
        $bodyMock = $this->createStub(StreamInterface::class);
        $bodyMock->method('getContents')->willReturn($rawBody);

        $request = $this->createStub(ServerRequestInterface::class);
        $request->method('getBody')->willReturn($bodyMock);
        $request->method('getParsedBody')->willReturn(null);

        $dto = CompleteRequest::fromRequest($request);

        $this->assertInstanceOf(CompleteRequest::class, $dto);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function malformedJsonProvider(): iterable
    {
        yield 'truncated JSON' => ['{"prompt":"test'];
        yield 'trailing comma' => ['{"prompt":"test",}'];
        yield 'single quotes' => ["{'prompt':'test'}"];
        yield 'no quotes on key' => ['{prompt:"test"}'];
        yield 'binary data' => ["\x89PNG\r\n\x1a\n"];
        yield 'XML document' => ['<?xml version="1.0"?><root/>'];
        yield 'just null' => ['null'];
        yield 'just number' => ['42'];
        yield 'just string' => ['"hello"'];
        yield 'just boolean' => ['true'];
        yield 'just array' => ['["a","b"]'];
        yield 'empty object' => ['{}'];
        yield 'nested deeply' => [str_repeat('[', 100) . '1' . str_repeat(']', 100)];
        yield 'very large number' => ['{"prompt":"test","n":' . str_repeat('9', 1000) . '}'];
        yield 'unicode escape' => ['{"prompt":"\\u0048\\u0065\\u006c\\u006c\\u006f"}'];
    }

    // ===================================================
    // Boundary conditions
    // ===================================================

    #[Test]
    public function fromRequestHandlesExtremelyLongPrompt(): void
    {
        // 1MB string
        $longPrompt = str_repeat('A', 1_048_576);
        $dto        = CompleteRequest::fromRequest(
            $this->createRequestWithJsonBody(['prompt' => $longPrompt]),
        );

        $this->assertSame($longPrompt, $dto->prompt);
        $this->assertFalse($dto->isValid());
    }

    #[Test]
    public function fromRequestHandlesExtremelyLongModelName(): void
    {
        $longModel = str_repeat('a', 10000);
        $dto       = CompleteRequest::fromRequest(
            $this->createRequestWithJsonBody(['prompt' => '#cw:' . $longModel . ' test']),
        );

        // Model name should still be parsed (it matches the pattern)
        $this->assertInstanceOf(CompleteRequest::class, $dto);
    }

    #[Test]
    public function fromRequestHandlesEmptyStringBody(): void
    {
        $bodyMock = $this->createStub(StreamInterface::class);
        $bodyMock->method('getContents')->willReturn('');

        $request = $this->createStub(ServerRequestInterface::class);
        $request->method('getBody')->willReturn($bodyMock);
        $request->method('getParsedBody')->willReturn(null);

        $dto = CompleteRequest::fromRequest($request);

        $this->assertSame('', $dto->prompt);
        $this->assertFalse($dto->isValid());
    }

    #[Test]
    #[DataProvider('modelOverrideEdgeCaseProvider')]
    public function fromRequestHandlesModelOverrideEdgeCases(string $prompt, ?string $expectedModel, string $expectedPrompt): void
    {
        $dto = CompleteRequest::fromRequest($this->createRequestWithJsonBody(['prompt' => $prompt]));

        $this->assertSame($expectedModel, $dto->modelOverride);
        $this->assertSame($expectedPrompt, $dto->prompt);
    }

    /**
     * @return iterable<string, array{string, ?string, string}>
     */
    public static function modelOverrideEdgeCaseProvider(): iterable
    {
        yield 'only prefix no model' => ['#cw: text', null, '#cw: text'];
        yield 'prefix without space after model' => ['#cw:modeltext', null, '#cw:modeltext'];
        yield 'double prefix' => ['#cw:gpt-4o #cw:claude test', 'gpt-4o', '#cw:claude test'];
        yield 'prefix with newline' => ["#cw:gpt-4o\ntest", 'gpt-4o', 'test'];
        yield 'prefix with tab' => ["#cw:gpt-4o\ttest", 'gpt-4o', 'test'];
        yield 'case sensitive prefix' => ['#CW:gpt-4o test', null, '#CW:gpt-4o test'];
        yield 'prefix mid-string' => ['hello #cw:gpt-4o test', null, 'hello #cw:gpt-4o test'];
        yield 'model with all valid chars' => ['#cw:a-b_c.d:e/f test', 'a-b_c.d:e/f', 'test'];
        yield 'model max valid pattern' => ['#cw:' . str_repeat('a', 200) . ' test', str_repeat('a', 200), 'test'];
    }

    // ===================================================
    // Configuration field edge cases
    // ===================================================

    #[Test]
    #[DataProvider('configurationFuzzProvider')]
    public function fromRequestHandlesConfigurationEdgeCases(mixed $configuration, ?string $expected): void
    {
        $dto = CompleteRequest::fromRequest(
            $this->createRequestWithJsonBody(['prompt' => 'test', 'configuration' => $configuration]),
        );

        $this->assertSame($expected, $dto->configuration);
    }

    /**
     * @return iterable<string, array{mixed, ?string}>
     */
    public static function configurationFuzzProvider(): iterable
    {
        yield 'whitespace only' => ['   ', '   '];
        yield 'path traversal' => ['../../etc/passwd', '../../etc/passwd'];
        yield 'null byte config' => ["config\x00id", "config\x00id"];
        yield 'very long config' => [str_repeat('x', 10000), str_repeat('x', 10000)];
        yield 'numeric config' => [42, '42'];
        yield 'boolean true config' => [true, '1'];
        yield 'float config' => [3.14, '3.14'];
        yield 'object config' => [new stdClass(), null];
        yield 'empty array config' => [[], null];
        yield 'null config' => [null, null];
        yield 'empty string config' => ['', null];
    }

    // ===================================================
    // Type coercion stress tests
    // ===================================================

    #[Test]
    #[DataProvider('typeCoercionProvider')]
    public function fromRequestHandlesTypeCoercion(mixed $promptValue, string $expectedPrompt): void
    {
        $dto = CompleteRequest::fromRequest(
            $this->createRequestWithJsonBody(['prompt' => $promptValue]),
        );

        $this->assertSame($expectedPrompt, $dto->prompt);
    }

    /**
     * @return iterable<string, array{mixed, string}>
     */
    public static function typeCoercionProvider(): iterable
    {
        yield 'zero' => [0, '0'];
        yield 'negative zero' => [-0, '0'];
        yield 'negative integer' => [-1, '-1'];
        yield 'max int' => [PHP_INT_MAX, (string) PHP_INT_MAX];
        yield 'min int' => [PHP_INT_MIN, (string) PHP_INT_MIN];
        yield 'float epsilon' => [PHP_FLOAT_EPSILON, (string) PHP_FLOAT_EPSILON];
        // Note: INF, -INF, NAN not testable via json_encode (returns false, incompatible with StreamInterface)
        yield 'nested array' => [[[['deep']]], ''];
        yield 'empty array' => [[], ''];
        yield 'object' => [new stdClass(), ''];
    }

    // ===================================================
    // Randomized payload tests
    // ===================================================

    #[Test]
    public function fromRequestSurvivesRandomBinaryPayloads(): void
    {
        // Generate 50 random binary payloads and verify no crashes
        for ($i = 0; $i < 50; ++$i) {
            $length    = random_int(0, 1000);
            $binaryStr = random_bytes($length);

            $bodyMock = $this->createStub(StreamInterface::class);
            $bodyMock->method('getContents')->willReturn($binaryStr);

            $request = $this->createStub(ServerRequestInterface::class);
            $request->method('getBody')->willReturn($bodyMock);
            $request->method('getParsedBody')->willReturn(null);

            $dto = CompleteRequest::fromRequest($request);

            $this->assertInstanceOf(CompleteRequest::class, $dto);
            // isValid() must not throw
            $dto->isValid();
        }
    }

    #[Test]
    public function fromRequestSurvivesRandomUnicodePrompts(): void
    {
        $unicodeRanges = [
            [0x0020, 0x007E],   // Basic Latin
            [0x00A0, 0x00FF],   // Latin-1 Supplement
            [0x0100, 0x024F],   // Latin Extended
            [0x0400, 0x04FF],   // Cyrillic
            [0x0600, 0x06FF],   // Arabic
            [0x4E00, 0x4FFF],   // CJK (subset)
            [0x1F600, 0x1F64F], // Emoticons
        ];

        for ($i = 0; $i < 50; ++$i) {
            $prompt = '';
            $len    = random_int(1, 100);
            for ($j = 0; $j < $len; ++$j) {
                $range     = $unicodeRanges[array_rand($unicodeRanges)];
                $codePoint = random_int($range[0], $range[1]);
                $prompt .= mb_chr($codePoint, 'UTF-8');
            }

            $dto = CompleteRequest::fromRequest(
                $this->createRequestWithJsonBody(['prompt' => $prompt]),
            );

            $this->assertInstanceOf(CompleteRequest::class, $dto);
            $dto->isValid();
        }
    }

    #[Test]
    public function constructorAndIsValidNeverThrowForAnyInput(): void
    {
        $adversarialPrompts = [
            '',
            ' ',
            "\t\n\r",
            str_repeat('a', 100000),
            "\x00\x01\x02\x03",
            'normal prompt',
            '<script>alert(1)</script>',
            '{"json":"in prompt"}',
            "line1\nline2\nline3",
            str_repeat("\u{FFFD}", 100),
        ];

        foreach ($adversarialPrompts as $prompt) {
            $dto = new CompleteRequest(
                prompt: $prompt,
                configuration: null,
                modelOverride: null,
            );

            // Must not throw
            $result = $dto->isValid();
            $this->assertIsBool($result);
        }
    }

    // ===================================================
    // Helpers
    // ===================================================

    /**
     * @param array<string, mixed> $data
     */
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
