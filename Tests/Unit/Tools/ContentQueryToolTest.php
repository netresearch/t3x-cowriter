<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\T3Cowriter\Tests\Unit\Tools;

use Netresearch\NrLlm\Domain\ValueObject\ToolSpec;
use Netresearch\T3Cowriter\Tools\ContentQueryTool;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(ContentQueryTool::class)]
final class ContentQueryToolTest extends TestCase
{
    #[Test]
    public function specReturnsValidToolSpec(): void
    {
        $spec = ContentQueryTool::spec();

        self::assertSame(ToolSpec::TYPE_FUNCTION, $spec->type);
        self::assertSame('query_content', $spec->name);
        self::assertArrayHasKey('properties', $spec->parameters);
    }

    #[Test]
    public function specRequiresPageId(): void
    {
        $required = ContentQueryTool::spec()->parameters['required'];

        self::assertContains('pageId', $required);
    }

    #[Test]
    public function specIncludesContentTypeProperty(): void
    {
        $properties = ContentQueryTool::spec()->parameters['properties'];

        self::assertArrayHasKey('contentType', $properties);
        self::assertSame('string', $properties['contentType']['type']);
    }
}
