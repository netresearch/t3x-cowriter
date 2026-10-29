<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\T3Cowriter\Tests\Unit\Controller;

use Netresearch\NrLlm\Domain\DTO\BudgetCheckResult;
use Netresearch\NrLlm\Domain\Model\LlmConfiguration;
use Netresearch\NrLlm\Domain\Repository\LlmConfigurationRepository;
use Netresearch\NrLlm\Exception\BudgetExceededException;
use Netresearch\NrLlm\Exception\ConfigurationNotFoundException;
use Netresearch\NrLlm\Provider\Exception\ProviderResponseException;
use Netresearch\NrLlm\Testing\FakeCompletionService;
use Netresearch\T3Cowriter\Controller\PresentationController;
use Netresearch\T3Cowriter\Domain\DTO\PresentationCreateRequest;
use Netresearch\T3Cowriter\Domain\DTO\PresentationProposeRequest;
use Netresearch\T3Cowriter\Service\Presentation\Layout;
use Netresearch\T3Cowriter\Service\Presentation\PresentationConfiguration;
use Netresearch\T3Cowriter\Service\Presentation\PresentationElement;
use Netresearch\T3Cowriter\Service\Presentation\PresentationElementReader;
use Netresearch\T3Cowriter\Service\Presentation\PresentationException;
use Netresearch\T3Cowriter\Service\Presentation\PresentationWriter;
use Netresearch\T3Cowriter\Service\Presentation\SectionContent;
use Netresearch\T3Cowriter\Service\Presentation\SectionProposalService;
use Netresearch\T3Cowriter\Service\RateLimiterInterface;
use Netresearch\T3Cowriter\Service\RateLimitResult;
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
use RuntimeException;
use Throwable;
use TYPO3\CMS\Backend\Routing\UriBuilder;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;
use TYPO3\CMS\Core\Configuration\ExtensionConfiguration;
use TYPO3\CMS\Core\Context\Context;
use TYPO3\CMS\Core\Http\Uri;

#[CoversClass(PresentationController::class)]
#[CoversClass(PresentationProposeRequest::class)]
#[CoversClass(PresentationCreateRequest::class)]
#[CoversClass(PresentationException::class)]
#[CoversClass(PresentationConfiguration::class)]
final class PresentationControllerTest extends TestCase
{
    use XliffLanguageServiceTrait;

    private const WITH_HEADINGS = '<p>Intro</p><h2>Seat</h2><p>Height</p><h2>Warranty</h2><p>Five years</p>';

    private const WITHOUT_HEADINGS = '<p>Chairs for every office.</p><p>Adjust the height.</p><p>Five years warranty.</p>';

    private FakeCompletionService $completion;

    private PresentationElementReader&Stub $reader;

    private PresentationWriter&Stub $writer;

    private RateLimiterInterface&Stub $rateLimiter;

    private LlmConfigurationRepository&Stub $configurationRepository;

    private RecordingLogger $logger;

    private bool $enabled = true;

    private mixed $sessionUserId = 7;

    /** @var list<array{string, array<string, mixed>}> */
    private array $builtUris = [];

    protected function setUp(): void
    {
        $this->completion              = new FakeCompletionService();
        $this->reader                  = $this->createStub(PresentationElementReader::class);
        $this->writer                  = $this->createStub(PresentationWriter::class);
        $this->rateLimiter             = $this->createStub(RateLimiterInterface::class);
        $this->configurationRepository = $this->createStub(LlmConfigurationRepository::class);
        $this->logger                  = new RecordingLogger();

        $this->rateLimiter->method('checkLimit')->willReturn(new RateLimitResult(true, 20, 19, time() + 60));
        $this->configurationRepository->method('findDefault')->willReturn(new LlmConfiguration());
        $this->reader->method('read')->willReturn($this->element(self::WITH_HEADINGS));
        $this->useXliffLanguageService();

        $GLOBALS['BE_USER'] = $this->createStub(BackendUserAuthentication::class);
    }

    protected function tearDown(): void
    {
        unset($GLOBALS['BE_USER']);
        parent::tearDown();
    }

    /**
     * @param list<Layout> $layouts
     */
    private function element(string $bodytext, array $layouts = [Layout::Tabs, Layout::Accordion]): PresentationElement
    {
        return new PresentationElement(10, 2, 0, 1, 'Office chairs', $bodytext, $layouts);
    }

    private function useElement(PresentationElement $element): void
    {
        $this->reader = $this->createStub(PresentationElementReader::class);
        $this->reader->method('read')->willReturn($element);
    }

    private function writerMock(): PresentationWriter&MockObject
    {
        $writer       = $this->createMock(PresentationWriter::class);
        $this->writer = $writer;

        return $writer;
    }

    private function subject(): PresentationController
    {
        $extensionConfiguration = $this->createStub(ExtensionConfiguration::class);
        $extensionConfiguration->method('get')->willReturn($this->enabled ? '1' : '0');
        $context = $this->createStub(Context::class);
        $context->method('getPropertyFromAspect')->willReturnCallback(
            fn (string $aspect, string $property, mixed $default): mixed => $this->sessionUserId ?? $default,
        );
        $uriBuilder = $this->createStub(UriBuilder::class);
        $uriBuilder->method('buildUriFromRoute')->willReturnCallback(function (string $route, array $parameters): Uri {
            $this->builtUris[] = [$route, $parameters];

            return new Uri('/typo3/record/edit?edit%5Btt_content%5D%5B17%5D=edit');
        });

        return new PresentationController(
            new PresentationConfiguration($extensionConfiguration),
            $this->reader,
            new SectionProposalService($this->completion),
            $this->writer,
            ConfigurationAccessDouble::selector($this->configurationRepository),
            $this->rateLimiter,
            $uriBuilder,
            $context,
            $this->logger,
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
     * @param array<string, mixed> $overrides
     *
     * @return array<string, mixed>
     */
    private function createBody(array $overrides = []): array
    {
        return $overrides + [
            'uid'         => 10,
            'layout'      => 'tab',
            'fingerprint' => hash('sha256', self::WITH_HEADINGS),
            'sections'    => [
                ['title' => 'Introduction', 'start' => 0, 'consumesHeading' => false],
                ['title' => 'Seat', 'start' => 1, 'consumesHeading' => true],
                ['title' => 'Warranty', 'start' => 3, 'consumesHeading' => true],
            ],
        ];
    }

    // ---- propose -------------------------------------------------------------

    #[Test]
    public function aTextWithHeadingsIsCutWithoutAModelCall(): void
    {
        $response = $this->subject()->proposeAction($this->request(['uid' => 10]));

        self::assertSame(200, $response->getStatusCode(), (string) $response->getBody());
        self::assertSame([
            'success'     => true,
            'source'      => 'headings',
            'fingerprint' => hash('sha256', self::WITH_HEADINGS),
            'layouts'     => ['tab', 'accordion'],
            'sections'    => [
                ['title' => 'Introduction', 'start' => 0, 'consumesHeading' => false, 'preview' => 'Intro'],
                ['title' => 'Seat', 'start' => 1, 'consumesHeading' => true, 'preview' => 'Height'],
                ['title' => 'Warranty', 'start' => 3, 'consumesHeading' => true, 'preview' => 'Five years'],
            ],
        ], $this->json($response));
        self::assertSame([], $this->completion->completeStructuredForConfigurationCalls);
    }

    #[Test]
    public function theIntroductionIsTitledInTheEditorsLanguage(): void
    {
        $this->useXliffLanguageService('de');

        $sections = $this->json($this->subject()->proposeAction($this->request(['uid' => 10])))['sections'];

        self::assertIsArray($sections);
        self::assertSame('Einleitung', $sections[0]['title'] ?? null);
    }

    #[Test]
    public function aTextWithoutHeadingsIsSplitByTheModel(): void
    {
        $this->useElement($this->element(self::WITHOUT_HEADINGS));
        $this->completion->structuredResult = ['sections' => [['title' => 'Chairs', 'start' => 0], ['title' => 'Warranty', 'start' => 2]]];

        $response = $this->subject()->proposeAction($this->request(['uid' => 10]));

        self::assertSame(200, $response->getStatusCode(), (string) $response->getBody());
        $data = $this->json($response);
        self::assertSame('model', $data['source']);
        self::assertSame([
            ['title' => 'Chairs', 'start' => 0, 'consumesHeading' => false, 'preview' => 'Chairs for every office. Adjust the height.'],
            ['title' => 'Warranty', 'start' => 2, 'consumesHeading' => false, 'preview' => 'Five years warranty.'],
        ], $data['sections']);
        self::assertCount(1, $this->completion->completeStructuredForConfigurationCalls);
        $options = $this->completion->completeStructuredForConfigurationCalls[0]['options'];
        self::assertSame(7, $options?->getBeUserUid());
    }

    #[Test]
    public function aLongSectionTextIsShortenedInThePreview(): void
    {
        $long = str_repeat('word ', 60);
        $this->useElement($this->element('<h2>A</h2><p>' . $long . '</p><h2>B</h2><p>b</p>'));

        $sections = $this->json($this->subject()->proposeAction($this->request(['uid' => 10])))['sections'];

        self::assertIsArray($sections);
        $preview = $sections[0]['preview'] ?? '';
        self::assertIsString($preview);
        self::assertSame(PresentationController::PREVIEW_LENGTH, mb_strlen($preview));
        self::assertStringEndsWith('…', $preview);
        self::assertSame('b', $sections[1]['preview'] ?? null);
    }

    #[Test]
    public function aModelAnswerWithoutUsableSectionsIsA502(): void
    {
        $this->useElement($this->element(self::WITHOUT_HEADINGS));
        $this->completion->structuredResult = ['sections' => [['title' => 'Only', 'start' => 0]]];

        $response = $this->subject()->proposeAction($this->request(['uid' => 10]));

        self::assertSame(502, $response->getStatusCode());
        self::assertStringStartsWith('No usable division into sections was proposed.', (string) ($this->json($response)['error'] ?? ''));
    }

    #[Test]
    public function aRefusedModelCallIsA429NamingTheLimit(): void
    {
        $this->useElement($this->element(self::WITHOUT_HEADINGS));
        $this->completion->throwable = new BudgetExceededException(
            BudgetCheckResult::denied(BudgetCheckResult::LIMIT_CONFIGURATION_DAILY_COST, 5.0, 5.0),
        );

        $response = $this->subject()->proposeAction($this->request(['uid' => 10]));

        self::assertSame(429, $response->getStatusCode());
        self::assertTrue($this->json($response)['budgetExceeded'] ?? false);
    }

    #[Test]
    public function aFailedModelCallIsA502WithoutTheProviderMessage(): void
    {
        $this->useElement($this->element(self::WITHOUT_HEADINGS));
        $this->completion->throwable = new ProviderResponseException('Unauthorized: secret-key-123 rejected', 401);

        $response = $this->subject()->proposeAction($this->request(['uid' => 10]));

        self::assertSame(502, $response->getStatusCode());
        self::assertStringNotContainsString('secret-key-123', (string) $response->getBody());
        self::assertStringContainsString('secret-key-123', $this->logger->records[0]['context']['exception'] ?? '');
    }

    #[Test]
    public function withoutAUsableConfigurationTheModelIsNotCalled(): void
    {
        $this->useElement($this->element(self::WITHOUT_HEADINGS));
        $this->configurationRepository = $this->createStub(LlmConfigurationRepository::class);
        $this->configurationRepository->method('findDefault')->willReturn(null);

        $response = $this->subject()->proposeAction($this->request(['uid' => 10]));

        self::assertSame(404, $response->getStatusCode());
        self::assertSame('No LLM configuration available. Please configure the nr_llm extension.', $this->json($response)['error'] ?? null);
        self::assertSame([], $this->completion->completeStructuredForConfigurationCalls);
    }

    #[Test]
    public function aTextWithLessThanTwoBlocksIsRefusedWithoutAModelCall(): void
    {
        $this->useElement($this->element('<p>Just one paragraph.</p>'));

        $response = $this->subject()->proposeAction($this->request(['uid' => 10]));

        self::assertSame(422, $response->getStatusCode());
        self::assertSame([], $this->completion->completeStructuredForConfigurationCalls);
    }

    #[Test]
    public function aLongTextWithoutHeadingsIsRefusedWithoutAModelCall(): void
    {
        $this->useElement($this->element(str_repeat('<p>x</p>', SectionProposalService::MAX_BLOCKS + 1)));

        $response = $this->subject()->proposeAction($this->request(['uid' => 10]));

        self::assertSame(422, $response->getStatusCode());
        self::assertSame([], $this->completion->completeStructuredForConfigurationCalls);
    }

    #[Test]
    public function aLongTextWithHeadingsIsStillCutAtThem(): void
    {
        $this->useElement($this->element('<h2>A</h2>' . str_repeat('<p>x</p>', 100) . '<h2>B</h2><p>y</p>'));

        $response = $this->subject()->proposeAction($this->request(['uid' => 10]));

        self::assertSame(200, $response->getStatusCode());
    }

    // ---- refusals shared by both actions -----------------------------------------

    /**
     * @return iterable<string, array{string}>
     */
    public static function actions(): iterable
    {
        yield 'propose' => ['proposeAction'];
        yield 'create' => ['createAction'];
    }

    private function call(string $action, ServerRequestInterface $request): ResponseInterface
    {
        $subject = $this->subject();

        return $action === 'proposeAction' ? $subject->proposeAction($request) : $subject->createAction($request);
    }

    #[Test]
    #[DataProvider('actions')]
    public function aRateLimitedRequestIsA429(string $action): void
    {
        $this->rateLimiter = $this->createStub(RateLimiterInterface::class);
        $this->rateLimiter->method('checkLimit')->willReturn(new RateLimitResult(false, 20, 0, time() + 60));
        $this->writerMock()->expects(self::never())->method('create');

        $response = $this->call($action, $this->request($this->createBody()));

        self::assertSame(429, $response->getStatusCode());
        self::assertSame([], $this->completion->completeStructuredForConfigurationCalls);
    }

    #[Test]
    #[DataProvider('actions')]
    public function whileSwitchedOffNothingIsReadOrWritten(string $action): void
    {
        $this->enabled = false;
        $reader        = $this->createMock(PresentationElementReader::class);
        $reader->expects(self::never())->method('read');
        $this->reader = $reader;
        $this->writerMock()->expects(self::never())->method('create');

        $response = $this->call($action, $this->request($this->createBody()));

        self::assertSame(403, $response->getStatusCode());
        self::assertSame('Presenting a text as tabs or accordion is switched off.', $this->json($response)['error'] ?? null);
    }

    #[Test]
    #[DataProvider('actions')]
    public function malformedJsonIsA400(string $action): void
    {
        self::assertSame(400, $this->call($action, $this->request('{nope'))->getStatusCode());
    }

    #[Test]
    #[DataProvider('actions')]
    public function aRequestWithoutUidIsA400(string $action): void
    {
        self::assertSame(400, $this->call($action, $this->request($this->createBody(['uid' => 0])))->getStatusCode());
    }

    #[Test]
    #[DataProvider('actions')]
    public function withoutBackendUserTheRequestIsRefused(string $action): void
    {
        unset($GLOBALS['BE_USER']);
        $this->writerMock()->expects(self::never())->method('create');

        self::assertSame(403, $this->call($action, $this->request($this->createBody()))->getStatusCode());
    }

    #[Test]
    #[DataProvider('actions')]
    public function anElementTheEditorMayNotPresentIsA403(string $action): void
    {
        $this->reader = $this->createStub(PresentationElementReader::class);
        $this->reader->method('read')->willThrowException(PresentationException::notAvailable());
        $this->writerMock()->expects(self::never())->method('create');

        $response = $this->call($action, $this->request($this->createBody()));

        self::assertSame(403, $response->getStatusCode());
        self::assertFalse($this->json($response)['success']);
        self::assertStringStartsWith('This element cannot be presented', (string) ($this->json($response)['error'] ?? ''));
    }

    // ---- create --------------------------------------------------------------

    #[Test]
    public function theConfirmedSectionsAreCutFromTheStoredTextAndWritten(): void
    {
        $this->writerMock()->expects(self::once())->method('create')
            ->with(
                self::isInstanceOf(PresentationElement::class),
                Layout::Accordion,
                self::equalTo([
                    new SectionContent('Intro', '<p>Intro</p>', 'Intro'),
                    new SectionContent('Seat & back', '<p>Height</p>', 'Height'),
                    new SectionContent('Warranty', '<p>Five years</p>', 'Five years'),
                ]),
                $GLOBALS['BE_USER'],
            )
            ->willReturn(17);

        $body                         = $this->createBody(['layout' => 'accordion']);
        $body['sections'][0]['title'] = 'Intro';
        $body['sections'][1]['title'] = '<b>Seat</b> &amp; back';

        $response = $this->subject()->createAction($this->request($body));

        self::assertSame(200, $response->getStatusCode(), (string) $response->getBody());
        self::assertSame([
            'success' => true,
            'uid'     => 17,
            'layout'  => 'accordion',
            'editUrl' => '/typo3/record/edit?edit%5Btt_content%5D%5B17%5D=edit',
        ], $this->json($response));
        self::assertSame([['record_edit', ['edit' => ['tt_content' => [17 => 'edit']]]]], $this->builtUris);
    }

    #[Test]
    public function aLayoutTheEditorMayNotUseIsA403(): void
    {
        $this->useElement($this->element(self::WITH_HEADINGS, [Layout::Accordion]));
        $this->writerMock()->expects(self::never())->method('create');

        self::assertSame(403, $this->subject()->createAction($this->request($this->createBody()))->getStatusCode());
    }

    #[Test]
    public function aTextChangedSinceTheProposalIsA409(): void
    {
        $this->writerMock()->expects(self::never())->method('create');

        $response = $this->subject()->createAction($this->request($this->createBody(['fingerprint' => hash('sha256', 'older text')])));

        self::assertSame(409, $response->getStatusCode());
    }

    /**
     * @return iterable<string, array{array<string, mixed>}>
     */
    public static function invalidCreateBodies(): iterable
    {
        yield 'unknown layout' => [['layout' => 'carousel']];
        yield 'no fingerprint' => [['fingerprint' => 'abc']];
        yield 'no sections' => [['sections' => []]];
        yield 'a section without start' => [['sections' => [['title' => 'A', 'consumesHeading' => false], ['title' => 'B', 'start' => 1, 'consumesHeading' => true]]]];
        yield 'a start as string' => [['sections' => [['title' => 'A', 'start' => '0', 'consumesHeading' => false], ['title' => 'B', 'start' => 1, 'consumesHeading' => true]]]];
        yield 'sections as object' => [['sections' => ['a' => ['title' => 'A', 'start' => 0, 'consumesHeading' => false]]]];
        yield 'too many sections' => [['sections' => array_fill(0, 13, ['title' => 'A', 'start' => 0, 'consumesHeading' => false])]];
    }

    /**
     * @param array<string, mixed> $overrides
     */
    #[Test]
    #[DataProvider('invalidCreateBodies')]
    public function malformedCreateRequestsAreA400(array $overrides): void
    {
        $this->writerMock()->expects(self::never())->method('create');

        self::assertSame(400, $this->subject()->createAction($this->request($this->createBody($overrides)))->getStatusCode());
    }

    /**
     * @return iterable<string, array{list<array<string, mixed>>}>
     */
    public static function sectionsThatDoNotFit(): iterable
    {
        yield 'past the end of the text' => [[['title' => 'A', 'start' => 0, 'consumesHeading' => false], ['title' => 'B', 'start' => 9, 'consumesHeading' => false]]];
        yield 'a title that is only markup' => [[['title' => 'A', 'start' => 0, 'consumesHeading' => false], ['title' => '<img src=x>', 'start' => 1, 'consumesHeading' => true]]];
        yield 'a paragraph taken for a heading' => [[['title' => 'A', 'start' => 0, 'consumesHeading' => true], ['title' => 'B', 'start' => 1, 'consumesHeading' => true]]];
    }

    /**
     * @param list<array<string, mixed>> $sections
     */
    #[Test]
    #[DataProvider('sectionsThatDoNotFit')]
    public function sectionsThatDoNotFitTheTextAreA400(array $sections): void
    {
        $this->writerMock()->expects(self::never())->method('create');

        $response = $this->subject()->createAction($this->request($this->createBody(['sections' => $sections])));

        self::assertSame(400, $response->getStatusCode());
        self::assertStringStartsWith('The sections do not fit', (string) ($this->json($response)['error'] ?? ''));
    }

    #[Test]
    public function aFailedCreationIsReportedWithItsStatus(): void
    {
        $this->writer->method('create')->willThrowException(PresentationException::createFailed());

        $response = $this->subject()->createAction($this->request($this->createBody()));

        self::assertSame(500, $response->getStatusCode());
        self::assertSame('The new element could not be created. Nothing was changed.', $this->json($response)['error'] ?? null);
    }

    #[Test]
    public function withoutTheEditRouteTheCreationStillSucceeds(): void
    {
        $this->writer->method('create')->willReturn(17);
        $extensionConfiguration = $this->createStub(ExtensionConfiguration::class);
        $extensionConfiguration->method('get')->willReturn('1');
        $uriBuilder = $this->createStub(UriBuilder::class);
        $uriBuilder->method('buildUriFromRoute')->willThrowException(new RuntimeException('no route'));
        $context = $this->createStub(Context::class);

        $subject = new PresentationController(
            new PresentationConfiguration($extensionConfiguration),
            $this->reader,
            new SectionProposalService($this->completion),
            $this->writer,
            ConfigurationAccessDouble::selector($this->configurationRepository),
            $this->rateLimiter,
            $uriBuilder,
            $context,
            $this->logger,
        );

        $response = $subject->createAction($this->request($this->createBody()));

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('', $this->json($response)['editUrl'] ?? null);
    }

    #[Test]
    public function twoBlocksAreEnoughForAProposal(): void
    {
        $this->useElement($this->element('<p>One</p><p>Two</p>'));
        $this->completion->structuredResult = ['sections' => [['title' => 'A', 'start' => 0], ['title' => 'B', 'start' => 1]]];

        self::assertSame(200, $this->subject()->proposeAction($this->request(['uid' => 10]))->getStatusCode());
        self::assertCount(1, $this->completion->completeStructuredForConfigurationCalls);
    }

    #[Test]
    public function theMaximumNumberOfBlocksStillGoesToTheModel(): void
    {
        $this->useElement($this->element(str_repeat('<p>x</p>', SectionProposalService::MAX_BLOCKS)));
        $this->completion->structuredResult = ['sections' => [['title' => 'A', 'start' => 0], ['title' => 'B', 'start' => 1]]];

        self::assertSame(200, $this->subject()->proposeAction($this->request(['uid' => 10]))->getStatusCode());
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function previews(): iterable
    {
        $exact = str_repeat('ä', PresentationController::PREVIEW_LENGTH);

        yield 'exactly the length' => [$exact, $exact];
        yield 'one character more' => [$exact . 'b', str_repeat('ä', PresentationController::PREVIEW_LENGTH - 1) . '…'];
        yield 'a space where it is cut' => [str_repeat('a', PresentationController::PREVIEW_LENGTH - 2) . ' bcd', str_repeat('a', PresentationController::PREVIEW_LENGTH - 2) . '…'];
    }

    #[Test]
    #[DataProvider('previews')]
    public function thePreviewIsCutByCharacters(string $text, string $expected): void
    {
        $this->useElement($this->element('<h2>A</h2><p>' . $text . '</p><h2>B</h2><p>b</p>'));

        $sections = $this->json($this->subject()->proposeAction($this->request(['uid' => 10])))['sections'];

        self::assertIsArray($sections);
        self::assertSame($expected, $sections[0]['preview'] ?? null);
    }

    /**
     * @return iterable<string, array{Throwable, string}>
     */
    public static function modelFailures(): iterable
    {
        yield 'configuration' => [
            new ConfigurationNotFoundException('Configuration "x" not found'),
            'LLM is not configured yet. Ask an administrator to check the Cowriter Setup Status page for details.',
        ];
        yield 'authentication' => [new ProviderResponseException('Unauthorized', 401), 'The LLM provider rejected the API key. Please ask an administrator to check the provider settings.'];
        yield 'rate limit' => [new ProviderResponseException('Too many requests', 429), 'The LLM provider rate limit was exceeded. Please wait a moment and try again.'];
        yield 'anything else' => [new RuntimeException('boom'), 'No usable division into sections was proposed. Try again, or add headings (level 2) where the sections begin.'];
    }

    #[Test]
    #[DataProvider('modelFailures')]
    public function aFailedModelCallIsExplainedByItsKind(Throwable $failure, string $message): void
    {
        $this->useElement($this->element(self::WITHOUT_HEADINGS));
        $this->completion->throwable = $failure;

        $response = $this->subject()->proposeAction($this->request(['uid' => 10]));

        self::assertSame(502, $response->getStatusCode());
        self::assertSame(['success' => false, 'error' => $message], $this->json($response));
    }

    #[Test]
    public function theSessionUserIdIsReadAsAnInteger(): void
    {
        $this->useElement($this->element(self::WITHOUT_HEADINGS));
        $this->completion->structuredResult = ['sections' => [['title' => 'A', 'start' => 0], ['title' => 'B', 'start' => 1]]];
        $this->sessionUserId                = '9';

        self::assertSame(200, $this->subject()->proposeAction($this->request(['uid' => 10]))->getStatusCode());
        self::assertSame(9, $this->completion->completeStructuredForConfigurationCalls[0]['options']?->getBeUserUid());
    }

    #[Test]
    public function withoutASessionUserTheRateLimitCountsUserZero(): void
    {
        $this->sessionUserId = null;
        $rateLimiter         = $this->createMock(RateLimiterInterface::class);
        $rateLimiter->expects(self::once())->method('checkLimit')->with('0')
            ->willReturn(new RateLimitResult(false, 20, 0, time() + 60));
        $this->rateLimiter = $rateLimiter;

        self::assertSame(429, $this->subject()->proposeAction($this->request(['uid' => 10]))->getStatusCode());
    }
}
