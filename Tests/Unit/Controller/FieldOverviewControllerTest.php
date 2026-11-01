<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\T3Cowriter\Tests\Unit\Controller;

use InvalidArgumentException;
use Netresearch\NrLlm\Domain\DTO\BudgetCheckResult;
use Netresearch\NrLlm\Domain\Model\LlmConfiguration;
use Netresearch\NrLlm\Domain\Repository\LlmConfigurationRepository;
use Netresearch\NrLlm\Exception\BudgetExceededException;
use Netresearch\NrLlm\Exception\GuardrailViolationException;
use Netresearch\NrLlm\Provider\Exception\ProviderResponseException;
use Netresearch\NrLlm\Testing\FakeCompletionService;
use Netresearch\T3Cowriter\Controller\FieldOverviewController;
use Netresearch\T3Cowriter\Domain\DTO\FieldOverviewRequest;
use Netresearch\T3Cowriter\Service\FieldSuggestion\FieldKind;
use Netresearch\T3Cowriter\Service\FieldSuggestion\FieldOverviewService;
use Netresearch\T3Cowriter\Service\FieldSuggestion\FieldProfile;
use Netresearch\T3Cowriter\Service\FieldSuggestion\FieldReview;
use Netresearch\T3Cowriter\Service\FieldSuggestion\FieldSuggestionException;
use Netresearch\T3Cowriter\Service\FieldSuggestion\RecordContext;
use Netresearch\T3Cowriter\Service\FieldSuggestion\RecordContextReader;
use Netresearch\T3Cowriter\Service\FieldSuggestion\SlugSuggestionBuilder;
use Netresearch\T3Cowriter\Service\FieldSuggestion\SuggestionNormalizer;
use Netresearch\T3Cowriter\Service\FieldSuggestion\Tca;
use Netresearch\T3Cowriter\Service\RateLimiterInterface;
use Netresearch\T3Cowriter\Service\RateLimitResult;
use Netresearch\T3Cowriter\Service\Transparency\AboutBuilder;
use Netresearch\T3Cowriter\Service\Vision\RecordLanguageResolverInterface;
use Netresearch\T3Cowriter\Tests\Support\ConfigurationAccessDouble;
use Netresearch\T3Cowriter\Tests\Support\XliffLanguageServiceTrait;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\StreamInterface;
use Psr\Log\AbstractLogger;
use Stringable;
use TYPO3\CMS\Backend\Module\ModuleProvider;
use TYPO3\CMS\Backend\Routing\UriBuilder;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;
use TYPO3\CMS\Core\Context\Context;

#[CoversClass(FieldOverviewController::class)]
#[CoversClass(FieldOverviewRequest::class)]
#[CoversClass(FieldOverviewService::class)]
#[CoversClass(FieldReview::class)]
#[CoversClass(FieldSuggestionException::class)]
#[CoversClass(FieldProfile::class)]
#[CoversClass(FieldKind::class)]
#[CoversClass(RecordContext::class)]
#[CoversClass(SuggestionNormalizer::class)]
#[CoversClass(Tca::class)]
final class FieldOverviewControllerTest extends TestCase
{
    use XliffLanguageServiceTrait;

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
        $this->reader->method('readFields')->willReturn(['context' => $this->context(), 'fields' => ['seo_title', 'description']]);
        $this->useXliffLanguageService();

        $GLOBALS['BE_USER'] = $this->createStub(BackendUserAuthentication::class);
        $GLOBALS['TCA']     = ['pages' => ['columns' => [
            'seo_title'   => ['label' => 'Title for search engines', 'config' => ['type' => 'input', 'max' => 255]],
            'description' => ['label' => 'Description', 'config' => ['type' => 'text']],
            'slug'        => ['label' => 'URL Segment', 'config' => ['type' => 'slug']],
        ]]];
    }

    /**
     * @param array<string, mixed> $record
     */
    private function context(array $record = ['uid' => 12, 'pid' => 3], int $slugPid = 3): RecordContext
    {
        return new RecordContext('pages', 'seo_title', $record, '', 'Air fryers', 'We tested twelve air fryers.', $slugPid);
    }

    private function subject(?RecordLanguageResolverInterface $languageResolver = null, ?AboutBuilder $about = null): FieldOverviewController
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

        if (!$languageResolver instanceof RecordLanguageResolverInterface) {
            $languageResolver = $this->createStub(RecordLanguageResolverInterface::class);
            $languageResolver->method('languageName')->willReturn('German (Germany)');
        }

        return new FieldOverviewController(
            $this->reader,
            new FieldOverviewService($this->completion, $this->slugBuilder),
            ConfigurationAccessDouble::selector($this->configurationRepository),
            $this->rateLimiter,
            $languageResolver,
            $context,
            $logger,
            about: $about,
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
    private function json(ResponseInterface $response): array
    {
        /** @var array<string, mixed> $data */
        $data = json_decode((string) $response->getBody(), true, 512, JSON_THROW_ON_ERROR);

        return $data;
    }

    /**
     * @return array<string, mixed>
     */
    private function body(array $overrides = []): array
    {
        return $overrides + ['table' => 'pages', 'uid' => 12, 'pid' => 3, 'fields' => ['seo_title' => 'Old title', 'description' => 'Good description.']];
    }

    #[Test]
    public function theAnswerSaysWhichModelAndContextProducedIt(): void
    {
        $this->completion->structuredResult = ['fields' => [['field' => 'seo_title', 'action' => 'keep', 'value' => '', 'reason' => 'Fine.']]];
        $this->reader->method('suggestionFields')->willReturn(['seo_title' => 'SEO title']);
        $about = new AboutBuilder($this->createStub(UriBuilder::class), $this->createStub(ModuleProvider::class));

        $data = $this->json($this->subject(about: $about)->suggestAllAction($this->request($this->body())));

        self::assertIsArray($data['about']);
        self::assertSame(FakeCompletionService::STRUCTURED_MODEL, $data['about']['model']);
        self::assertSame('Suggestions for all fields', $data['about']['feature']);
        self::assertSame(['Page title', 'Current values of the reviewed fields', 'Content of 1 element'], $data['about']['sources']);
        self::assertSame(['Feature', 'Fields with suggestions you may edit', 'Limits'], array_column($data['about']['settings'], 'label'));
    }

    #[Test]
    public function returnsOneReviewPerFieldWithLabelLimitAndReason(): void
    {
        $this->completion->structuredResult = ['fields' => [
            ['field' => 'description', 'action' => 'keep', 'value' => '', 'reason' => 'Already a complete sentence.'],
            ['field' => 'seo_title', 'action' => 'change', 'value' => 'Air fryer test 2026', 'reason' => 'Names the main keyword first.'],
        ]];

        $response = $this->subject()->suggestAllAction($this->request($this->body()));

        self::assertSame(200, $response->getStatusCode(), (string) $response->getBody());
        self::assertSame(['success' => true, 'fields' => [
            [
                'field'   => 'seo_title', 'label' => 'Title for search engines', 'kind' => 'seoTitle', 'maxLength' => 60,
                'current' => 'Old title', 'action' => 'change', 'value' => 'Air fryer test 2026', 'reason' => 'Names the main keyword first.',
            ],
            [
                'field'   => 'description', 'label' => 'Description', 'kind' => 'metaDescription', 'maxLength' => 160,
                'current' => 'Good description.', 'action' => 'keep', 'value' => '', 'reason' => 'Already a complete sentence.',
            ],
        ]], $this->json($response));
        self::assertCount(1, $this->completion->completeStructuredForConfigurationCalls);
        self::assertSame(FieldOverviewService::schema(), $this->completion->completeStructuredForConfigurationCalls[0]['schema']);
        self::assertStringContainsString('Write every value and every reason in German (Germany).', (string) $this->completion->completeStructuredForConfigurationCalls[0]['options']?->getSystemPrompt());
        self::assertSame('20', $response->getHeaderLine('X-RateLimit-Limit'));
    }

    #[Test]
    public function theReaderDecidesWhichFieldsAreServed(): void
    {
        $this->reader = $this->createMock(RecordContextReader::class);
        $this->reader->expects(self::once())->method('readFields')
            ->with('pages', ['seo_title', 'description', 'author'], 12, 3, self::anything())
            ->willReturn(['context' => $this->context(), 'fields' => ['seo_title']]);
        $this->completion->structuredResult = ['fields' => [
            ['field' => 'seo_title', 'action' => 'keep', 'reason' => 'Fine.'],
            ['field' => 'description', 'action' => 'change', 'value' => 'Sneaked in', 'reason' => 'Not allowed.'],
        ]];

        $response = $this->subject()->suggestAllAction($this->request($this->body(['fields' => [
            'seo_title' => 'Old title', 'description' => 'x', 'author' => 'Mallory',
        ]])));

        self::assertSame(['seo_title'], array_column($this->json($response)['fields'], 'field'));
        $system = (string) $this->completion->completeStructuredForConfigurationCalls[0]['options']?->getSystemPrompt();
        self::assertStringContainsString('- seo_title (', $system);
        self::assertStringNotContainsString('- description (', $system);
        self::assertStringNotContainsString('- slug (', $system);
        self::assertStringNotContainsString('Mallory', $this->completion->completeStructuredForConfigurationCalls[0]['prompt']);
    }

    #[Test]
    public function oneRequestCountsOnceAgainstTheRateLimitOfTheUser(): void
    {
        $this->rateLimiter = $this->createMock(RateLimiterInterface::class);
        $this->rateLimiter->expects(self::once())->method('checkLimit')->with('7')
            ->willReturn(new RateLimitResult(true, 20, 19, time() + 60));
        $this->completion->structuredResult = ['fields' => [['field' => 'seo_title', 'action' => 'keep', 'reason' => 'Fine.']]];

        $this->subject()->suggestAllAction($this->request($this->body()));
    }

    #[Test]
    public function rateLimitIsCheckedFirst(): void
    {
        $this->rateLimiter = $this->createStub(RateLimiterInterface::class);
        $this->rateLimiter->method('checkLimit')->willReturn(new RateLimitResult(false, 20, 0, time() + 30));
        $this->reader = $this->createMock(RecordContextReader::class);
        $this->reader->expects(self::never())->method('readFields');

        $response = $this->subject()->suggestAllAction($this->request('{not json'));

        self::assertSame(429, $response->getStatusCode());
        self::assertSame([], $this->completion->completeStructuredForConfigurationCalls);
    }

    #[Test]
    public function invalidJsonIsRejected(): void
    {
        $response = $this->subject()->suggestAllAction($this->request('{not json'));

        self::assertSame(400, $response->getStatusCode());
        self::assertSame(['success' => false, 'error' => 'Invalid JSON in request body.'], $this->json($response));
    }

    #[Test]
    public function missingTableOrFieldsAreRejected(): void
    {
        foreach ([['fields' => ['seo_title' => '']], ['table' => 'pages', 'fields' => []], ['table' => 'pages', 'fields' => 'seo_title']] as $body) {
            $response = $this->subject()->suggestAllAction($this->request($body));

            self::assertSame(400, $response->getStatusCode());
            self::assertSame(['success' => false, 'error' => 'Table and fields are required.'], $this->json($response));
        }
    }

    #[Test]
    public function missingBackendUserIsRejected(): void
    {
        unset($GLOBALS['BE_USER']);

        $response = $this->subject()->suggestAllAction($this->request($this->body()));

        self::assertSame(403, $response->getStatusCode());
        self::assertSame([], $this->completion->completeStructuredForConfigurationCalls);
    }

    #[Test]
    public function permissionRefusalIsReturnedWithoutCallingTheModel(): void
    {
        $this->reader = $this->createStub(RecordContextReader::class);
        $this->reader->method('readFields')->willThrowException(FieldSuggestionException::accessDenied());

        $response = $this->subject()->suggestAllAction($this->request($this->body()));

        self::assertSame(403, $response->getStatusCode());
        self::assertSame(['success' => false, 'error' => 'You are not allowed to edit this field.'], $this->json($response));
        self::assertSame([], $this->completion->completeStructuredForConfigurationCalls);
    }

    #[Test]
    public function theSlugOfASiteRootIsLeftOutAndTheOtherFieldsAreReviewed(): void
    {
        $this->reader = $this->createStub(RecordContextReader::class);
        $this->reader->method('readFields')->willReturn([
            'context' => $this->context(['uid' => 1, 'pid' => 0, 'is_siteroot' => 1], 0),
            'fields'  => ['slug', 'seo_title'],
        ]);
        $this->completion->structuredResult = ['fields' => [['field' => 'seo_title', 'action' => 'keep', 'reason' => 'Fine.']]];

        $response = $this->subject()->suggestAllAction($this->request($this->body(['fields' => ['slug' => '/', 'seo_title' => 'Home']])));

        self::assertSame(200, $response->getStatusCode());
        $system = (string) $this->completion->completeStructuredForConfigurationCalls[0]['options']?->getSystemPrompt();
        self::assertStringContainsString('- seo_title (', $system);
        self::assertStringNotContainsString('- description (', $system);
        self::assertStringNotContainsString('- slug (', $system);
    }

    #[Test]
    public function aSiteRootWithOnlyTheSlugIsRefused(): void
    {
        $this->reader = $this->createStub(RecordContextReader::class);
        $this->reader->method('readFields')->willReturn([
            'context' => $this->context(['uid' => 1, 'pid' => 0, 'is_siteroot' => 1], 0),
            'fields'  => ['slug'],
        ]);

        $response = $this->subject()->suggestAllAction($this->request($this->body(['fields' => ['slug' => '/']])));

        self::assertSame(422, $response->getStatusCode());
        self::assertSame([], $this->completion->completeStructuredForConfigurationCalls);
    }

    #[Test]
    public function aNewRecordGetsNoLanguageLookup(): void
    {
        $resolver = $this->createMock(RecordLanguageResolverInterface::class);
        $resolver->expects(self::never())->method('languageName');
        $this->completion->structuredResult = ['fields' => [['field' => 'seo_title', 'action' => 'keep', 'reason' => 'Fine.']]];

        $this->subject($resolver)->suggestAllAction($this->request($this->body(['uid' => 'NEW123'])));

        self::assertStringContainsString(
            'in the language of the page content',
            (string) $this->completion->completeStructuredForConfigurationCalls[0]['options']?->getSystemPrompt(),
        );
    }

    #[Test]
    public function aRefusedBudgetIsA429NamingTheLimit(): void
    {
        $this->completion->throwable = new BudgetExceededException(
            BudgetCheckResult::denied(BudgetCheckResult::LIMIT_CONFIGURATION_DAILY_COST, 5.0, 5.0),
        );

        $response = $this->subject()->suggestAllAction($this->request($this->body()));

        self::assertSame(429, $response->getStatusCode());
        self::assertTrue($this->json($response)['budgetExceeded']);
    }

    #[Test]
    public function aGuardrailStopShowsItsReason(): void
    {
        $this->completion->throwable = new GuardrailViolationException('Guardrail', 'Es enthält Rabatt.');

        $response = $this->subject()->suggestAllAction($this->request($this->body()));

        self::assertStringContainsString('Es enthält Rabatt.', json_decode((string) $response->getBody(), true)['error']);
    }

    #[Test]
    public function aProviderFailureIsLoggedAndAnsweredWithoutDetails(): void
    {
        $this->completion->throwable = new ProviderResponseException('Unauthorized: key sk-123 was rejected', 401);

        $response = $this->subject()->suggestAllAction($this->request($this->body()));

        self::assertSame(502, $response->getStatusCode());
        self::assertStringNotContainsString('sk-123', (string) $response->getBody());
        self::assertStringContainsString('sk-123', $this->logged[0]);
    }

    #[Test]
    public function aSchemaMismatchIsReportedAsInvalidFormat(): void
    {
        $this->completion->throwable = new InvalidArgumentException('did not match', 1784500001);

        $response = $this->subject()->suggestAllAction($this->request($this->body()));

        self::assertSame(502, $response->getStatusCode());
        self::assertSame('The AI answer did not have the expected format. Please try again.', $this->json($response)['error']);
    }

    #[Test]
    public function anAnswerWithoutUsableEntriesIsAnError(): void
    {
        $this->completion->structuredResult = ['fields' => [['field' => 'seo_title', 'action' => 'change', 'value' => '', 'reason' => 'Empty.']]];

        $response = $this->subject()->suggestAllAction($this->request($this->body()));

        self::assertSame(502, $response->getStatusCode());
        self::assertSame('The AI returned no usable suggestions. Please try again.', $this->json($response)['error']);
    }

    #[Test]
    public function missingLlmConfigurationIsReportedWithoutCallingTheModel(): void
    {
        $this->configurationRepository = $this->createStub(LlmConfigurationRepository::class);
        $this->configurationRepository->method('findDefault')->willReturn(null);

        $response = $this->subject()->suggestAllAction($this->request($this->body()));

        self::assertNotSame(200, $response->getStatusCode());
        self::assertFalse($this->json($response)['success']);
        self::assertSame([], $this->completion->completeStructuredForConfigurationCalls);
    }
}
