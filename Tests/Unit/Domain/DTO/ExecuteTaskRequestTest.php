<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\T3Cowriter\Tests\Unit\Domain\DTO;

use Netresearch\T3Cowriter\Domain\DTO\ExecuteTaskRequest;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\StreamInterface;

#[CoversClass(ExecuteTaskRequest::class)]
final class ExecuteTaskRequestTest extends TestCase
{
    // =========================================================================
    // Construction
    // =========================================================================

    #[Test]
    public function constructSetsAllProperties(): void
    {
        $dto = new ExecuteTaskRequest(
            taskUid: 42,
            context: 'Some text',
            contextType: 'selection',
            adHocRules: 'Be formal',
            configuration: 'openai-gpt4',
        );

        self::assertSame(42, $dto->taskUid);
        self::assertSame('Some text', $dto->context);
        self::assertSame('selection', $dto->contextType);
        self::assertSame('Be formal', $dto->adHocRules);
        self::assertSame('openai-gpt4', $dto->configuration);
    }

    #[Test]
    public function constructWithNullConfiguration(): void
    {
        $dto = new ExecuteTaskRequest(1, 'text', 'selection', '', null);
        self::assertNull($dto->configuration);
    }

    // =========================================================================
    // fromRequest — JSON body
    // =========================================================================

    #[Test]
    public function fromRequestParsesJsonBody(): void
    {
        $request = $this->createJsonRequest([
            'taskUid'       => 5,
            'context'       => 'Hello world',
            'contextType'   => 'content_element',
            'adHocRules'    => 'Keep it short',
            'configuration' => 'claude-config',
        ]);

        $dto = ExecuteTaskRequest::fromRequest($request);

        self::assertSame(5, $dto->taskUid);
        self::assertSame('Hello world', $dto->context);
        self::assertSame('content_element', $dto->contextType);
        self::assertSame('Keep it short', $dto->adHocRules);
        self::assertSame('claude-config', $dto->configuration);
    }

    #[Test]
    public function fromRequestHandlesEmptyBody(): void
    {
        $request = $this->createJsonRequest([]);

        $dto = ExecuteTaskRequest::fromRequest($request);

        self::assertSame(0, $dto->taskUid);
        self::assertSame('', $dto->context);
        self::assertSame('', $dto->contextType);
        self::assertSame('', $dto->adHocRules);
        self::assertNull($dto->configuration);
    }

    #[Test]
    public function fromRequestHandlesInvalidJson(): void
    {
        $stream = $this->createStub(StreamInterface::class);
        $stream->method('getContents')->willReturn('not-json');

        $request = $this->createStub(ServerRequestInterface::class);
        $request->method('getParsedBody')->willReturn(null);
        $request->method('getBody')->willReturn($stream);

        $dto = ExecuteTaskRequest::fromRequest($request);

        self::assertSame(0, $dto->taskUid);
        self::assertSame('', $dto->context);
    }

    #[Test]
    public function fromRequestHandlesParsedBody(): void
    {
        $request = $this->createStub(ServerRequestInterface::class);
        $request->method('getParsedBody')->willReturn([
            'taskUid'     => 10,
            'context'     => 'parsed body',
            'contextType' => 'selection',
        ]);

        $dto = ExecuteTaskRequest::fromRequest($request);

        self::assertSame(10, $dto->taskUid);
        self::assertSame('parsed body', $dto->context);
    }

    #[Test]
    public function fromRequestHandlesNonArrayTypes(): void
    {
        $request = $this->createJsonRequest([
            'taskUid'     => 'not-a-number',
            'context'     => ['array'],
            'contextType' => 123,
            'adHocRules'  => true,
        ]);

        $dto = ExecuteTaskRequest::fromRequest($request);

        self::assertSame(0, $dto->taskUid);
        self::assertSame('', $dto->context);
        self::assertSame('123', $dto->contextType);
        self::assertSame('1', $dto->adHocRules);
    }

    #[Test]
    public function fromRequestHandlesNumericStringTaskUid(): void
    {
        $request = $this->createJsonRequest(['taskUid' => '42', 'context' => 'text', 'contextType' => 'selection']);

        $dto = ExecuteTaskRequest::fromRequest($request);

        self::assertSame(42, $dto->taskUid);
    }

    // =========================================================================
    // isValid
    // =========================================================================

    #[Test]
    public function isValidReturnsTrueForValidRequest(): void
    {
        $dto = new ExecuteTaskRequest(1, 'Some context', 'selection', '', null);
        self::assertTrue($dto->isValid());
    }

    #[Test]
    public function isValidReturnsTrueWithContentElementType(): void
    {
        $dto = new ExecuteTaskRequest(1, 'Some context', 'content_element', '', null);
        self::assertTrue($dto->isValid());
    }

    #[Test]
    public function isValidReturnsTrueWithAdHocRules(): void
    {
        $dto = new ExecuteTaskRequest(1, 'Some context', 'selection', 'Be formal', 'config');
        self::assertTrue($dto->isValid());
    }

    /**
     * @return array<string, array{ExecuteTaskRequest}>
     */
    public static function invalidRequestProvider(): array
    {
        return [
            'zero task UID' => [
                new ExecuteTaskRequest(0, 'text', 'selection', '', null),
            ],
            'negative task UID' => [
                new ExecuteTaskRequest(-1, 'text', 'selection', '', null),
            ],
            'empty context' => [
                new ExecuteTaskRequest(1, '', 'selection', '', null),
            ],
            'whitespace-only context' => [
                new ExecuteTaskRequest(1, '   ', 'selection', '', null),
            ],
            'invalid context type' => [
                new ExecuteTaskRequest(1, 'text', 'invalid', '', null),
            ],
            'empty context type' => [
                new ExecuteTaskRequest(1, 'text', '', '', null),
            ],
            'context exceeds max length' => [
                new ExecuteTaskRequest(1, str_repeat('a', 32769), 'selection', '', null),
            ],
            'ad-hoc rules exceed max length' => [
                new ExecuteTaskRequest(1, 'text', 'selection', str_repeat('a', 4097), null),
            ],
        ];
    }

    #[Test]
    #[DataProvider('invalidRequestProvider')]
    public function isValidReturnsFalseForInvalidRequest(ExecuteTaskRequest $dto): void
    {
        self::assertFalse($dto->isValid());
    }

    #[Test]
    public function isValidAcceptsMaxLengthContext(): void
    {
        $dto = new ExecuteTaskRequest(1, str_repeat('a', 32768), 'selection', '', null);
        self::assertTrue($dto->isValid());
    }

    #[Test]
    public function isValidAcceptsMaxLengthAdHocRules(): void
    {
        $dto = new ExecuteTaskRequest(1, 'text', 'selection', str_repeat('a', 4096), null);
        self::assertTrue($dto->isValid());
    }

    // =========================================================================
    // editorCapabilities
    // =========================================================================

    #[Test]
    public function fromRequestParsesEditorCapabilities(): void
    {
        $request = $this->createJsonRequest([
            'taskUid'            => 1,
            'context'            => 'text',
            'contextType'        => 'selection',
            'adHocRules'         => '',
            'editorCapabilities' => 'bold, italic, tables, lists',
        ]);

        $dto = ExecuteTaskRequest::fromRequest($request);

        self::assertSame('bold, italic, tables, lists', $dto->editorCapabilities);
    }

    #[Test]
    public function fromRequestDefaultsEditorCapabilitiesToEmpty(): void
    {
        $request = $this->createJsonRequest([
            'taskUid'     => 1,
            'context'     => 'text',
            'contextType' => 'selection',
        ]);

        $dto = ExecuteTaskRequest::fromRequest($request);

        self::assertSame('', $dto->editorCapabilities);
    }

    #[Test]
    public function isValidRejectsTooLongEditorCapabilities(): void
    {
        $dto = new ExecuteTaskRequest(1, 'text', 'selection', '', null, str_repeat('a', 2049));
        self::assertFalse($dto->isValid());
    }

    #[Test]
    public function isValidAcceptsMaxLengthEditorCapabilities(): void
    {
        $dto = new ExecuteTaskRequest(1, 'text', 'selection', '', null, str_repeat('a', 2048));
        self::assertTrue($dto->isValid());
    }

    // =========================================================================
    // XSS / injection payloads
    // =========================================================================

    #[Test]
    public function fromRequestPreservesXssPayloadsVerbatim(): void
    {
        $xssContext = '<script>alert("xss")</script>';
        $xssRules   = '"><img src=x onerror=alert(1)>';

        $request = $this->createJsonRequest([
            'taskUid'     => 1,
            'context'     => $xssContext,
            'contextType' => 'selection',
            'adHocRules'  => $xssRules,
        ]);

        $dto = ExecuteTaskRequest::fromRequest($request);

        // DTO preserves raw input; escaping is the controller's responsibility
        self::assertSame($xssContext, $dto->context);
        self::assertSame($xssRules, $dto->adHocRules);
    }

    // =========================================================================
    // Helpers
    // =========================================================================

    /**
     * @param array<string, mixed> $data
     */
    private function createJsonRequest(array $data): ServerRequestInterface
    {
        $json   = json_encode($data, JSON_THROW_ON_ERROR);
        $stream = $this->createStub(StreamInterface::class);
        $stream->method('getContents')->willReturn($json);

        $request = $this->createStub(ServerRequestInterface::class);
        $request->method('getParsedBody')->willReturn(null);
        $request->method('getBody')->willReturn($stream);

        return $request;
    }
}
