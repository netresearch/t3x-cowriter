<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\T3Cowriter\Tests\Unit\Controller;

use Netresearch\NrLlm\Domain\DTO\BudgetCheckResult;
use Netresearch\NrLlm\Domain\Model\CompletionResponse;
use Netresearch\NrLlm\Domain\Model\LlmConfiguration;
use Netresearch\NrLlm\Domain\Model\UsageStatistics;
use Netresearch\NrLlm\Domain\Repository\LlmConfigurationRepository;
use Netresearch\NrLlm\Exception\BudgetExceededException;
use Netresearch\NrLlm\Exception\GuardrailViolationException;
use Netresearch\NrLlm\Testing\FakeCompletionService;
use Netresearch\T3Cowriter\Controller\InlineSuggestionController;
use Netresearch\T3Cowriter\Domain\DTO\InlineSuggestionRequest;
use Netresearch\T3Cowriter\Service\CallerSource;
use Netresearch\T3Cowriter\Service\InlineSuggestion\InlineSuggestionConfiguration;
use Netresearch\T3Cowriter\Service\InlineSuggestion\SuggestionText;
use Netresearch\T3Cowriter\Service\RateLimiterInterface;
use Netresearch\T3Cowriter\Service\RateLimitResult;
use Netresearch\T3Cowriter\Service\Vision\RecordLanguageResolverInterface;
use Netresearch\T3Cowriter\Tests\Support\ConfigurationAccessDouble;
use Netresearch\T3Cowriter\Tests\Support\XliffLanguageServiceTrait;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\StreamInterface;
use Psr\Log\AbstractLogger;
use RuntimeException;
use Stringable;
use TYPO3\CMS\Core\Configuration\ExtensionConfiguration;
use TYPO3\CMS\Core\Context\Context;

#[CoversClass(InlineSuggestionController::class)]
#[CoversClass(InlineSuggestionRequest::class)]
#[CoversClass(InlineSuggestionConfiguration::class)]
#[CoversClass(SuggestionText::class)]
final class InlineSuggestionControllerTest extends TestCase
{
    use XliffLanguageServiceTrait;

    private const TEXT = 'The lighthouse keeper climbed the stairs every evening';

    private FakeCompletionService $completion;

    /** @var array<string, mixed> */
    private array $settings = [];

    /** @var list<string> */
    private array $rateLimitKeys = [];

    private bool $rateLimitAllowed = true;

    private ?string $language = 'German (Germany)';

    private bool $languageFails = false;

    /** @var list<array{string, string, array<mixed>}> */
    private array $logged = [];

    /** @var list<string|null> */
    private array $selectedConfigurations = [];

    protected function setUp(): void
    {
        $this->useXliffLanguageService();
        $this->completion             = new FakeCompletionService();
        $this->settings               = ['enable' => '1'];
        $this->rateLimitKeys          = [];
        $this->rateLimitAllowed       = true;
        $this->language               = 'German (Germany)';
        $this->languageFails          = false;
        $this->logged                 = [];
        $this->selectedConfigurations = [];
    }

    #[Test]
    public function answersOneLineOfPlainTextAfterTheText(): void
    {
        $this->answer("to light the lamp.\nSecond line.");

        $response = $this->subject()->suggestAction($this->request(['text' => self::TEXT]));

        self::assertSame(200, $response->getStatusCode());
        self::assertSame(['success' => true, 'suggestion' => ' to light the lamp.'], $this->json($response));
        self::assertSame('12', $response->getHeaderLine('X-RateLimit-Limit'));
    }

    #[Test]
    public function asksTheModelForAShortContinuationAsTheEditor(): void
    {
        $this->answer('to light the lamp.');

        $this->subject()->suggestAction($this->request([
            'text'          => self::TEXT,
            'recordContext' => ['table' => 'tt_content', 'uid' => 12],
        ]));

        self::assertCount(1, $this->completion->completeForConfigurationCalls);
        $call    = $this->completion->completeForConfigurationCalls[0];
        $options = $call['options'];
        self::assertNotNull($options);
        self::assertSame(self::TEXT, $call['prompt']);
        self::assertSame(InlineSuggestionController::MAX_TOKENS, $options->getMaxTokens());
        self::assertSame(InlineSuggestionController::TEMPERATURE, $options->getTemperature());
        self::assertSame(["\n"], $options->getStopSequences());
        self::assertSame(7, $options->getBeUserUid());
        self::assertSame(SuggestionText::instruction('German (Germany)'), $options->getSystemPrompt());
        self::assertStringContainsString('Write in German (Germany).', $options->getSystemPrompt());
        self::assertSame(CallerSource::EXTENSION, $options->getCallerSourceExtension());
        self::assertSame('inlineSuggestion', $options->getCallerSourceOperation());
    }

    #[Test]
    public function withoutARecordTheModelKeepsTheLanguageOfTheText(): void
    {
        $this->answer('to light the lamp.');

        $this->subject()->suggestAction($this->request(['text' => self::TEXT]));

        self::assertSame(SuggestionText::instruction(null), $this->completion->completeForConfigurationCalls[0]['options']?->getSystemPrompt());
    }

    #[Test]
    public function aFailingLanguageLookupCostsTheHintNotTheSuggestion(): void
    {
        $this->languageFails = true;
        $this->answer('to light the lamp.');

        $response = $this->subject()->suggestAction($this->request([
            'text'          => self::TEXT,
            'recordContext' => ['table' => 'tt_content', 'uid' => 12],
        ]));

        self::assertSame(200, $response->getStatusCode());
        self::assertSame(SuggestionText::instruction(null), $this->completion->completeForConfigurationCalls[0]['options']?->getSystemPrompt());
        self::assertSame('warning', $this->logged[0][0]);
    }

    #[Test]
    public function theRateLimitHasAWindowOfItsOwnPerEditor(): void
    {
        $this->answer('to light the lamp.');

        $this->subject()->suggestAction($this->request(['text' => self::TEXT]));

        self::assertSame(['inline-suggestion:7'], $this->rateLimitKeys);
    }

    #[Test]
    public function aRateLimitedRequestIsRefusedBeforeAnythingElse(): void
    {
        $this->rateLimitAllowed = false;
        $this->settings         = ['enable' => '0'];

        $response = $this->subject()->suggestAction($this->request('{not json'));

        self::assertSame(429, $response->getStatusCode());
        self::assertSame('30', $response->getHeaderLine('Retry-After'));
        self::assertSame([], $this->completion->completeForConfigurationCalls);
    }

    #[Test]
    public function theRouteRefusesWhileTheSettingIsOff(): void
    {
        $this->settings = ['enable' => '0'];

        $response = $this->subject()->suggestAction($this->request(['text' => self::TEXT]));

        self::assertSame(403, $response->getStatusCode());
        self::assertStringContainsString('inlineSuggestions.enable', (string) $this->json($response)['error']);
        self::assertSame([], $this->completion->completeForConfigurationCalls);
    }

    #[Test]
    #[DataProvider('settingsThatAreNotOn')]
    public function anythingButOneCountsAsOff(mixed $value): void
    {
        $this->settings = ['enable' => $value];

        self::assertSame(403, $this->subject()->suggestAction($this->request(['text' => self::TEXT]))->getStatusCode());
    }

    /**
     * @return iterable<string, array{mixed}>
     */
    public static function settingsThatAreNotOn(): iterable
    {
        yield 'zero' => ['0'];
        yield 'empty' => [''];
        yield 'true as text' => ['true'];
        yield 'missing' => [null];
    }

    #[Test]
    public function invalidJsonIsRefused(): void
    {
        $response = $this->subject()->suggestAction($this->request('{not json'));

        self::assertSame(400, $response->getStatusCode());
        self::assertSame(['success' => false, 'error' => 'Invalid JSON in request body.'], $this->json($response));
        self::assertSame([], $this->completion->completeForConfigurationCalls);
    }

    #[Test]
    #[DataProvider('tooShortTexts')]
    public function aTextShorterThanTheMinimumIsRefused(mixed $text): void
    {
        $response = $this->subject()->suggestAction($this->request(['text' => $text]));

        self::assertSame(400, $response->getStatusCode());
        self::assertSame('The text is too short for a suggestion (at least 30 characters).', $this->json($response)['error']);
        self::assertSame([], $this->completion->completeForConfigurationCalls);
    }

    /**
     * @return iterable<string, array{mixed}>
     */
    public static function tooShortTexts(): iterable
    {
        yield 'empty' => [''];
        yield 'missing (not a string)' => [['nested']];
        yield '29 characters' => [str_repeat('a', 29)];
        yield '29 characters in spaces' => ['   ' . str_repeat('ä', 29) . "  \n"];
    }

    #[Test]
    public function theMinimumComesFromTheSettings(): void
    {
        $this->settings = ['enable' => '1', 'minChars' => '10'];
        $this->answer('goes on.');

        $response = $this->subject()->suggestAction($this->request(['text' => 'Ten chars!']));

        self::assertSame(200, $response->getStatusCode());
    }

    #[Test]
    public function aTextOfTheMaximumLengthIsAccepted(): void
    {
        $this->answer('goes on.');

        $text     = str_repeat('ü', InlineSuggestionRequest::MAX_TEXT_LENGTH);
        $response = $this->subject()->suggestAction($this->request(['text' => $text]));

        self::assertSame(200, $response->getStatusCode());
        self::assertSame($text, $this->completion->completeForConfigurationCalls[0]['prompt']);
    }

    #[Test]
    public function aLongerTextIsRefused(): void
    {
        $response = $this->subject()->suggestAction($this->request(['text' => str_repeat('a', InlineSuggestionRequest::MAX_TEXT_LENGTH + 1)]));

        self::assertSame(400, $response->getStatusCode());
        self::assertSame('The text for a suggestion is too long (at most 1000 characters).', $this->json($response)['error']);
        self::assertSame([], $this->completion->completeForConfigurationCalls);
    }

    #[Test]
    public function theConfigurationComesFromTheSettingsNeverFromTheRequest(): void
    {
        $this->settings = ['enable' => '1', 'configuration' => ' fast-model '];
        $this->answer('goes on.');

        $this->subject()->suggestAction($this->request(['text' => self::TEXT, 'configuration' => 'expensive']));

        self::assertSame(['fast-model'], $this->selectedConfigurations);
    }

    #[Test]
    public function withoutAConfiguredOneTheDefaultConfigurationAnswers(): void
    {
        $this->answer('goes on.');

        $this->subject()->suggestAction($this->request(['text' => self::TEXT, 'configuration' => 'expensive']));

        self::assertSame([null], $this->selectedConfigurations);
    }

    #[Test]
    public function aConfigurationTheEditorMayNotUseIsRefused(): void
    {
        $this->settings = ['enable' => '1', 'configuration' => 'denied'];

        $response = $this->subject()->suggestAction($this->request(['text' => self::TEXT]));

        self::assertSame(403, $response->getStatusCode());
        self::assertSame([], $this->completion->completeForConfigurationCalls);
    }

    #[Test]
    public function aRefusedSuggestionIsA429NamingTheLimit(): void
    {
        $this->completion->throwable = new BudgetExceededException(
            BudgetCheckResult::denied(BudgetCheckResult::LIMIT_CONFIGURATION_DAILY_COST, 5.0, 5.0),
        );

        $response = $this->subject()->suggestAction($this->request(['text' => self::TEXT]));

        self::assertSame(429, $response->getStatusCode());
        self::assertSame([
            'success'        => false,
            'error'          => 'The request was refused. This limit is used up: the daily cost of this configuration, shared by all users.',
            'budgetExceeded' => true,
            'exceededLimit'  => BudgetCheckResult::LIMIT_CONFIGURATION_DAILY_COST,
        ], $this->json($response));
    }

    #[Test]
    public function aGuardrailStopShowsItsReason(): void
    {
        $this->completion->throwable = new GuardrailViolationException('Guardrail', 'Es enthält Rabatt.');

        $response = $this->subject()->suggestAction($this->request(['text' => self::TEXT]));

        self::assertSame(['success' => false, 'error' => 'Es enthält Rabatt.'], $this->json($response));
    }

    #[Test]
    public function aFailedCallIsA502WithoutTheTextInTheLog(): void
    {
        $this->completion->throwable = new RuntimeException('provider said no');

        $response = $this->subject()->suggestAction($this->request(['text' => self::TEXT]));

        self::assertSame(502, $response->getStatusCode());
        self::assertSame(['success' => false, 'error' => 'No suggestion could be created.'], $this->json($response));
        self::assertSame('error', $this->logged[0][0]);
        self::assertSame(['exception' => 'provider said no'], $this->logged[0][2]);
        self::assertStringNotContainsString('lighthouse', (string) json_encode($this->logged));
    }

    #[Test]
    public function markupInTheAnswerIsStrippedNotEscaped(): void
    {
        $this->answer('<p>to <b>light</b> the lamp &amp; <script>alert(1)</script>the fire.</p>');

        $response = $this->subject()->suggestAction($this->request(['text' => self::TEXT]));

        self::assertSame(' to light the lamp & alert(1)the fire.', $this->json($response)['suggestion']);
    }

    #[Test]
    public function anEmptyAnswerIsAnEmptySuggestion(): void
    {
        $this->answer('  <think>reasoning</think>  ');

        $response = $this->subject()->suggestAction($this->request(['text' => self::TEXT]));

        self::assertSame(200, $response->getStatusCode());
        self::assertSame(['success' => true, 'suggestion' => ''], $this->json($response));
    }

    private function answer(string $content): void
    {
        $this->completion->responses[] = new CompletionResponse(
            content: $content,
            model: 'fake',
            usage: UsageStatistics::fromTokens(10, 5),
        );
    }

    private function subject(): InlineSuggestionController
    {
        $settings      = $this->settings;
        $configuration = $this->createStub(ExtensionConfiguration::class);
        $configuration->method('get')->willReturnCallback(
            static function (string $extension, string $path = '') use ($settings): mixed {
                $key = substr($path, strlen('inlineSuggestions/'));

                return $settings[$key] ?? null;
            },
        );

        $selected   = &$this->selectedConfigurations;
        $repository = $this->createStub(LlmConfigurationRepository::class);
        $repository->method('findDefault')->willReturnCallback(static function () use (&$selected): LlmConfiguration {
            $selected[] = null;

            return new LlmConfiguration();
        });
        $repository->method('findOneByIdentifier')->willReturnCallback(static function (string $identifier) use (&$selected): LlmConfiguration {
            $selected[]    = $identifier;
            $configuration = new LlmConfiguration();
            $configuration->setIdentifier($identifier);
            $configuration->setIsActive(true);

            return $configuration;
        });

        $keys        = &$this->rateLimitKeys;
        $allowed     = $this->rateLimitAllowed;
        $rateLimiter = $this->createStub(RateLimiterInterface::class);
        $rateLimiter->method('checkLimit')->willReturnCallback(static function (string $key) use (&$keys, $allowed): RateLimitResult {
            $keys[] = $key;

            return new RateLimitResult($allowed, 12, $allowed ? 11 : 0, time() + 30);
        });

        $language         = $this->language;
        $fails            = $this->languageFails;
        $languageResolver = $this->createStub(RecordLanguageResolverInterface::class);
        $languageResolver->method('languageName')->willReturnCallback(static function () use ($language, $fails): ?string {
            if ($fails) {
                throw new RuntimeException('no site');
            }

            return $language;
        });

        $context = $this->createStub(Context::class);
        $context->method('getPropertyFromAspect')->willReturn(7);

        $logged = &$this->logged;
        $logger = new class ($logged) extends AbstractLogger {
            /** @param list<array{string, string, array<mixed>}> $logged */
            public function __construct(private array &$logged) {}

            public function log($level, string|Stringable $message, array $context = []): void
            {
                $this->logged[] = [(string) $level, (string) $message, $context];
            }
        };

        return new InlineSuggestionController(
            $this->completion,
            new InlineSuggestionConfiguration($configuration),
            ConfigurationAccessDouble::selector($repository, ['denied']),
            $languageResolver,
            $rateLimiter,
            $context,
            $logger,
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
}
