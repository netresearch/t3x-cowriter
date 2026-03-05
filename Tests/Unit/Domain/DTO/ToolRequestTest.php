<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\T3Cowriter\Tests\Unit\Domain\DTO;

use Netresearch\T3Cowriter\Domain\DTO\ToolRequest;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(ToolRequest::class)]
final class ToolRequestTest extends TestCase
{
    #[Test]
    public function fromRequestBodyParsesAllFields(): void
    {
        $body = [
            'prompt' => 'Find all text elements',
            'tools'  => ['query_content'],
        ];
        $request = ToolRequest::fromRequestBody($body);

        self::assertSame('Find all text elements', $request->prompt);
        self::assertSame(['query_content'], $request->enabledTools);
    }

    #[Test]
    public function fromRequestBodyUsesDefaults(): void
    {
        $body    = ['prompt' => 'Hello'];
        $request = ToolRequest::fromRequestBody($body);

        self::assertSame([], $request->enabledTools);
        self::assertNull($request->configuration);
    }

    #[Test]
    public function fromRequestBodyHandlesMissingFields(): void
    {
        $request = ToolRequest::fromRequestBody([]);

        self::assertSame('', $request->prompt);
        self::assertSame([], $request->enabledTools);
    }

    #[Test]
    public function fromRequestBodyTrimsWhitespace(): void
    {
        $body    = ['prompt' => '  Find elements  '];
        $request = ToolRequest::fromRequestBody($body);

        self::assertSame('Find elements', $request->prompt);
    }

    #[Test]
    public function fromRequestBodyParsesConfiguration(): void
    {
        $body    = ['prompt' => 'Find', 'configuration' => 'claude-fast'];
        $request = ToolRequest::fromRequestBody($body);

        self::assertSame('claude-fast', $request->configuration);
    }

    #[Test]
    public function fromRequestBodyHandlesNonScalarPrompt(): void
    {
        $body    = ['prompt' => ['nested', 'array']];
        $request = ToolRequest::fromRequestBody($body);

        self::assertSame('', $request->prompt);
    }

    #[Test]
    public function fromRequestBodyHandlesNonArrayTools(): void
    {
        $body    = ['prompt' => 'Find', 'tools' => 'not-an-array'];
        $request = ToolRequest::fromRequestBody($body);

        self::assertSame([], $request->enabledTools);
    }

    #[Test]
    public function fromRequestBodyFiltersEmptyToolNames(): void
    {
        $body    = ['prompt' => 'Find', 'tools' => ['query_content', '', '  ', 'another_tool']];
        $request = ToolRequest::fromRequestBody($body);

        self::assertSame(['query_content', 'another_tool'], $request->enabledTools);
    }
}
