<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\T3Cowriter\Tests\Unit\Domain\DTO;

use Netresearch\T3Cowriter\Domain\DTO\VisionRequest;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(VisionRequest::class)]
final class VisionRequestTest extends TestCase
{
    #[Test]
    public function fromRequestBodyParsesImageUrl(): void
    {
        $body    = ['imageUrl' => 'https://example.com/image.jpg', 'prompt' => 'Generate alt text'];
        $request = VisionRequest::fromRequestBody($body);

        self::assertSame('https://example.com/image.jpg', $request->imageUrl);
        self::assertSame('Generate alt text', $request->prompt);
    }

    /**
     * The DTO no longer fills in a default instruction: an empty prompt tells
     * AltTextPrompt that the request sent none, so the configured instruction
     * applies.
     */
    #[Test]
    public function fromRequestBodyLeavesTheMissingPromptEmpty(): void
    {
        $body    = ['imageUrl' => 'https://example.com/image.jpg'];
        $request = VisionRequest::fromRequestBody($body);

        self::assertSame('', $request->prompt);
    }

    #[Test]
    public function fromRequestBodyTakesTableAndUidOfTheRecordContext(): void
    {
        $request = VisionRequest::fromRequestBody([
            'imageUrl'      => 'https://example.com/image.jpg',
            'recordContext' => ['table' => 'tt_content', 'uid' => '42', 'field' => 'bodytext'],
        ]);

        self::assertSame(['table' => 'tt_content', 'uid' => 42], $request->recordContext);
    }

    #[Test]
    public function fromRequestBodyKeepsTheCaseOfTheTableName(): void
    {
        $request = VisionRequest::fromRequestBody([
            'imageUrl'      => 'https://example.com/image.jpg',
            'recordContext' => ['table' => 'tx_Shop_Product', 'uid' => 3],
        ]);

        self::assertSame(['table' => 'tx_Shop_Product', 'uid' => 3], $request->recordContext);
    }

    /**
     * @return iterable<string, array{mixed}>
     */
    public static function malformedRecordContexts(): iterable
    {
        yield 'missing' => [null];
        yield 'not an array' => ['tt_content:42'];
        yield 'uid zero' => [['table' => 'tt_content', 'uid' => 0]];
        yield 'uid not numeric' => [['table' => 'tt_content', 'uid' => 'x']];
        yield 'table not string' => [['table' => ['tt_content'], 'uid' => 42]];
        yield 'table with quote' => [['table' => "tt_content'", 'uid' => 42]];
        yield 'table starts digit' => [['table' => '1table', 'uid' => 42]];
    }

    #[Test]
    #[DataProvider('malformedRecordContexts')]
    public function fromRequestBodyDropsAMalformedRecordContext(mixed $recordContext): void
    {
        $request = VisionRequest::fromRequestBody(['imageUrl' => 'https://example.com/image.jpg', 'recordContext' => $recordContext]);

        self::assertNull($request->recordContext);
        self::assertTrue($request->isValid());
    }

    #[Test]
    public function fromRequestBodyTrimsWhitespace(): void
    {
        $body    = ['imageUrl' => '  https://example.com/image.jpg  ', 'prompt' => '  test  '];
        $request = VisionRequest::fromRequestBody($body);

        self::assertSame('https://example.com/image.jpg', $request->imageUrl);
        self::assertSame('test', $request->prompt);
    }

    #[Test]
    public function fromRequestBodyHandlesMissingImageUrl(): void
    {
        $request = VisionRequest::fromRequestBody([]);

        self::assertSame('', $request->imageUrl);
    }

    #[Test]
    public function fromRequestBodyHandlesNonScalarImageUrl(): void
    {
        $body    = ['imageUrl' => ['nested', 'array'], 'prompt' => 'Generate alt text'];
        $request = VisionRequest::fromRequestBody($body);

        // Non-scalar values fall back to default empty string
        self::assertSame('', $request->imageUrl);
        self::assertSame('Generate alt text', $request->prompt);
    }

    #[Test]
    public function fromRequestBodyHandlesNonScalarPrompt(): void
    {
        $body    = ['imageUrl' => 'https://example.com/image.jpg', 'prompt' => ['array', 'value']];
        $request = VisionRequest::fromRequestBody($body);

        // A non-scalar prompt counts as no prompt
        self::assertSame('https://example.com/image.jpg', $request->imageUrl);
        self::assertSame('', $request->prompt);
    }

    #[Test]
    public function isValidReturnsTrueForNormalInput(): void
    {
        $request = new VisionRequest(imageUrl: 'https://example.com/img.jpg', prompt: 'Describe this image');
        self::assertTrue($request->isValid());
    }

    #[Test]
    public function isValidReturnsTrueAtExactMaxImageUrlLength(): void
    {
        $request = new VisionRequest(imageUrl: str_repeat('a', 32768));
        self::assertTrue($request->isValid());
    }

    #[Test]
    public function isValidReturnsFalseAtOneOverMaxImageUrlLength(): void
    {
        $request = new VisionRequest(imageUrl: str_repeat('a', 32769));
        self::assertFalse($request->isValid());
    }

    #[Test]
    public function isValidReturnsFalseForExcessiveImageUrlLength(): void
    {
        $request = new VisionRequest(imageUrl: str_repeat('a', 40000));
        self::assertFalse($request->isValid());
    }

    #[Test]
    public function isValidReturnsTrueAtExactMaxPromptLength(): void
    {
        $request = new VisionRequest(imageUrl: 'https://example.com/img.jpg', prompt: str_repeat('a', 32768));
        self::assertTrue($request->isValid());
    }

    #[Test]
    public function isValidReturnsFalseAtOneOverMaxPromptLength(): void
    {
        $request = new VisionRequest(imageUrl: 'https://example.com/img.jpg', prompt: str_repeat('a', 32769));
        self::assertFalse($request->isValid());
    }

    #[Test]
    public function isValidReturnsFalseForExcessivePromptLength(): void
    {
        $request = new VisionRequest(imageUrl: 'https://example.com/img.jpg', prompt: str_repeat('a', 40000));
        self::assertFalse($request->isValid());
    }
}
