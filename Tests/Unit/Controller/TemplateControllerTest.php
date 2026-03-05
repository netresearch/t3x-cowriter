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
use Netresearch\T3Cowriter\Service\RateLimiterInterface;
use Netresearch\T3Cowriter\Service\RateLimitResult;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Log\NullLogger;
use RuntimeException;
use TYPO3\CMS\Core\Context\Context;
use TYPO3\CMS\Extbase\Persistence\QueryResultInterface;

#[CoversClass(TemplateController::class)]
final class TemplateControllerTest extends TestCase
{
    private PromptTemplateRepository&Stub $templateRepositoryStub;
    private RateLimiterInterface&Stub $rateLimiterStub;
    private TemplateController $subject;

    protected function setUp(): void
    {
        $this->templateRepositoryStub = $this->createStub(PromptTemplateRepository::class);
        $this->rateLimiterStub        = $this->createStub(RateLimiterInterface::class);
        $contextStub                  = $this->createStub(Context::class);

        $contextStub->method('getPropertyFromAspect')
            ->willReturn(1);

        $this->subject = new TemplateController(
            $this->templateRepositoryStub,
            $this->rateLimiterStub,
            $contextStub,
            new NullLogger(),
        );
    }

    #[Test]
    public function listActionReturnsTemplates(): void
    {
        $this->rateLimiterStub->method('checkLimit')
            ->willReturn(new RateLimitResult(true, 20, 19, time() + 60));

        $template = $this->createStub(PromptTemplate::class);
        $template->method('getIdentifier')->willReturn('improve');
        $template->method('getTitle')->willReturn('Improve Text');
        $template->method('getDescription')->willReturn('Enhance readability');
        $template->method('getFeature')->willReturn('content');

        $this->templateRepositoryStub->method('findActive')
            ->willReturn($this->createQueryResult([$template]));

        $request  = $this->createStub(ServerRequestInterface::class);
        $response = $this->subject->listAction($request);

        self::assertSame(200, $response->getStatusCode());
        $data = json_decode((string) $response->getBody(), true);
        self::assertTrue($data['success']);
        self::assertCount(1, $data['templates']);
        self::assertSame('improve', $data['templates'][0]['identifier']);
        self::assertSame('Improve Text', $data['templates'][0]['name']);
        self::assertSame('Enhance readability', $data['templates'][0]['description']);
        self::assertSame('content', $data['templates'][0]['category']);
        self::assertSame('20', $response->getHeaderLine('X-RateLimit-Limit'));
        self::assertSame('19', $response->getHeaderLine('X-RateLimit-Remaining'));
    }

    #[Test]
    public function listActionReturns429WithHeadersWhenRateLimited(): void
    {
        $resetTime = time() + 60;
        $this->rateLimiterStub->method('checkLimit')
            ->willReturn(new RateLimitResult(false, 20, 0, $resetTime));

        $request  = $this->createStub(ServerRequestInterface::class);
        $response = $this->subject->listAction($request);

        self::assertSame(429, $response->getStatusCode());
        $data = json_decode((string) $response->getBody(), true);
        self::assertFalse($data['success']);
        self::assertArrayHasKey('error', $data);
        self::assertSame('20', $response->getHeaderLine('X-RateLimit-Limit'));
        self::assertSame('0', $response->getHeaderLine('X-RateLimit-Remaining'));
        self::assertNotEmpty($response->getHeaderLine('Retry-After'));
    }

    #[Test]
    public function listActionReturns500OnRepositoryError(): void
    {
        $this->rateLimiterStub->method('checkLimit')
            ->willReturn(new RateLimitResult(true, 20, 19, time() + 60));

        $this->templateRepositoryStub->method('findActive')
            ->willThrowException(new RuntimeException('DB error'));

        $request  = $this->createStub(ServerRequestInterface::class);
        $response = $this->subject->listAction($request);

        self::assertSame(500, $response->getStatusCode());
        $data = json_decode((string) $response->getBody(), true);
        self::assertFalse($data['success']);
        self::assertArrayHasKey('error', $data);
        self::assertStringContainsString('Failed', $data['error']);
        self::assertSame('20', $response->getHeaderLine('X-RateLimit-Limit'));
        self::assertSame('19', $response->getHeaderLine('X-RateLimit-Remaining'));
    }

    #[Test]
    public function listActionReturnsEmptyArrayWhenNoTemplates(): void
    {
        $this->rateLimiterStub->method('checkLimit')
            ->willReturn(new RateLimitResult(true, 20, 19, time() + 60));

        $this->templateRepositoryStub->method('findActive')
            ->willReturn($this->createQueryResult([]));

        $request  = $this->createStub(ServerRequestInterface::class);
        $response = $this->subject->listAction($request);

        $data = json_decode((string) $response->getBody(), true);
        self::assertTrue($data['success']);
        self::assertSame([], $data['templates']);
        self::assertSame('20', $response->getHeaderLine('X-RateLimit-Limit'));
        self::assertSame('19', $response->getHeaderLine('X-RateLimit-Remaining'));
    }

    #[Test]
    public function listActionCoalescesNullDescriptionToEmptyString(): void
    {
        $this->rateLimiterStub->method('checkLimit')
            ->willReturn(new RateLimitResult(true, 20, 19, time() + 60));

        $template = $this->createStub(PromptTemplate::class);
        $template->method('getIdentifier')->willReturn('no-desc');
        $template->method('getTitle')->willReturn('No Description');
        $template->method('getDescription')->willReturn(null);
        $template->method('getFeature')->willReturn('misc');

        $this->templateRepositoryStub->method('findActive')
            ->willReturn($this->createQueryResult([$template]));

        $request  = $this->createStub(ServerRequestInterface::class);
        $response = $this->subject->listAction($request);

        $data = json_decode((string) $response->getBody(), true);
        self::assertSame('', $data['templates'][0]['description']);
        self::assertSame('misc', $data['templates'][0]['category']);
    }

    /**
     * @param list<PromptTemplate&Stub> $items
     */
    private function createQueryResult(array $items): QueryResultInterface&Stub
    {
        $iterator    = new ArrayIterator($items);
        $queryResult = $this->createStub(QueryResultInterface::class);

        $queryResult->method('current')
            ->willReturnCallback(static fn () => $iterator->current());
        $queryResult->method('next')
            ->willReturnCallback(static function () use ($iterator): void {
                $iterator->next();
            });
        $queryResult->method('key')
            ->willReturnCallback(static fn () => $iterator->key());
        $queryResult->method('valid')
            ->willReturnCallback(static fn (): bool => $iterator->valid());
        $queryResult->method('rewind')
            ->willReturnCallback(static function () use ($iterator): void {
                $iterator->rewind();
            });
        $queryResult->method('count')
            ->willReturn(count($items));

        return $queryResult;
    }
}
