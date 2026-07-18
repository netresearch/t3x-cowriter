<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\T3Cowriter\Tests\Integration\Controller;

use Netresearch\NrLlm\Domain\Model\Task;
use Netresearch\NrLlm\Domain\Repository\TaskRepository;
use Netresearch\T3Cowriter\Controller\TemplateController;
use Netresearch\T3Cowriter\Service\RateLimiterInterface;
use Netresearch\T3Cowriter\Service\RateLimitResult;
use Netresearch\T3Cowriter\Tests\Integration\AbstractIntegrationTestCase;
use Netresearch\T3Cowriter\Tests\Support\TestQueryResult;
use Override;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\MockObject;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Log\NullLogger;
use stdClass;
use TYPO3\CMS\Core\Context\Context;

/**
 * Integration tests for TemplateController.
 *
 * Tests complete request/response flows through the controller
 * with mocked TaskRepository responses.
 */
#[AllowMockObjectsWithoutExpectations]
#[CoversClass(TemplateController::class)]
final class TemplateControllerIntegrationTest extends AbstractIntegrationTestCase
{
    private TemplateController $subject;
    private TaskRepository&MockObject $templateRepoMock;

    #[Override]
    protected function setUp(): void
    {
        parent::setUp();

        $this->templateRepoMock = $this->createMock(TaskRepository::class);

        $rateLimiter = $this->createStub(RateLimiterInterface::class);
        $rateLimiter->method('checkLimit')->willReturn(
            new RateLimitResult(allowed: true, limit: 20, remaining: 19, resetTime: time() + 60),
        );

        $context = $this->createStub(Context::class);
        $context->method('getPropertyFromAspect')->willReturn(1);

        $this->subject = new TemplateController(
            $this->templateRepoMock,
            $rateLimiter,
            $context,
            new NullLogger(),
        );
    }

    /**
     * Create a stub ServerRequest (TemplateController doesn't read body).
     */
    private function createStubRequest(): ServerRequestInterface
    {
        return self::createStub(ServerRequestInterface::class);
    }

    /**
     * Create a Task stub with common field values.
     *
     * @return Task&MockObject
     */
    private function createTaskStub(
        string $identifier,
        string $name,
        string $description,
        string $category,
    ): Task&MockObject {
        $template = $this->createMock(Task::class);
        $template->method('getIdentifier')->willReturn($identifier);
        $template->method('getName')->willReturn($name);
        $template->method('getDescription')->willReturn($description);
        $template->method('getCategory')->willReturn($category);

        return $template;
    }

    // =========================================================================
    // List Flow Tests
    // =========================================================================

    #[Test]
    public function listFlowReturnsFormattedTemplates(): void
    {
        // Arrange
        $template = $this->createTaskStub(
            'improve-text',
            'Improve Text',
            'Enhances text quality and readability.',
            'writing',
        );

        $queryResult = new TestQueryResult([$template]);
        $this->templateRepoMock->method('findActive')->willReturn($queryResult);

        // Act
        $request  = $this->createStubRequest();
        $response = $this->subject->listAction($request);

        // Assert
        self::assertSame(200, $response->getStatusCode());
        $data = json_decode((string) $response->getBody(), true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($data);
        self::assertTrue($data['success']);
        self::assertCount(1, $data['templates']);

        // Verify field mapping: title -> name, feature -> category
        $entry = $data['templates'][0];
        self::assertSame('improve-text', $entry['identifier']);
        self::assertSame('Improve Text', $entry['name']);
        self::assertSame('Enhances text quality and readability.', $entry['description']);
        self::assertSame('writing', $entry['category']);
    }

    #[Test]
    public function listFlowHandlesMultipleTemplates(): void
    {
        // Arrange
        $template1 = $this->createTaskStub('improve-text', 'Improve Text', 'Improves quality', 'writing');
        $template2 = $this->createTaskStub('summarize', 'Summarize', '', 'analysis');
        $template3 = $this->createTaskStub('translate-formal', 'Formal Translation', 'Formal tone', 'translation');

        $queryResult = new TestQueryResult([$template1, $template2, $template3]);
        $this->templateRepoMock->method('findActive')->willReturn($queryResult);

        // Act
        $request  = $this->createStubRequest();
        $response = $this->subject->listAction($request);

        // Assert
        $data = json_decode((string) $response->getBody(), true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($data);
        self::assertTrue($data['success']);
        self::assertCount(3, $data['templates']);

        self::assertSame('improve-text', $data['templates'][0]['identifier']);
        self::assertSame('summarize', $data['templates'][1]['identifier']);
        self::assertSame('translate-formal', $data['templates'][2]['identifier']);

        // Empty description passes through unchanged (Task::getDescription() is non-nullable)
        self::assertSame('', $data['templates'][1]['description']);
    }

    #[Test]
    public function listFlowFiltersNonTemplateObjects(): void
    {
        // Arrange: QueryResult contains a mix of Task and stdClass
        $validTemplate = $this->createTaskStub('valid', 'Valid Template', 'A valid one', 'writing');
        $invalidObject = new stdClass();

        $queryResult = new TestQueryResult([$validTemplate, $invalidObject]);
        $this->templateRepoMock->method('findActive')->willReturn($queryResult);

        // Act
        $request  = $this->createStubRequest();
        $response = $this->subject->listAction($request);

        // Assert: only the Task instance is in the result
        $data = json_decode((string) $response->getBody(), true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($data);
        self::assertTrue($data['success']);
        self::assertCount(1, $data['templates']);
        self::assertSame('valid', $data['templates'][0]['identifier']);
    }
}
