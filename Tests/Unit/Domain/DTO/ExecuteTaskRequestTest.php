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
            instruction: 'Improve this text',
            configuration: 'openai-gpt4',
        );

        self::assertSame(42, $dto->taskUid);
        self::assertSame('Some text', $dto->context);
        self::assertSame('selection', $dto->contextType);
        self::assertSame('Improve this text', $dto->instruction);
        self::assertSame('openai-gpt4', $dto->configuration);
    }

    #[Test]
    public function constructWithNullConfiguration(): void
    {
        $dto = new ExecuteTaskRequest(1, 'text', 'selection', 'Do something', null);
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
            'instruction'   => 'Improve the text',
            'configuration' => 'claude-config',
        ]);

        $dto = ExecuteTaskRequest::fromRequest($request);

        self::assertSame(5, $dto->taskUid);
        self::assertSame('Hello world', $dto->context);
        self::assertSame('content_element', $dto->contextType);
        self::assertSame('Improve the text', $dto->instruction);
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
        self::assertSame('', $dto->instruction);
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
            'instruction' => true,
        ]);

        $dto = ExecuteTaskRequest::fromRequest($request);

        self::assertSame(0, $dto->taskUid);
        self::assertSame('', $dto->context);
        self::assertSame('123', $dto->contextType);
        self::assertSame('1', $dto->instruction);
    }

    #[Test]
    public function fromRequestHandlesNumericStringTaskUid(): void
    {
        $request = $this->createJsonRequest(['taskUid' => '42', 'context' => 'text', 'contextType' => 'selection', 'instruction' => 'Do it']);

        $dto = ExecuteTaskRequest::fromRequest($request);

        self::assertSame(42, $dto->taskUid);
    }

    // =========================================================================
    // isValid
    // =========================================================================

    #[Test]
    public function isValidReturnsTrueForValidRequest(): void
    {
        $dto = new ExecuteTaskRequest(1, 'Some context', 'selection', 'Improve this', null);
        self::assertTrue($dto->isValid());
    }

    #[Test]
    public function isValidReturnsTrueWithContentElementType(): void
    {
        $dto = new ExecuteTaskRequest(1, 'Some context', 'content_element', 'Improve this', null);
        self::assertTrue($dto->isValid());
    }

    #[Test]
    public function isValidReturnsTrueWithInstruction(): void
    {
        $dto = new ExecuteTaskRequest(1, 'Some context', 'selection', 'Be formal and concise', 'config');
        self::assertTrue($dto->isValid());
    }

    #[Test]
    public function isValidAcceptsZeroTaskUid(): void
    {
        // taskUid=0 is custom mode (no task)
        $dto = new ExecuteTaskRequest(0, '', '', 'Custom instruction', null);
        self::assertTrue($dto->isValid());
    }

    #[Test]
    public function isValidAcceptsEmptyContextWithInstruction(): void
    {
        // Empty context is valid (custom mode, no editor content)
        $dto = new ExecuteTaskRequest(1, '', '', 'Write a blog post about AI', null);
        self::assertTrue($dto->isValid());
    }

    #[Test]
    public function isValidAcceptsEmptyContextTypeWhenContextEmpty(): void
    {
        // Empty contextType is valid when context is empty
        $dto = new ExecuteTaskRequest(0, '', '', 'Custom instruction', null);
        self::assertTrue($dto->isValid());
    }

    /**
     * @return array<string, array{ExecuteTaskRequest}>
     */
    public static function invalidRequestProvider(): array
    {
        return [
            'negative task UID' => [
                new ExecuteTaskRequest(-1, 'text', 'selection', 'Do it', null),
            ],
            'invalid context type with non-empty context' => [
                new ExecuteTaskRequest(1, 'text', 'invalid', 'Do it', null),
            ],
            'empty context type with non-empty context' => [
                new ExecuteTaskRequest(1, 'text', '', 'Do it', null),
            ],
            'context exceeds max length' => [
                new ExecuteTaskRequest(1, str_repeat('a', 32769), 'selection', 'Do it', null),
            ],
            'empty instruction' => [
                new ExecuteTaskRequest(1, 'text', 'selection', '', null),
            ],
            'whitespace-only instruction' => [
                new ExecuteTaskRequest(1, 'text', 'selection', '   ', null),
            ],
            'instruction exceeds max length' => [
                new ExecuteTaskRequest(1, 'text', 'selection', str_repeat('a', 32769), null),
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
        $dto = new ExecuteTaskRequest(1, str_repeat('a', 32768), 'selection', 'Do it', null);
        self::assertTrue($dto->isValid());
    }

    #[Test]
    public function isValidAcceptsMaxLengthInstruction(): void
    {
        $dto = new ExecuteTaskRequest(1, 'text', 'selection', str_repeat('a', 32768), null);
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
            'instruction'        => 'Improve',
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
        $dto = new ExecuteTaskRequest(1, 'text', 'selection', 'Do it', null, str_repeat('a', 2049));
        self::assertFalse($dto->isValid());
    }

    #[Test]
    public function isValidAcceptsMaxLengthEditorCapabilities(): void
    {
        $dto = new ExecuteTaskRequest(1, 'text', 'selection', 'Do it', null, str_repeat('a', 2048));
        self::assertTrue($dto->isValid());
    }

    // =========================================================================
    // contextScope
    // =========================================================================

    #[Test]
    public function fromRequestParsesContextScope(): void
    {
        $request = $this->createJsonRequest([
            'taskUid'      => 1,
            'context'      => 'text',
            'contextType'  => 'selection',
            'contextScope' => 'page',
        ]);

        $dto = ExecuteTaskRequest::fromRequest($request);
        self::assertSame('page', $dto->contextScope);
    }

    #[Test]
    public function fromRequestDefaultsContextScopeToEmpty(): void
    {
        $request = $this->createJsonRequest([
            'taskUid'     => 1,
            'context'     => 'text',
            'contextType' => 'selection',
        ]);

        $dto = ExecuteTaskRequest::fromRequest($request);
        self::assertSame('', $dto->contextScope);
    }

    #[Test]
    public function isValidAcceptsAllContextScopes(): void
    {
        foreach (['', 'selection', 'text', 'element', 'page', 'ancestors_1', 'ancestors_2'] as $scope) {
            $rc = in_array($scope, ['element', 'page', 'ancestors_1', 'ancestors_2'], true)
                ? ['table' => 'tt_content', 'uid' => 1, 'field' => 'bodytext']
                : null;
            $dto = new ExecuteTaskRequest(1, 'text', 'selection', 'Do it', null, '', $scope, $rc);
            self::assertTrue($dto->isValid(), "contextScope '$scope' should be valid");
        }
    }

    #[Test]
    public function isValidRejectsInvalidContextScope(): void
    {
        $dto = new ExecuteTaskRequest(1, 'text', 'selection', 'Do it', null, '', 'invalid_scope');
        self::assertFalse($dto->isValid());
    }

    // =========================================================================
    // recordContext
    // =========================================================================

    #[Test]
    public function fromRequestParsesRecordContext(): void
    {
        $request = $this->createJsonRequest([
            'taskUid'       => 1,
            'context'       => 'text',
            'contextType'   => 'selection',
            'recordContext' => ['table' => 'tt_content', 'uid' => 42, 'field' => 'bodytext'],
        ]);

        $dto = ExecuteTaskRequest::fromRequest($request);
        self::assertSame(['table' => 'tt_content', 'uid' => 42, 'field' => 'bodytext'], $dto->recordContext);
    }

    #[Test]
    public function fromRequestDefaultsRecordContextToNull(): void
    {
        $request = $this->createJsonRequest([
            'taskUid'     => 1,
            'context'     => 'text',
            'contextType' => 'selection',
        ]);

        $dto = ExecuteTaskRequest::fromRequest($request);
        self::assertNull($dto->recordContext);
    }

    #[Test]
    public function isValidAcceptsValidRecordContext(): void
    {
        $dto = new ExecuteTaskRequest(
            1,
            'text',
            'selection',
            'Do it',
            null,
            '',
            'page',
            ['table' => 'tt_content', 'uid' => 42, 'field' => 'bodytext'],
        );
        self::assertTrue($dto->isValid());
    }

    #[Test]
    public function isValidAcceptsNullRecordContext(): void
    {
        $dto = new ExecuteTaskRequest(1, 'text', 'selection', 'Do it', null, '', '', null);
        self::assertTrue($dto->isValid());
    }

    #[Test]
    public function isValidRejectsRecordContextWithDisallowedTable(): void
    {
        $dto = new ExecuteTaskRequest(
            1,
            'text',
            'selection',
            'Do it',
            null,
            '',
            'page',
            ['table' => 'be_users', 'uid' => 1, 'field' => 'username'],
        );
        self::assertFalse($dto->isValid());
    }

    #[Test]
    public function isValidRequiresRecordContextWhenScopeIsElement(): void
    {
        $dto = new ExecuteTaskRequest(1, 'text', 'selection', 'Do it', null, '', 'element', null);
        self::assertFalse($dto->isValid());
    }

    #[Test]
    public function isValidRequiresRecordContextWhenScopeIsPage(): void
    {
        $dto = new ExecuteTaskRequest(1, 'text', 'selection', 'Do it', null, '', 'page', null);
        self::assertFalse($dto->isValid());
    }

    #[Test]
    public function isValidRequiresRecordContextWhenScopeIsAncestors(): void
    {
        $dto = new ExecuteTaskRequest(1, 'text', 'selection', 'Do it', null, '', 'ancestors_1', null);
        self::assertFalse($dto->isValid());
    }

    // =========================================================================
    // referencePages
    // =========================================================================

    #[Test]
    public function fromRequestParsesReferencePages(): void
    {
        $request = $this->createJsonRequest([
            'taskUid'        => 1,
            'context'        => 'text',
            'contextType'    => 'selection',
            'referencePages' => [
                ['pid' => 5, 'relation' => 'reference material'],
                ['pid' => 12, 'relation' => 'style guide'],
            ],
        ]);

        $dto = ExecuteTaskRequest::fromRequest($request);
        self::assertCount(2, $dto->referencePages);
        self::assertSame(5, $dto->referencePages[0]['pid']);
        self::assertSame('style guide', $dto->referencePages[1]['relation']);
    }

    #[Test]
    public function fromRequestDefaultsReferencePagesToEmpty(): void
    {
        $request = $this->createJsonRequest([
            'taskUid'     => 1,
            'context'     => 'text',
            'contextType' => 'selection',
        ]);

        $dto = ExecuteTaskRequest::fromRequest($request);
        self::assertSame([], $dto->referencePages);
    }

    #[Test]
    public function isValidRejectsTooManyReferencePages(): void
    {
        $pages = array_fill(0, 11, ['pid' => 1, 'relation' => 'test']);
        $dto   = new ExecuteTaskRequest(1, 'text', 'selection', 'Do it', null, '', '', null, $pages);
        self::assertFalse($dto->isValid());
    }

    #[Test]
    public function isValidAcceptsTenReferencePages(): void
    {
        $pages = array_fill(0, 10, ['pid' => 1, 'relation' => 'test']);
        $dto   = new ExecuteTaskRequest(1, 'text', 'selection', 'Do it', null, '', '', null, $pages);
        self::assertTrue($dto->isValid());
    }

    // =========================================================================
    // Multi-byte string length checks (kills MBString mutants #63-65)
    // =========================================================================

    #[Test]
    public function isValidUsesMultiByteForContextLength(): void
    {
        // Kills MBString mutant #63: mb_strlen → strlen for context
        // 32768 emojis = 32768 chars (within limit) but 131072 bytes (over limit with strlen)
        $emoji = "\xF0\x9F\x92\xA1"; // U+1F4A1 (lightbulb), 4 bytes
        $dto   = new ExecuteTaskRequest(1, str_repeat($emoji, 32768), 'selection', 'Do it', null);
        self::assertTrue($dto->isValid());
    }

    #[Test]
    public function isValidUsesMultiByteForInstructionLength(): void
    {
        // Kills MBString mutant: mb_strlen → strlen for instruction
        $emoji = "\xF0\x9F\x92\xA1";
        $dto   = new ExecuteTaskRequest(1, 'text', 'selection', str_repeat($emoji, 32768), null);
        self::assertTrue($dto->isValid());
    }

    #[Test]
    public function isValidUsesMultiByteForEditorCapabilitiesLength(): void
    {
        // Kills MBString mutant #65: mb_strlen → strlen for editorCapabilities
        $emoji = "\xF0\x9F\x92\xA1";
        $dto   = new ExecuteTaskRequest(1, 'text', 'selection', 'Do it', null, str_repeat($emoji, 2048));
        self::assertTrue($dto->isValid());
    }

    // =========================================================================
    // recordContext uid boundary (kills LessThanOrEqualTo #68, Increment/Decrement #66-67)
    // =========================================================================

    #[Test]
    public function isValidRejectsRecordContextWithUidZero(): void
    {
        // Kills LessThanOrEqualTo #68: ($uid ?? 0) <= 0 → ($uid ?? 0) < 0
        // uid=0 is invalid (should return false). With < 0, it would incorrectly pass.
        $dto = new ExecuteTaskRequest(
            1,
            'text',
            'selection',
            'Do it',
            null,
            '',
            'page',
            ['table' => 'tt_content', 'uid' => 0, 'field' => 'bodytext'],
        );
        self::assertFalse($dto->isValid());
    }

    // =========================================================================
    // recordContext field regex (kills PregMatchRemoveCaret #69, PregMatchRemoveDollar #70, PregMatchRemoveFlags #71, LogicalOr #72)
    // =========================================================================

    #[Test]
    public function isValidRejectsRecordContextFieldStartingWithDigit(): void
    {
        // Kills PregMatchRemoveCaret #69: /^[a-z].../ → /[a-z].../
        // Without ^, '1bodytext' would match because 'b' matches [a-z]
        $dto = new ExecuteTaskRequest(
            1,
            'text',
            'selection',
            'Do it',
            null,
            '',
            'page',
            ['table' => 'tt_content', 'uid' => 1, 'field' => '1bodytext'],
        );
        self::assertFalse($dto->isValid());
    }

    #[Test]
    public function isValidRejectsRecordContextFieldWithTrailingSpecialChars(): void
    {
        // Kills PregMatchRemoveDollar #70: /...[a-z0-9_]*$/ → /...[a-z0-9_]*/
        // Without $, 'bodytext!@#' would match up to 't'
        $dto = new ExecuteTaskRequest(
            1,
            'text',
            'selection',
            'Do it',
            null,
            '',
            'page',
            ['table' => 'tt_content', 'uid' => 1, 'field' => 'bodytext!'],
        );
        self::assertFalse($dto->isValid());
    }

    #[Test]
    public function isValidAcceptsRecordContextFieldWithUpperCase(): void
    {
        // Kills PregMatchRemoveFlags #71: /^[a-z]...$/i → /^[a-z]...$/
        // Without /i flag, 'Bodytext' would fail because 'B' doesn't match [a-z]
        $dto = new ExecuteTaskRequest(
            1,
            'text',
            'selection',
            'Do it',
            null,
            '',
            'page',
            ['table' => 'tt_content', 'uid' => 1, 'field' => 'Bodytext'],
        );
        self::assertTrue($dto->isValid());
    }

    // =========================================================================
    // extractRecordContext validation (kills LogicalOr #78-79-83, LessThanOrEqualTo #77, PregMatch #80-82)
    // =========================================================================

    #[Test]
    public function fromRequestRejectsRecordContextWithEmptyTableValidUidAndField(): void
    {
        $request = $this->createJsonRequest([
            'taskUid'       => 1,
            'context'       => 'text',
            'contextType'   => 'selection',
            'recordContext' => ['table' => '', 'uid' => 5, 'field' => 'bodytext'],
        ]);

        $dto = ExecuteTaskRequest::fromRequest($request);
        self::assertNull($dto->recordContext);
    }

    #[Test]
    public function fromRequestRejectsRecordContextWithValidTableUidZeroAndValidField(): void
    {
        $request = $this->createJsonRequest([
            'taskUid'       => 1,
            'context'       => 'text',
            'contextType'   => 'selection',
            'recordContext' => ['table' => 'tt_content', 'uid' => 0, 'field' => 'bodytext'],
        ]);

        $dto = ExecuteTaskRequest::fromRequest($request);
        self::assertNull($dto->recordContext);
    }

    #[Test]
    public function fromRequestRejectsRecordContextWithInvalidFieldRegex(): void
    {
        $request = $this->createJsonRequest([
            'taskUid'       => 1,
            'context'       => 'text',
            'contextType'   => 'selection',
            'recordContext' => ['table' => 'tt_content', 'uid' => 1, 'field' => '1invalid'],
        ]);

        $dto = ExecuteTaskRequest::fromRequest($request);
        self::assertNull($dto->recordContext);
    }

    #[Test]
    public function fromRequestRejectsRecordContextFieldStartingWithDigitInExtract(): void
    {
        $request = $this->createJsonRequest([
            'taskUid'       => 1,
            'context'       => 'text',
            'contextType'   => 'selection',
            'recordContext' => ['table' => 'tt_content', 'uid' => 1, 'field' => '9field'],
        ]);

        $dto = ExecuteTaskRequest::fromRequest($request);
        self::assertNull($dto->recordContext);
    }

    #[Test]
    public function fromRequestRejectsRecordContextFieldWithTrailingSpecialInExtract(): void
    {
        $request = $this->createJsonRequest([
            'taskUid'       => 1,
            'context'       => 'text',
            'contextType'   => 'selection',
            'recordContext' => ['table' => 'tt_content', 'uid' => 1, 'field' => 'body$text'],
        ]);

        $dto = ExecuteTaskRequest::fromRequest($request);
        self::assertNull($dto->recordContext);
    }

    #[Test]
    public function fromRequestAcceptsRecordContextFieldWithUpperCaseInExtract(): void
    {
        $request = $this->createJsonRequest([
            'taskUid'       => 1,
            'context'       => 'text',
            'contextType'   => 'selection',
            'recordContext' => ['table' => 'tt_content', 'uid' => 1, 'field' => 'BodyText'],
        ]);

        $dto = ExecuteTaskRequest::fromRequest($request);
        self::assertNotNull($dto->recordContext);
        self::assertSame('BodyText', $dto->recordContext['field']);
    }

    // =========================================================================
    // extractRecordContext CastInt and uid defaults (kills #74-77)
    // =========================================================================

    #[Test]
    public function fromRequestCastsRecordContextUidToInt(): void
    {
        $request = $this->createJsonRequest([
            'taskUid'       => 1,
            'context'       => 'text',
            'contextType'   => 'selection',
            'recordContext' => ['table' => 'tt_content', 'uid' => '42', 'field' => 'bodytext'],
        ]);

        $dto = ExecuteTaskRequest::fromRequest($request);
        self::assertNotNull($dto->recordContext);
        self::assertIsInt($dto->recordContext['uid']);
        self::assertSame(42, $dto->recordContext['uid']);
    }

    // =========================================================================
    // referencePages extraction (kills CastInt #84, GreaterThan #90, MBString #89, Inc/Dec #85-88)
    // =========================================================================

    #[Test]
    public function fromRequestCastsReferencePidToInt(): void
    {
        $request = $this->createJsonRequest([
            'taskUid'        => 1,
            'context'        => 'text',
            'contextType'    => 'selection',
            'referencePages' => [
                ['pid' => '7', 'relation' => 'ref'],
            ],
        ]);

        $dto = ExecuteTaskRequest::fromRequest($request);
        self::assertCount(1, $dto->referencePages);
        self::assertIsInt($dto->referencePages[0]['pid']);
        self::assertSame(7, $dto->referencePages[0]['pid']);
    }

    #[Test]
    public function fromRequestFiltersReferencePagesWithZeroPid(): void
    {
        $request = $this->createJsonRequest([
            'taskUid'        => 1,
            'context'        => 'text',
            'contextType'    => 'selection',
            'referencePages' => [
                ['pid' => 0, 'relation' => 'should be filtered'],
                ['pid' => 5, 'relation' => 'valid'],
            ],
        ]);

        $dto = ExecuteTaskRequest::fromRequest($request);
        self::assertCount(1, $dto->referencePages);
        self::assertSame(5, $dto->referencePages[0]['pid']);
    }

    #[Test]
    public function fromRequestTruncatesReferenceRelationToExactly100MultiByteChars(): void
    {
        $emoji    = "\xF0\x9F\x92\xA1"; // 4 bytes per char
        $relation = str_repeat($emoji, 101); // 101 chars, 404 bytes

        $request = $this->createJsonRequest([
            'taskUid'        => 1,
            'context'        => 'text',
            'contextType'    => 'selection',
            'referencePages' => [
                ['pid' => 1, 'relation' => $relation],
            ],
        ]);

        $dto = ExecuteTaskRequest::fromRequest($request);
        self::assertCount(1, $dto->referencePages);
        self::assertSame(100, mb_strlen($dto->referencePages[0]['relation'], 'UTF-8'));
    }

    #[Test]
    public function fromRequestKeepsRelationExactlyAt100Chars(): void
    {
        $relation = str_repeat('a', 100);

        $request = $this->createJsonRequest([
            'taskUid'        => 1,
            'context'        => 'text',
            'contextType'    => 'selection',
            'referencePages' => [
                ['pid' => 1, 'relation' => $relation],
            ],
        ]);

        $dto = ExecuteTaskRequest::fromRequest($request);
        self::assertSame(100, mb_strlen($dto->referencePages[0]['relation'], 'UTF-8'));
        self::assertSame($relation, $dto->referencePages[0]['relation']);
    }

    // =========================================================================
    // CastString / extractNullableString (kills #73)
    // =========================================================================

    #[Test]
    public function fromRequestCastsNumericConfigurationToString(): void
    {
        $request = $this->createJsonRequest([
            'taskUid'       => 1,
            'context'       => 'text',
            'contextType'   => 'selection',
            'configuration' => 42,
        ]);

        $dto = ExecuteTaskRequest::fromRequest($request);
        self::assertIsString($dto->configuration);
        self::assertSame('42', $dto->configuration);
    }

    // =========================================================================
    // extractNullableString ReturnRemoval (kills ReturnRemoval mutant)
    // =========================================================================

    #[Test]
    public function fromRequestReturnsNullForEmptyConfiguration(): void
    {
        // Kills ReturnRemoval mutant: extractNullableString removing 'return null'
        $request = $this->createJsonRequest([
            'taskUid'       => 1,
            'context'       => 'text',
            'contextType'   => 'selection',
            'instruction'   => 'Do it',
            'configuration' => '',
        ]);

        $dto = ExecuteTaskRequest::fromRequest($request);
        self::assertNull($dto->configuration);
    }

    #[Test]
    public function fromRequestReturnsNullForNullConfiguration(): void
    {
        // Explicitly pass null to ensure extractNullableString returns null
        $request = $this->createJsonRequest([
            'taskUid'     => 1,
            'context'     => 'text',
            'contextType' => 'selection',
            'instruction' => 'Do it',
        ]);

        $dto = ExecuteTaskRequest::fromRequest($request);
        self::assertNull($dto->configuration);
    }

    // =========================================================================
    // extractRecordContext ReturnRemoval (kills ReturnRemoval mutant)
    // =========================================================================

    #[Test]
    public function fromRequestReturnsNullRecordContextForNonArrayValue(): void
    {
        // Kills ReturnRemoval mutant on extractRecordContext 'return null'
        $request = $this->createJsonRequest([
            'taskUid'       => 1,
            'context'       => 'text',
            'contextType'   => 'selection',
            'instruction'   => 'Do it',
            'recordContext' => 'not-an-array',
        ]);

        $dto = ExecuteTaskRequest::fromRequest($request);
        self::assertNull($dto->recordContext);
    }

    // =========================================================================
    // Default integer values (kills Inc/Dec mutants on uid/pid defaults)
    // =========================================================================

    #[Test]
    public function fromRequestRejectsRecordContextWithNonNumericUid(): void
    {
        // Kills IncrementInteger/DecrementInteger mutant on default uid value (0 → 1 or -1)
        // When uid is non-numeric, it defaults to 0 which fails the <= 0 check
        $request = $this->createJsonRequest([
            'taskUid'       => 1,
            'context'       => 'text',
            'contextType'   => 'selection',
            'instruction'   => 'Do it',
            'recordContext' => ['table' => 'tt_content', 'uid' => 'not-a-number', 'field' => 'bodytext'],
        ]);

        $dto = ExecuteTaskRequest::fromRequest($request);
        self::assertNull($dto->recordContext);
    }

    #[Test]
    public function fromRequestRejectsReferencePagesWithNonNumericPid(): void
    {
        // Kills IncrementInteger/DecrementInteger mutant on default pid value (0 → 1 or -1)
        $request = $this->createJsonRequest([
            'taskUid'        => 1,
            'context'        => 'text',
            'contextType'    => 'selection',
            'instruction'    => 'Do it',
            'referencePages' => [
                ['pid' => 'not-a-number', 'relation' => 'ref'],
            ],
        ]);

        $dto = ExecuteTaskRequest::fromRequest($request);
        self::assertSame([], $dto->referencePages);
    }

    #[Test]
    public function fromRequestFiltersReferencePagesWithNegativePid(): void
    {
        $request = $this->createJsonRequest([
            'taskUid'        => 1,
            'context'        => 'text',
            'contextType'    => 'selection',
            'instruction'    => 'Do it',
            'referencePages' => [
                ['pid' => -1, 'relation' => 'should be filtered'],
            ],
        ]);

        $dto = ExecuteTaskRequest::fromRequest($request);
        self::assertSame([], $dto->referencePages);
    }

    // =========================================================================
    // isValid context trim test (kills UnwrapTrim mutant)
    // =========================================================================

    #[Test]
    public function isValidAcceptsWhitespaceOnlyContextWithAnyContextType(): void
    {
        // Kills UnwrapTrim mutant: trim($this->context) !== '' → $this->context !== ''
        // Whitespace-only context is treated as empty after trim, so any contextType is valid
        $dto = new ExecuteTaskRequest(1, '   ', 'invalid_type', 'Do it', null);
        self::assertTrue($dto->isValid());
    }

    // =========================================================================
    // XSS / injection payloads
    // =========================================================================

    #[Test]
    public function fromRequestPreservesXssPayloadsVerbatim(): void
    {
        $xssContext     = '<script>alert("xss")</script>';
        $xssInstruction = '"><img src=x onerror=alert(1)>';

        $request = $this->createJsonRequest([
            'taskUid'     => 1,
            'context'     => $xssContext,
            'contextType' => 'selection',
            'instruction' => $xssInstruction,
        ]);

        $dto = ExecuteTaskRequest::fromRequest($request);

        // DTO preserves raw input; escaping is the controller's responsibility
        self::assertSame($xssContext, $dto->context);
        self::assertSame($xssInstruction, $dto->instruction);
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
