<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\T3Cowriter\Tests\Unit\Controller;

use InvalidArgumentException;
use Netresearch\NrLlm\Domain\Model\LlmConfiguration;
use Netresearch\NrLlm\Domain\Repository\LlmConfigurationRepository;
use Netresearch\NrLlm\Exception\ConfigurationNotFoundException;
use Netresearch\NrLlm\Provider\Exception\ProviderResponseException;
use Netresearch\NrLlm\Testing\FakeCompletionService;
use Netresearch\T3Cowriter\Controller\FieldSuggestionController;
use Netresearch\T3Cowriter\Domain\DTO\FieldSuggestionRequest;
use Netresearch\T3Cowriter\Service\FieldSuggestion\FieldKind;
use Netresearch\T3Cowriter\Service\FieldSuggestion\FieldProfile;
use Netresearch\T3Cowriter\Service\FieldSuggestion\FieldSuggestionException;
use Netresearch\T3Cowriter\Service\FieldSuggestion\FieldSuggestionService;
use Netresearch\T3Cowriter\Service\FieldSuggestion\RecordContext;
use Netresearch\T3Cowriter\Service\FieldSuggestion\RecordContextReader;
use Netresearch\T3Cowriter\Service\FieldSuggestion\SlugSuggestionBuilder;
use Netresearch\T3Cowriter\Service\FieldSuggestion\SuggestionNormalizer;
use Netresearch\T3Cowriter\Service\FieldSuggestion\Tca;
use Netresearch\T3Cowriter\Service\LlmErrorClassifier;
use Netresearch\T3Cowriter\Service\RateLimiterInterface;
use Netresearch\T3Cowriter\Service\RateLimitResult;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\StreamInterface;
use Psr\Log\AbstractLogger;
use RuntimeException;
use Stringable;
use Throwable;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;
use TYPO3\CMS\Core\Context\Context;

#[CoversClass(FieldSuggestionController::class)]
#[CoversClass(FieldSuggestionRequest::class)]
#[CoversClass(FieldSuggestionService::class)]
#[CoversClass(FieldSuggestionException::class)]
#[CoversClass(FieldProfile::class)]
#[CoversClass(FieldKind::class)]
#[CoversClass(RecordContext::class)]
#[CoversClass(SuggestionNormalizer::class)]
#[CoversClass(Tca::class)]
final class FieldSuggestionControllerTest extends TestCase
{
    private FakeCompletionService $completion;
    private RecordContextReader&Stub $reader;
    private LlmConfigurationRepository&Stub $configurationRepository;
    private RateLimiterInterface&Stub $rateLimiter;
    private SlugSuggestionBuilder&Stub $slugBuilder;

    /** @var list<string> */
    private array $logged = [];

    protected function setUp(): void
    {
        $this->completion              = new FakeCompletionService();
        $this->reader                  = $this->createStub(RecordContextReader::class);
        $this->configurationRepository = $this->createStub(LlmConfigurationRepository::class);
        $this->rateLimiter             = $this->createStub(RateLimiterInterface::class);
        $this->slugBuilder             = $this->createStub(SlugSuggestionBuilder::class);

        $this->rateLimiter->method('checkLimit')->willReturn(new RateLimitResult(true, 20, 19, time() + 60));
        $this->configurationRepository->method('findDefault')->willReturn(new LlmConfiguration());
        $this->reader->method('read')->willReturn(self::context('seo_title'));

        $GLOBALS['BE_USER'] = $this->createStub(BackendUserAuthentication::class);
        $GLOBALS['TCA']     = ['pages' => ['columns' => [
            'seo_title' => ['config' => ['type' => 'input', 'max' => 255]],
            'slug'      => ['config' => ['type' => 'slug']],
        ]]];
    }

    private static function context(string $field, array $record = ['uid' => 12, 'pid' => 3], int $slugPid = 3): RecordContext
    {
        return new RecordContext('pages', $field, $record, '', 'Office chairs', 'Ergonomic chairs.', $slugPid);
    }

    private function subject(): FieldSuggestionController
    {
        $logged = &$this->logged;
        $logger = new class ($logged) extends AbstractLogger {
            /** @param list<string> $logged */
            public function __construct(private array &$logged) {}

            public function log($level, string|Stringable $message, array $context = []): void
            {
                $this->logged[] = $message . ' ' . json_encode($context);
            }
        };
        $context = $this->createStub(Context::class);
        $context->method('getPropertyFromAspect')->willReturn(7);

        return new FieldSuggestionController(
            $this->reader,
            new FieldSuggestionService($this->completion, $this->slugBuilder),
            $this->configurationRepository,
            $this->rateLimiter,
            $context,
            $logger,
            new LlmErrorClassifier(),
        );
    }

    /**
     * @param array<string, mixed>|string $body
     */
    private function request(array|string $body): ServerRequestInterface
    {
        $json   = is_string($body) ? $body : json_encode($body, JSON_THROW_ON_ERROR);
        $stream = $this->createStub(StreamInterface::class);
        $stream->method('__toString')->willReturn($json);
        $request = $this->createStub(ServerRequestInterface::class);
        $request->method('getBody')->willReturn($stream);

        return $request;
    }

    /**
     * @return array<string, mixed>
     */
    private static function json(ResponseInterface $response): array
    {
        /** @var array<string, mixed> $data */
        $data = json_decode((string) $response->getBody(), true, 512, JSON_THROW_ON_ERROR);

        return $data;
    }

    /**
     * @return array<string, mixed>
     */
    private static function body(array $overrides = []): array
    {
        return $overrides + ['table' => 'pages', 'field' => 'seo_title', 'uid' => 12, 'pid' => 12, 'count' => 3];
    }

    #[Test]
    public function returnsThreeSuggestionsForTheSeoTitle(): void
    {
        $this->completion->structuredResult = ['suggestions' => ['Office chairs', 'Ergonomic office chairs', 'Chairs for your office']];

        $response = $this->subject()->suggestAction($this->request(self::body()));

        self::assertSame(200, $response->getStatusCode());
        self::assertSame(
            ['success' => true, 'suggestions' => ['Office chairs', 'Ergonomic office chairs', 'Chairs for your office'], 'maxLength' => 60],
            self::json($response),
        );
        self::assertSame(FieldSuggestionService::schema(3), $this->completion->completeStructuredForConfigurationCalls[0]['schema']);
        self::assertSame('20', $response->getHeaderLine('X-RateLimit-Limit'));
    }

    #[Test]
    public function requestedCountAboveFiveAsksForFive(): void
    {
        $this->completion->structuredResult = ['suggestions' => ['1', '2', '3', '4', '5']];

        $response = $this->subject()->suggestAction($this->request(self::body(['count' => 50])));

        self::assertCount(5, self::json($response)['suggestions']);
        self::assertSame(FieldSuggestionService::schema(5), $this->completion->completeStructuredForConfigurationCalls[0]['schema']);
    }

    #[Test]
    public function rateLimitIsKeyedByTheBackendUser(): void
    {
        $this->rateLimiter = $this->createMock(RateLimiterInterface::class);
        $this->rateLimiter->expects(self::once())->method('checkLimit')->with('7')
            ->willReturn(new RateLimitResult(true, 20, 19, time() + 60));
        $this->completion->structuredResult = ['suggestions' => ['A']];

        $this->subject()->suggestAction($this->request(self::body(['count' => 1])));
    }

    #[Test]
    public function rateLimitIsCheckedFirst(): void
    {
        $this->rateLimiter = $this->createStub(RateLimiterInterface::class);
        $this->rateLimiter->method('checkLimit')->willReturn(new RateLimitResult(false, 20, 0, time() + 30));

        $response = $this->subject()->suggestAction($this->request(self::body()));

        self::assertSame(429, $response->getStatusCode());
        self::assertSame([], $this->completion->completeStructuredForConfigurationCalls);
    }

    #[Test]
    public function invalidJsonIsRejected(): void
    {
        $response = $this->subject()->suggestAction($this->request('{not json'));

        self::assertSame(400, $response->getStatusCode());
        self::assertSame(['success' => false, 'error' => 'Invalid JSON in request body.'], self::json($response));
    }

    #[Test]
    public function missingTableOrFieldIsRejected(): void
    {
        $response = $this->subject()->suggestAction($this->request(['field' => 'seo_title']));

        self::assertSame(400, $response->getStatusCode());
        self::assertSame(['success' => false, 'error' => 'Table and field are required.'], self::json($response));
    }

    #[Test]
    public function missingBackendUserIsRejected(): void
    {
        unset($GLOBALS['BE_USER']);

        $response = $this->subject()->suggestAction($this->request(self::body()));

        self::assertSame(403, $response->getStatusCode());
        self::assertSame(['success' => false, 'error' => 'No backend user session.'], self::json($response));
        self::assertSame([], $this->completion->completeStructuredForConfigurationCalls);
    }

    #[Test]
    public function permissionRefusalIsReturnedWithoutCallingTheModel(): void
    {
        $this->reader = $this->createStub(RecordContextReader::class);
        $this->reader->method('read')->willThrowException(FieldSuggestionException::accessDenied());

        $response = $this->subject()->suggestAction($this->request(self::body()));

        self::assertSame(403, $response->getStatusCode());
        self::assertSame(['success' => false, 'error' => 'You are not allowed to edit this field.'], self::json($response));
        self::assertSame([], $this->completion->completeStructuredForConfigurationCalls);
    }

    #[Test]
    public function slugOfASiteRootIsNotSuggested(): void
    {
        $this->reader = $this->createStub(RecordContextReader::class);
        $this->reader->method('read')->willReturn(self::context('slug', ['uid' => 1, 'pid' => 0, 'is_siteroot' => 1], 0));

        $response = $this->subject()->suggestAction($this->request(self::body(['field' => 'slug'])));

        self::assertSame(422, $response->getStatusCode());
        self::assertSame('The root page of a site always has the slug "/".', self::json($response)['error']);
        self::assertSame([], $this->completion->completeStructuredForConfigurationCalls);
    }

    #[Test]
    public function slugSuggestionsComeFromTheSlugBuilder(): void
    {
        $this->reader = $this->createStub(RecordContextReader::class);
        $this->reader->method('read')->willReturn(self::context('slug'));
        $this->slugBuilder->method('build')->willReturnCallback(
            static fn (string $segment): string => '/products/' . strtolower(str_replace(' ', '-', $segment)),
        );
        $this->completion->structuredResult = ['suggestions' => ['Office Chairs']];

        $response = $this->subject()->suggestAction($this->request(self::body(['field' => 'slug', 'count' => 1])));

        self::assertSame(['/products/office-chairs'], self::json($response)['suggestions']);
    }

    #[Test]
    public function missingLlmConfigurationIsReported(): void
    {
        $this->configurationRepository = $this->createStub(LlmConfigurationRepository::class);
        $this->configurationRepository->method('findDefault')->willReturn(null);

        $response = $this->subject()->suggestAction($this->request(self::body()));

        self::assertSame(404, $response->getStatusCode());
        self::assertSame(
            ['success' => false, 'error' => 'No LLM configuration available. Please configure the nr_llm extension.'],
            self::json($response),
        );
    }

    /**
     * @return iterable<string, array{Throwable, string}>
     */
    public static function failureProvider(): iterable
    {
        yield 'schema mismatch after repair' => [
            new InvalidArgumentException('Structured completion did not match the required schema after one repair attempt.', 1784500002),
            'The AI answer did not have the expected format. Please try again.',
        ];
        yield 'rejected API key' => [
            new ProviderResponseException('Unauthorized: the API key was rejected by the upstream service', 401),
            'The LLM provider rejected the API key. Please ask an administrator to check the provider settings.',
        ];
        yield 'provider rate limit' => [
            new ProviderResponseException('Too many requests', 429),
            'The LLM provider rate limit was exceeded. Please wait a moment and try again.',
        ];
        yield 'missing configuration' => [
            new ConfigurationNotFoundException('Configuration "x" not found'),
            'LLM is not configured yet. Ask an administrator to check the Cowriter Setup Status page for details.',
        ];
        yield 'anything else' => [
            new RuntimeException('cURL error 7: Failed to connect to internal-llm.example:11434'),
            'The suggestions could not be generated. Please try again later.',
        ];
    }

    #[Test]
    #[DataProvider('failureProvider')]
    public function llmFailuresReturnAReadableMessageAndNoProviderDetails(Throwable $failure, string $expected): void
    {
        $this->completion->throwable = $failure;

        $response = $this->subject()->suggestAction($this->request(self::body()));

        self::assertSame(502, $response->getStatusCode());
        self::assertSame(['success' => false, 'error' => $expected], self::json($response));
        self::assertStringNotContainsString($failure->getMessage(), (string) $response->getBody());
        // The details go to the log instead.
        self::assertSame(['Field suggestion failed ' . json_encode(['exception' => $failure->getMessage()])], $this->logged);
    }

    #[Test]
    public function answerWithoutUsableSuggestionsIsAnError(): void
    {
        $this->completion->structuredResult = ['suggestions' => ['', '   ']];

        $response = $this->subject()->suggestAction($this->request(self::body()));

        self::assertSame(502, $response->getStatusCode());
        self::assertSame(['success' => false, 'error' => 'The AI returned no usable suggestions. Please try again.'], self::json($response));
    }

    #[Test]
    public function nothingIsWrittenOrLoggedOnSuccess(): void
    {
        $this->completion->structuredResult = ['suggestions' => ['A']];

        $this->subject()->suggestAction($this->request(self::body(['count' => 1])));

        self::assertSame([], $this->logged);
    }
}
