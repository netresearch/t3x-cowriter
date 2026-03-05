<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\T3Cowriter\Tests\Unit\Tools;

use Netresearch\T3Cowriter\Tools\ContentQueryTool;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(ContentQueryTool::class)]
final class ContentQueryToolTest extends TestCase
{
    #[Test]
    public function definitionReturnsValidToolSchema(): void
    {
        $definition = ContentQueryTool::definition();

        self::assertSame('function', $definition['type']);
        self::assertSame('query_content', $definition['function']['name']);
        self::assertArrayHasKey('parameters', $definition['function']);
    }

    #[Test]
    public function definitionRequiresPageId(): void
    {
        $definition = ContentQueryTool::definition();
        $required   = $definition['function']['parameters']['required'];

        self::assertContains('pageId', $required);
    }

    #[Test]
    public function definitionIncludesContentTypeProperty(): void
    {
        $definition = ContentQueryTool::definition();
        $properties = $definition['function']['parameters']['properties'];

        self::assertArrayHasKey('contentType', $properties);
        self::assertSame('string', $properties['contentType']['type']);
    }
}
