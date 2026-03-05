<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\T3Cowriter\Tests\Unit\Controller;

use ArrayIterator;
use Netresearch\NrLlm\Domain\Model\PromptTemplate;
use Netresearch\NrLlm\Domain\Repository\PromptTemplateRepository;
use Netresearch\T3Cowriter\Controller\TemplateController;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Log\NullLogger;
use RuntimeException;
use stdClass;
use TYPO3\CMS\Extbase\Persistence\QueryResultInterface;

#[CoversClass(TemplateController::class)]
final class TemplateControllerTest extends TestCase
{
    private PromptTemplateRepository&Stub $templateRepoStub;
    private TemplateController $subject;

    protected function setUp(): void
    {
        $this->templateRepoStub = $this->createStub(PromptTemplateRepository::class);
        $this->subject          = new TemplateController(
            $this->templateRepoStub,
            new NullLogger(),
        );
    }

    #[Test]
    public function listActionReturnsTemplates(): void
    {
        $template = $this->createStub(PromptTemplate::class);
        $template->method('getIdentifier')->willReturn('blog_post');
        $template->method('getTitle')->willReturn('Blog Post');
        $template->method('getDescription')->willReturn('Generate a blog post');
        $template->method('getFeature')->willReturn('content');

        $this->templateRepoStub->method('findActive')
            ->willReturn($this->createQueryResult([$template]));

        $request  = $this->createStub(ServerRequestInterface::class);
        $response = $this->subject->listAction($request);

        $data = json_decode((string) $response->getBody(), true);
        self::assertTrue($data['success']);
        self::assertCount(1, $data['templates']);
        self::assertSame('blog_post', $data['templates'][0]['identifier']);
        self::assertSame('Blog Post', $data['templates'][0]['name']);
    }

    #[Test]
    public function listActionReturnsEmptyArrayWhenNoTemplates(): void
    {
        $this->templateRepoStub->method('findActive')
            ->willReturn($this->createQueryResult([]));

        $request  = $this->createStub(ServerRequestInterface::class);
        $response = $this->subject->listAction($request);

        $data = json_decode((string) $response->getBody(), true);
        self::assertTrue($data['success']);
        self::assertCount(0, $data['templates']);
    }

    #[Test]
    public function listActionReturns500OnError(): void
    {
        $this->templateRepoStub->method('findActive')
            ->willThrowException(new RuntimeException('DB error'));

        $request  = $this->createStub(ServerRequestInterface::class);
        $response = $this->subject->listAction($request);

        self::assertSame(500, $response->getStatusCode());
        $data = json_decode((string) $response->getBody(), true);
        self::assertFalse($data['success']);
    }

    #[Test]
    public function listActionSkipsNonTemplateObjects(): void
    {
        $template = $this->createStub(PromptTemplate::class);
        $template->method('getIdentifier')->willReturn('valid');
        $template->method('getTitle')->willReturn('Valid');
        $template->method('getDescription')->willReturn('desc');
        $template->method('getFeature')->willReturn('cat');

        // Mix in a non-PromptTemplate object
        $this->templateRepoStub->method('findActive')
            ->willReturn($this->createQueryResult([new stdClass(), $template]));

        $request  = $this->createStub(ServerRequestInterface::class);
        $response = $this->subject->listAction($request);

        $data = json_decode((string) $response->getBody(), true);
        self::assertCount(1, $data['templates']);
    }

    /**
     * @param list<object> $items
     */
    private function createQueryResult(array $items): QueryResultInterface&Stub
    {
        $queryResult = $this->createStub(QueryResultInterface::class);
        $iterator    = new ArrayIterator($items);

        $queryResult->method('current')->willReturnCallback(static fn () => $iterator->current());
        $queryResult->method('key')->willReturnCallback(static fn () => $iterator->key());
        $queryResult->method('next')->willReturnCallback(static fn () => $iterator->next());
        $queryResult->method('rewind')->willReturnCallback(static fn () => $iterator->rewind());
        $queryResult->method('valid')->willReturnCallback(static fn () => $iterator->valid());
        $queryResult->method('count')->willReturn(count($items));

        return $queryResult;
    }
}
