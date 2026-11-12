<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\T3Cowriter\Tests\Unit\Controller;

use Netresearch\NrLlm\Domain\DTO\BudgetCheckResult;
use Netresearch\NrLlm\Domain\Model\LlmConfiguration;
use Netresearch\NrLlm\Domain\Model\PromptSnippet;
use Netresearch\NrLlm\Domain\Repository\LlmConfigurationRepository;
use Netresearch\NrLlm\Domain\Repository\PromptSnippetRepository;
use Netresearch\NrLlm\Exception\BudgetExceededException;
use Netresearch\NrLlm\Exception\GuardrailViolationException;
use Netresearch\NrLlm\Provider\Exception\ProviderResponseException;
use Netresearch\NrLlm\Service\Prompt\PromptSnippetComposer;
use Netresearch\NrLlm\Testing\FakeCompletionService;
use Netresearch\T3Cowriter\Controller\VariantController;
use Netresearch\T3Cowriter\Domain\DTO\VariantCreateRequest;
use Netresearch\T3Cowriter\Domain\DTO\VariantProposeRequest;
use Netresearch\T3Cowriter\Domain\DTO\VariantRequestValue;
use Netresearch\T3Cowriter\Domain\DTO\VariantShowRequest;
use Netresearch\T3Cowriter\Service\RateLimiterInterface;
use Netresearch\T3Cowriter\Service\RateLimitResult;
use Netresearch\T3Cowriter\Service\Style\StyleInstructionBuilder;
use Netresearch\T3Cowriter\Service\Style\TargetLengthResolverInterface;
use Netresearch\T3Cowriter\Service\Transparency\AboutBuilder;
use Netresearch\T3Cowriter\Service\Variant\AudienceContexts;
use Netresearch\T3Cowriter\Service\Variant\VariantConfiguration;
use Netresearch\T3Cowriter\Service\Variant\VariantElement;
use Netresearch\T3Cowriter\Service\Variant\VariantElementReader;
use Netresearch\T3Cowriter\Service\Variant\VariantException;
use Netresearch\T3Cowriter\Service\Variant\VariantProposal;
use Netresearch\T3Cowriter\Service\Variant\VariantProposalService;
use Netresearch\T3Cowriter\Service\Variant\VariantShowToken;
use Netresearch\T3Cowriter\Service\Variant\VariantWriter;
use Netresearch\T3Cowriter\Tests\Support\ConfigurationAccessDouble;
use Netresearch\T3Cowriter\Tests\Support\RecordingLogger;
use Netresearch\T3Cowriter\Tests\Support\XliffLanguageServiceTrait;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\StreamInterface;
use TYPO3\CMS\Backend\Module\ModuleProvider;
use TYPO3\CMS\Backend\Routing\UriBuilder;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;
use TYPO3\CMS\Core\Configuration\ExtensionConfiguration;
use TYPO3\CMS\Core\Context\Context;
use TYPO3\CMS\Core\Crypto\HashService;
use TYPO3\CMS\Core\Http\Uri;

#[CoversClass(VariantController::class)]
#[CoversClass(VariantProposeRequest::class)]
#[CoversClass(VariantCreateRequest::class)]
#[CoversClass(VariantShowRequest::class)]
#[CoversClass(VariantRequestValue::class)]
#[CoversClass(VariantException::class)]
#[CoversClass(VariantConfiguration::class)]
#[CoversClass(VariantShowToken::class)]
#[CoversClass(VariantProposalService::class)]
#[CoversClass(VariantProposal::class)]
#[CoversClass(VariantElement::class)]
final class VariantControllerTest extends TestCase
{
    use XliffLanguageServiceTrait;

    private const AUDIENCE = 31;

    private const TONE = 34;

    private FakeCompletionService $completion;

    private VariantElementReader&Stub $reader;

    private VariantWriter&Stub $writer;

    private AudienceContexts&Stub $contexts;

    private LlmConfigurationRepository&Stub $configurationRepository;

    private RecordingLogger $logger;

    private bool $enabled = true;

    private bool $rateLimited = false;

    private BackendUserAuthentication&Stub $backendUser;

    protected function setUp(): void
    {
        $GLOBALS['TYPO3_CONF_VARS']['SYS']['encryptionKey'] = 'unit-test-encryption-key';
        $this->completion                                   = new FakeCompletionService();
        $this->completion->structuredResult                 = ['header' => 'Chairs for students', 'bodytext' => '<p>Cheap.</p>'];

        $this->reader = $this->createStub(VariantElementReader::class);
        $this->reader->method('read')->willReturn($this->element());
        $this->writer                  = $this->createStub(VariantWriter::class);
        $this->contexts                = $this->createStub(AudienceContexts::class);
        $this->configurationRepository = $this->createStub(LlmConfigurationRepository::class);
        $this->configurationRepository->method('findDefault')->willReturn(new LlmConfiguration());
        $this->logger = new RecordingLogger();
        $this->useXliffLanguageService();

        $this->backendUser            = $this->createStub(BackendUserAuthentication::class);
        $this->backendUser->workspace = 0;
        $GLOBALS['BE_USER']           = $this->backendUser;
    }

    protected function tearDown(): void
    {
        unset($GLOBALS['BE_USER']);
        parent::tearDown();
    }

    private function element(string $bodytext = '<p>Our chairs fit every office.</p>'): VariantElement
    {
        return new VariantElement(10, 2, 0, 'Office chairs', $bodytext);
    }

    private function useContexts(bool $mayAssign, array $available = [['uid' => 5, 'title' => 'Mobile']]): void
    {
        $this->contexts = $this->createStub(AudienceContexts::class);
        $this->contexts->method('isInstalled')->willReturn(true);
        $this->contexts->method('mayAssign')->willReturn($mayAssign);
        $this->contexts->method('available')->willReturn($available);
        $this->contexts->method('title')->willReturnCallback(static function (int $uid) use ($available): ?string {
            foreach ($available as $context) {
                if ($context['uid'] === $uid) {
                    return $context['title'];
                }
            }

            return null;
        });
    }

    private function snippet(int $uid, string $name, string $text, string $tag): PromptSnippet
    {
        $snippet = $this->createStub(PromptSnippet::class);
        $snippet->method('getUid')->willReturn($uid);
        $snippet->method('getName')->willReturn($name);
        $snippet->method('getSnippet')->willReturn($text);
        $snippet->method('getTagList')->willReturn([$tag]);

        return $snippet;
    }

    private function styleInstructions(): StyleInstructionBuilder
    {
        $audience   = $this->snippet(self::AUDIENCE, 'Students', 'Students on a small budget.', 'audience');
        $tone       = $this->snippet(self::TONE, 'Friendly', 'Warm and friendly.', 'tone_of_voice');
        $repository = $this->createStub(PromptSnippetRepository::class);
        $repository->method('findActiveByTag')->willReturnMap([
            ['audience', [$audience]],
            ['tone_of_voice', [$tone]],
        ]);
        $repository->method('findByUids')->willReturnCallback(
            static fn (array $uids): array => array_values(array_filter([self::AUDIENCE => $audience, self::TONE => $tone], static fn (int $uid): bool => in_array($uid, $uids, true), ARRAY_FILTER_USE_KEY)),
        );
        $targetLength = new class implements TargetLengthResolverInterface {
            public function targetWords(?array $recordContext): ?int
            {
                return null;
            }
        };

        return new StyleInstructionBuilder($repository, new PromptSnippetComposer(), $targetLength);
    }

    private function subject(?AboutBuilder $about = null): VariantController
    {
        $extensionConfiguration = $this->createStub(ExtensionConfiguration::class);
        $extensionConfiguration->method('get')->willReturn($this->enabled ? '1' : '0');
        $context = $this->createStub(Context::class);
        $context->method('getPropertyFromAspect')->willReturn(7);
        $uriBuilder = $this->createStub(UriBuilder::class);
        $uriBuilder->method('buildUriFromRoute')->willReturnCallback(
            static fn (string $route): Uri => new Uri($route === 'web_layout' ? '/typo3/module/web/layout?id=2' : '/typo3/record/edit?x=17'),
        );
        $rateLimiter = $this->createStub(RateLimiterInterface::class);
        $rateLimiter->method('checkLimit')->willReturn(new RateLimitResult(!$this->rateLimited, 20, $this->rateLimited ? 0 : 19, time() + 60));

        return new VariantController(
            new VariantConfiguration($extensionConfiguration),
            $this->reader,
            new VariantProposalService($this->completion),
            $this->writer,
            new VariantShowToken(new HashService()),
            $this->contexts,
            $this->styleInstructions(),
            ConfigurationAccessDouble::selector($this->configurationRepository),
            $rateLimiter,
            $uriBuilder,
            $context,
            $this->logger,
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
    private function createBody(int $context = 0): array
    {
        return [
            'uid'         => 10,
            'fingerprint' => $this->element()->fingerprint(),
            'header'      => '  Chairs for students  ',
            'bodytext'    => '<p>Cheap.</p>',
            'context'     => $context,
        ];
    }

    private function writerMock(): VariantWriter&MockObject
    {
        $writer       = $this->createMock(VariantWriter::class);
        $this->writer = $writer;

        return $writer;
    }

    #[Test]
    public function theProposalCarriesTheModelsTextTheFingerprintAndTheContexts(): void
    {
        $this->useContexts(true);
        $this->completion->structuredResult = ['header' => ' Chairs for students ', 'bodytext' => '<p>Cheap <script>x</script></p>'];

        $response = $this->subject($this->about())->proposeAction($this->request(['uid' => 10, 'audience' => self::AUDIENCE, 'tone' => self::TONE]));
        $data     = $this->json($response);

        self::assertSame(200, $response->getStatusCode(), (string) $response->getBody());
        self::assertSame('Chairs for students', $data['header']);
        // Raw: the dialog sanitises it.
        self::assertSame('<p>Cheap <script>x</script></p>', $data['bodytext']);
        // The saved text the model rewrote: the dialog keeps its images.
        self::assertSame('<p>Our chairs fit every office.</p>', $data['originalBodytext']);
        self::assertSame($this->element()->fingerprint(), $data['fingerprint']);
        self::assertSame([['uid' => 5, 'title' => 'Mobile']], $data['contexts']);
        self::assertSame('', $data['contextNote']);
        self::assertSame('Audience variant', $data['about']['feature'] ?? null);

        $call = $this->completion->completeStructuredForConfigurationCalls[0];
        self::assertStringContainsString('Students on a small budget.', (string) $call['options']?->getSystemPrompt());
        self::assertStringContainsString('Warm and friendly.', (string) $call['options']?->getSystemPrompt());
        self::assertStringContainsString('Our chairs fit every office.', $call['prompt']);
        self::assertSame(VariantProposalService::schema(), $call['schema']);
    }

    #[Test]
    public function withoutContextsTheProposalSaysSoAndOffersNone(): void
    {
        $data = $this->json($this->subject()->proposeAction($this->request(['uid' => 10, 'audience' => self::AUDIENCE])));

        self::assertSame([], $data['contexts']);
        self::assertSame('EXT:contexts is not installed: the variant is created hidden and without an audience assignment.', $data['contextNote']);
    }

    #[Test]
    public function anEditorWhoMayNotAssignContextsIsToldWhy(): void
    {
        $this->useContexts(false);

        $data = $this->json($this->subject()->proposeAction($this->request(['uid' => 10, 'audience' => self::AUDIENCE])));

        self::assertSame([], $data['contexts']);
        self::assertSame('You may not assign contexts to content elements. The variant is created hidden and without a context.', $data['contextNote']);
    }

    #[Test]
    public function inAWorkspaceTheProposalSaysThatContextsCannotBeAssigned(): void
    {
        $this->useContexts(false);
        $this->backendUser->workspace = 3;

        $data = $this->json($this->subject()->proposeAction($this->request(['uid' => 10, 'audience' => self::AUDIENCE])));

        self::assertStringStartsWith('Contexts cannot be assigned in a workspace', (string) $data['contextNote']);
    }

    #[Test]
    public function withoutAnyContextTheProposalSaysSo(): void
    {
        $this->useContexts(true, []);

        $data = $this->json($this->subject()->proposeAction($this->request(['uid' => 10, 'audience' => self::AUDIENCE])));

        self::assertStringStartsWith('There is no context to choose.', (string) $data['contextNote']);
    }

    /**
     * @return iterable<string, array{array<string, mixed>|string, int}>
     */
    public static function refusedProposals(): iterable
    {
        yield 'no json' => ['nope', 400];
        yield 'no audience' => [['uid' => 10], 400];
        yield 'negative tone' => [['uid' => 10, 'audience' => self::AUDIENCE, 'tone' => -1], 400];
        yield 'audience not offered' => [['uid' => 10, 'audience' => 99], 400];
        yield 'tone as audience' => [['uid' => 10, 'audience' => self::TONE], 400];
        yield 'tone not offered' => [['uid' => 10, 'audience' => self::AUDIENCE, 'tone' => 99], 400];
        yield 'audience as tone' => [['uid' => 10, 'audience' => self::AUDIENCE, 'tone' => self::AUDIENCE], 400];
    }

    /**
     * @param array<string, mixed>|string $body
     */
    #[Test]
    #[DataProvider('refusedProposals')]
    public function aRefusedProposalCostsNothing(array|string $body, int $status): void
    {
        $response = $this->subject()->proposeAction($this->request($body));

        self::assertSame($status, $response->getStatusCode());
        self::assertSame([], $this->completion->completeStructuredForConfigurationCalls);
    }

    #[Test]
    public function aSwitchedOffFeatureIsRefusedOnEveryRoute(): void
    {
        $this->enabled = false;

        self::assertSame(403, $this->subject()->proposeAction($this->request(['uid' => 10, 'audience' => self::AUDIENCE]))->getStatusCode());
        self::assertSame(403, $this->subject()->createAction($this->request($this->createBody()))->getStatusCode());
        self::assertSame(403, $this->subject()->showAction($this->request([]))->getStatusCode());
        self::assertSame([], $this->completion->completeStructuredForConfigurationCalls);
    }

    #[Test]
    public function aRateLimitedRequestIsRefused(): void
    {
        $this->rateLimited = true;

        self::assertSame(429, $this->subject()->proposeAction($this->request(['uid' => 10, 'audience' => self::AUDIENCE]))->getStatusCode());
        self::assertSame(429, $this->subject()->createAction($this->request($this->createBody()))->getStatusCode());
    }

    #[Test]
    public function withoutABackendUserNothingHappens(): void
    {
        unset($GLOBALS['BE_USER']);

        self::assertSame(403, $this->subject()->proposeAction($this->request(['uid' => 10, 'audience' => self::AUDIENCE]))->getStatusCode());
        self::assertSame(403, $this->subject()->createAction($this->request($this->createBody()))->getStatusCode());
        self::assertSame(403, $this->subject()->showAction($this->request(['variantUid' => 11, 'originalUid' => 10, 'context' => 5, 'token' => str_repeat('a', 64)]))->getStatusCode());
    }

    #[Test]
    public function anElementWithoutTextIsRefusedBeforeTheModel(): void
    {
        $this->reader = $this->createStub(VariantElementReader::class);
        $this->reader->method('read')->willReturn($this->element('<p> </p>'));

        $response = $this->subject()->proposeAction($this->request(['uid' => 10, 'audience' => self::AUDIENCE]));

        self::assertSame(422, $response->getStatusCode());
        self::assertSame([], $this->completion->completeStructuredForConfigurationCalls);
    }

    #[Test]
    public function aTooLongTextIsRefusedBeforeTheModel(): void
    {
        $this->reader = $this->createStub(VariantElementReader::class);
        $this->reader->method('read')->willReturn($this->element('<p>' . str_repeat('a', VariantProposalService::MAX_TEXT_LENGTH) . '</p>'));

        self::assertSame(422, $this->subject()->proposeAction($this->request(['uid' => 10, 'audience' => self::AUDIENCE]))->getStatusCode());
        self::assertSame([], $this->completion->completeStructuredForConfigurationCalls);
    }

    #[Test]
    public function aConnectedTranslationIsRefusedWithItsReason(): void
    {
        $this->reader = $this->createStub(VariantElementReader::class);
        $this->reader->method('read')->willReturn($this->element());
        $this->reader->method('refuseForTranslations')->willThrowException(VariantException::connectedTranslation());

        $response = $this->subject()->proposeAction($this->request(['uid' => 10, 'audience' => self::AUDIENCE]));

        self::assertSame(409, $response->getStatusCode());
        self::assertStringStartsWith('This element is a translation connected to the default language.', (string) $this->json($response)['error']);
    }

    #[Test]
    public function anEmptyAnswerIsA502(): void
    {
        $this->completion->structuredResult = ['header' => 'x', 'bodytext' => ''];

        $response = $this->subject()->proposeAction($this->request(['uid' => 10, 'audience' => self::AUDIENCE]));

        self::assertSame(502, $response->getStatusCode());
        self::assertSame('The language model gave no usable variant. Try again.', $this->json($response)['error']);
    }

    #[Test]
    public function aRefusedModelCallIsA429NamingTheLimit(): void
    {
        $this->completion->throwable = new BudgetExceededException(
            BudgetCheckResult::denied(BudgetCheckResult::LIMIT_CONFIGURATION_DAILY_COST, 5.0, 5.0),
        );

        $response = $this->subject()->proposeAction($this->request(['uid' => 10, 'audience' => self::AUDIENCE]));

        self::assertSame(429, $response->getStatusCode());
        self::assertTrue($this->json($response)['budgetExceeded'] ?? false);
    }

    #[Test]
    public function aGuardrailStopShowsItsReason(): void
    {
        $this->completion->throwable = new GuardrailViolationException('Guardrail', 'Es enthält Rabatt.');

        $response = $this->subject()->proposeAction($this->request(['uid' => 10, 'audience' => self::AUDIENCE]));

        self::assertSame('Es enthält Rabatt.', $this->json($response)['error'] ?? null);
    }

    #[Test]
    public function aFailedModelCallIsA502WithoutTheProviderMessage(): void
    {
        $this->completion->throwable = new ProviderResponseException('Unauthorized: secret-key-123 rejected', 401);

        $response = $this->subject()->proposeAction($this->request(['uid' => 10, 'audience' => self::AUDIENCE]));

        self::assertSame(502, $response->getStatusCode());
        self::assertStringNotContainsString('secret-key-123', (string) $response->getBody());
        self::assertStringContainsString('secret-key-123', $this->logger->records[0]['context']['exception'] ?? '');
    }

    #[Test]
    public function withoutAUsableConfigurationTheModelIsNotCalled(): void
    {
        $this->configurationRepository = $this->createStub(LlmConfigurationRepository::class);
        $this->configurationRepository->method('findDefault')->willReturn(null);

        $response = $this->subject()->proposeAction($this->request(['uid' => 10, 'audience' => self::AUDIENCE]));

        self::assertGreaterThanOrEqual(400, $response->getStatusCode());
        self::assertSame([], $this->completion->completeStructuredForConfigurationCalls);
    }

    #[Test]
    public function theCreationWritesTheReviewedTextWithItsContextAndIssuesAShowToken(): void
    {
        $this->useContexts(true);
        $this->writerMock()->expects(self::once())->method('create')
            ->with(self::isInstanceOf(VariantElement::class), 'Chairs for students', '<p>Cheap.</p>', 5, $this->backendUser)
            ->willReturn(20);

        $response = $this->subject()->createAction($this->request($this->createBody(5)));
        $data     = $this->json($response);

        self::assertSame(200, $response->getStatusCode(), (string) $response->getBody());
        self::assertSame(20, $data['uid']);
        self::assertSame(10, $data['originalUid']);
        self::assertSame(5, $data['context']);
        self::assertSame('Mobile', $data['contextTitle']);
        self::assertSame('', $data['note']);
        self::assertTrue((new VariantShowToken(new HashService()))->isValid((string) $data['showToken'], 20, 10, 5, 7, 0));
        self::assertStringEndsWith('#element-tt_content-20', (string) $data['pageUrl']);
        self::assertSame('/typo3/record/edit?x=17', $data['editUrl']);
    }

    #[Test]
    public function withoutAContextTheCreationGivesNoTokenButANote(): void
    {
        $this->writerMock()->expects(self::once())->method('create')->willReturn(20);

        $data = $this->json($this->subject()->createAction($this->request($this->createBody())));

        self::assertSame('', $data['showToken']);
        self::assertSame('', $data['contextTitle']);
        self::assertStringContainsString('EXT:contexts', (string) $data['note']);
    }

    #[Test]
    public function withContextsButWithoutAChoiceTheNoteNamesTheTakenOverSettings(): void
    {
        $this->useContexts(true);
        $this->writerMock()->expects(self::once())->method('create')->willReturn(20);

        $data = $this->json($this->subject()->createAction($this->request($this->createBody())));

        self::assertStringContainsString('carries the context settings of this element', (string) $data['note']);
    }

    /**
     * @return iterable<string, array{bool, list<array{uid: int, title: string}>}>
     */
    public static function unassignableContexts(): iterable
    {
        yield 'editor may not assign' => [false, [['uid' => 5, 'title' => 'Mobile']]];
        yield 'context not offered' => [true, [['uid' => 6, 'title' => 'Campaign']]];
    }

    /**
     * @param list<array{uid: int, title: string}> $available
     */
    #[Test]
    #[DataProvider('unassignableContexts')]
    public function aContextTheEditorMayNotAssignIsRefusedBeforeTheWrite(bool $mayAssign, array $available): void
    {
        $this->useContexts($mayAssign, $available);
        $this->writerMock()->expects(self::never())->method('create');

        self::assertSame(400, $this->subject()->createAction($this->request($this->createBody(5)))->getStatusCode());
    }

    #[Test]
    public function aChangedTextIsRefusedBeforeTheWrite(): void
    {
        $this->writerMock()->expects(self::never())->method('create');
        $body                = $this->createBody();
        $body['fingerprint'] = hash('sha256', 'other');

        self::assertSame(409, $this->subject()->createAction($this->request($body))->getStatusCode());
    }

    /**
     * @return iterable<string, array{array<string, mixed>}>
     */
    public static function invalidCreations(): iterable
    {
        yield 'no fingerprint' => [['uid' => 10, 'header' => 'x', 'bodytext' => '<p>y</p>']];
        yield 'empty text' => [['uid' => 10, 'fingerprint' => str_repeat('a', 64), 'header' => 'x', 'bodytext' => ' ']];
        yield 'header too long' => [['uid' => 10, 'fingerprint' => str_repeat('a', 64), 'header' => str_repeat('x', 256), 'bodytext' => '<p>y</p>']];
        yield 'text too long' => [['uid' => 10, 'fingerprint' => str_repeat('a', 64), 'header' => 'x', 'bodytext' => str_repeat('y', VariantCreateRequest::MAX_BODYTEXT_LENGTH + 1)]];
        yield 'malformed context' => [['uid' => 10, 'fingerprint' => str_repeat('a', 64), 'header' => 'x', 'bodytext' => '<p>y</p>', 'context' => 'five']];
        yield 'uid as float' => [['uid' => 10.5, 'fingerprint' => str_repeat('a', 64), 'header' => 'x', 'bodytext' => '<p>y</p>']];
    }

    /**
     * @param array<string, mixed> $body
     */
    #[Test]
    #[DataProvider('invalidCreations')]
    public function anInvalidCreationIsRefused(array $body): void
    {
        $this->writerMock()->expects(self::never())->method('create');

        self::assertSame(400, $this->subject()->createAction($this->request($body))->getStatusCode());
    }

    #[Test]
    public function aFailedWriteReturnsItsMessage(): void
    {
        $this->writerMock()->expects(self::once())->method('create')->willThrowException(VariantException::createIncomplete());

        $response = $this->subject()->createAction($this->request($this->createBody()));

        self::assertSame(500, $response->getStatusCode());
        self::assertStringStartsWith('The variant could not be created completely', (string) $this->json($response)['error']);
    }

    #[Test]
    public function theShowStepNeedsTheTokenOfTheCreatedVariant(): void
    {
        $this->useContexts(true);
        $writer = $this->writerMock();
        $writer->expects(self::once())->method('show')->with(20, 10, 5, true, $this->backendUser);
        $token = (new VariantShowToken(new HashService()))->issue(20, 10, 5, 7, 0);

        $response = $this->subject()->showAction($this->request(['variantUid' => 20, 'originalUid' => 10, 'context' => 5, 'hideOriginal' => true, 'token' => $token]));

        self::assertSame(200, $response->getStatusCode(), (string) $response->getBody());
    }

    /**
     * @return iterable<string, array{array<string, mixed>, int}>
     */
    public static function refusedShows(): iterable
    {
        yield 'another context' => [['variantUid' => 20, 'originalUid' => 10, 'context' => 6, 'hideOriginal' => true, 'tokenFor' => [20, 10, 5]], 403];
        yield 'another original' => [['variantUid' => 20, 'originalUid' => 11, 'context' => 5, 'hideOriginal' => true, 'tokenFor' => [20, 10, 5]], 403];
        yield 'no context' => [['variantUid' => 20, 'originalUid' => 10, 'context' => 0, 'hideOriginal' => true, 'tokenFor' => [20, 10, 0]], 400];
        yield 'same uids' => [['variantUid' => 10, 'originalUid' => 10, 'context' => 5, 'hideOriginal' => true, 'tokenFor' => [10, 10, 5]], 400];
    }

    /**
     * @param array<string, mixed> $body
     */
    #[Test]
    #[DataProvider('refusedShows')]
    public function aShowForAnotherPairIsRefused(array $body, int $status): void
    {
        $this->useContexts(true);
        $this->writerMock()->expects(self::never())->method('show');
        /** @var array{int, int, int} $for */
        $for           = $body['tokenFor'];
        $body['token'] = (new VariantShowToken(new HashService()))->issue($for[0], $for[1], $for[2], 7, 0);
        unset($body['tokenFor']);

        self::assertSame($status, $this->subject()->showAction($this->request($body))->getStatusCode());
    }

    #[Test]
    public function hidingTheOriginalNeedsTheRightOnTheContextSettings(): void
    {
        $this->useContexts(false);
        $this->writerMock()->expects(self::never())->method('show');
        $token = (new VariantShowToken(new HashService()))->issue(20, 10, 5, 7, 0);

        $response = $this->subject()->showAction($this->request(['variantUid' => 20, 'originalUid' => 10, 'context' => 5, 'hideOriginal' => true, 'token' => $token]));

        self::assertSame(400, $response->getStatusCode());
    }

    #[Test]
    public function showingWithoutHidingNeedsNoRightOnTheContextSettings(): void
    {
        $this->useContexts(false);
        $this->writerMock()->expects(self::once())->method('show')->with(20, 10, 5, false, $this->backendUser);
        $token = (new VariantShowToken(new HashService()))->issue(20, 10, 5, 7, 0);

        $response = $this->subject()->showAction($this->request(['variantUid' => 20, 'originalUid' => 10, 'context' => 5, 'hideOriginal' => false, 'token' => $token]));

        self::assertSame(200, $response->getStatusCode());
    }

    #[Test]
    public function aFailedShowReturnsItsMessage(): void
    {
        $this->useContexts(true);
        $this->writerMock()->expects(self::once())->method('show')->willThrowException(VariantException::showFailed());
        $token = (new VariantShowToken(new HashService()))->issue(20, 10, 5, 7, 0);

        $response = $this->subject()->showAction($this->request(['variantUid' => 20, 'originalUid' => 10, 'context' => 5, 'hideOriginal' => true, 'token' => $token]));

        self::assertSame(500, $response->getStatusCode());
    }

    private function about(): AboutBuilder
    {
        return new AboutBuilder($this->createStub(UriBuilder::class), $this->createStub(ModuleProvider::class));
    }
}
