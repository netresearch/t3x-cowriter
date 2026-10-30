<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\T3Cowriter\Tests\Unit\Controller;

use Netresearch\NrLlm\Provider\Middleware\MiddlewarePipeline;
use Netresearch\NrLlm\Service\BudgetServiceInterface;
use Netresearch\NrLlm\Service\Guardrail\InputGuardrailScreener;
use Netresearch\NrLlm\Service\UsageTrackerServiceInterface;
use Netresearch\NrLlm\Specialized\Image\DallEImageService;
use Netresearch\NrLlm\Specialized\Pricing\SpecializedCostCalculatorInterface;
use Netresearch\NrVault\Service\VaultServiceInterface;
use Netresearch\T3Cowriter\Controller\ImageContextController;
use Netresearch\T3Cowriter\Service\FieldSuggestion\FieldSuggestionException;
use Netresearch\T3Cowriter\Service\FieldSuggestion\RecordContext;
use Netresearch\T3Cowriter\Service\FieldSuggestion\RecordContextReader;
use Netresearch\T3Cowriter\Service\Image\ImageGenerationConfiguration;
use Netresearch\T3Cowriter\Service\Image\ImagePurpose;
use Netresearch\T3Cowriter\Service\Transparency\AboutBuilder;
use Netresearch\T3Cowriter\Service\Transparency\ModelUse;
use Netresearch\T3Cowriter\Tests\Support\XliffLanguageServiceTrait;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Psr\Log\NullLogger;
use TYPO3\CMS\Backend\Module\ModuleProvider;
use TYPO3\CMS\Backend\Routing\UriBuilder;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;
use TYPO3\CMS\Core\Configuration\ExtensionConfiguration;
use TYPO3\CMS\Core\Http\ServerRequest;

#[CoversClass(ImageContextController::class)]
#[CoversClass(ImagePurpose::class)]
#[CoversClass(ModelUse::class)]
final class ImageContextControllerTest extends TestCase
{
    use XliffLanguageServiceTrait;

    protected function setUp(): void
    {
        parent::setUp();
        $this->useXliffLanguageService();
        $GLOBALS['BE_USER'] = $this->createStub(BackendUserAuthentication::class);
        $GLOBALS['TCA']     = [
            'tt_content' => [
                'ctrl'    => ['label' => 'header'],
                'columns' => ['image' => ['label' => 'Images', 'config' => ['type' => 'file']]],
            ],
        ];
    }

    protected function tearDown(): void
    {
        unset($GLOBALS['BE_USER']);
        parent::tearDown();
    }

    /**
     * @param array<string, mixed> $settings image.* settings
     */
    private function controller(RecordContextReader $reader, array $settings = ['enable' => '1']): ImageContextController
    {
        $extensionConfiguration = $this->createStub(ExtensionConfiguration::class);
        $extensionConfiguration->method('get')->willReturnCallback(
            static fn (string $extension, string $path = ''): mixed => $extension === 'nr_llm' ? [] : ($settings[substr($path, strlen('image/'))] ?? null),
        );
        $budget = $this->createStub(BudgetServiceInterface::class);

        // Without nr-llm's model registry the service resolves to the fallback model.
        $imageService = new DallEImageService(
            $this->createStub(VaultServiceInterface::class),
            $this->createStub(RequestFactoryInterface::class),
            $this->createStub(StreamFactoryInterface::class),
            $extensionConfiguration,
            $this->createStub(UsageTrackerServiceInterface::class),
            new NullLogger(),
            $this->createStub(SpecializedCostCalculatorInterface::class),
            $budget,
            new MiddlewarePipeline([]),
            new InputGuardrailScreener([]),
        );

        $modules = $this->createStub(ModuleProvider::class);
        $modules->method('isModuleRegistered')->willReturn(false);

        return new ImageContextController(
            new ImageGenerationConfiguration($extensionConfiguration),
            $imageService,
            $reader,
            new AboutBuilder($this->createStub(UriBuilder::class), $modules),
        );
    }

    /**
     * @param array<string, string> $query
     */
    private function call(ImageContextController $controller, array $query): ResponseInterface
    {
        return $controller->contextAction((new ServerRequest('/typo3/ajax/cowriter/image-context', 'GET'))->withQueryParams($query));
    }

    /**
     * @return array<string, mixed>
     */
    private function decode(ResponseInterface $response): array
    {
        $data = json_decode((string) $response->getBody(), true);
        self::assertIsArray($data);

        return $data;
    }

    private function element(): RecordContext
    {
        return new RecordContext(
            table: 'tt_content',
            field: 'image',
            record: [],
            storedValue: '',
            pageTitle: 'Airfryer Test',
            pageContent: '',
            slugPid: 3,
            recordFields: ['header' => ['label' => 'Header', 'value' => 'Our test'], 'bodytext' => ['label' => 'Text', 'value' => 'Twelve fryers.']],
        );
    }

    #[Test]
    public function anEditableRecordGivesPurposeProposalAndTheModelTheGenerationWillUse(): void
    {
        $reader = $this->createMock(RecordContextReader::class);
        $reader->expects(self::once())->method('readForImage')->with('tt_content', 'image', 12, 3, $GLOBALS['BE_USER'])->willReturn($this->element());

        $data = $this->decode($this->call($this->controller($reader, ['enable' => '1', 'model' => 'gpt-image-2.5-flare']), [
            'table' => 'tt_content', 'field' => 'image', 'uid' => '12', 'pid' => '3',
        ]));

        self::assertTrue($data['success']);
        self::assertSame('for element "Our test" on page "Airfryer Test"', $data['purpose']);
        self::assertSame('Images', $data['field']);
        self::assertSame('Our test – Airfryer Test: Twelve fryers.', $data['proposal']);
        self::assertSame('gpt-image-2.5-flare', $data['about']['model']);
        self::assertSame('gpt-image-2.5-flare (set in the extension configuration)', $data['about']['settings'][1]['value']);
        self::assertSame(['Only your description of the image'], $data['about']['sources']);
    }

    #[Test]
    public function withoutAModelSettingNrLlmsDefaultIsNamed(): void
    {
        $reader = $this->createStub(RecordContextReader::class);
        $reader->method('readForImage')->willReturn($this->element());

        $data = $this->decode($this->call($this->controller($reader), ['table' => 'tt_content', 'field' => 'image', 'uid' => '12']));

        self::assertSame(ImageGenerationConfiguration::FALLBACK_MODEL, $data['about']['model']);
        self::assertSame(ImageGenerationConfiguration::FALLBACK_MODEL . ' (default image model of nr-llm)', $data['about']['settings'][1]['value']);
    }

    #[Test]
    #[DataProvider('refusals')]
    public function aRefusedOrMissingRecordGivesTheSameAnswerWithoutPurpose(FieldSuggestionException $refusal): void
    {
        $reader = $this->createStub(RecordContextReader::class);
        $reader->method('readForImage')->willThrowException($refusal);

        $response = $this->call($this->controller($reader), ['table' => 'tt_content', 'field' => 'image', 'uid' => '99']);
        $data     = $this->decode($response);

        self::assertSame(200, $response->getStatusCode());
        self::assertSame([true, '', '', ''], [$data['success'], $data['purpose'], $data['field'], $data['proposal']]);
        self::assertIsArray($data['about']);
    }

    /**
     * @return iterable<string, array{FieldSuggestionException}>
     */
    public static function refusals(): iterable
    {
        yield 'no access or no record' => [FieldSuggestionException::accessDenied()];
        yield 'unknown field' => [FieldSuggestionException::notEnabled()];
    }

    #[Test]
    #[DataProvider('unusableTargets')]
    public function anUnusableTargetIsNotReadAtAll(array $query): void
    {
        $reader = $this->createMock(RecordContextReader::class);
        $reader->expects(self::never())->method('readForImage');

        $data = $this->decode($this->call($this->controller($reader), $query));

        self::assertSame('', $data['purpose']);
    }

    /**
     * @return iterable<string, array{array<string, string>}>
     */
    public static function unusableTargets(): iterable
    {
        yield 'nothing' => [[]];
        yield 'no field' => [['table' => 'tt_content', 'uid' => '1']];
        yield 'table with a quote' => [['table' => "tt_content'", 'field' => 'image', 'uid' => '1']];
        yield 'field with a dot' => [['table' => 'tt_content', 'field' => 'a.b', 'uid' => '1']];
    }

    #[Test]
    public function aNewRecordIsReadAsUidZeroOnItsPage(): void
    {
        $reader = $this->createMock(RecordContextReader::class);
        $reader->expects(self::once())->method('readForImage')->with('tt_content', 'image', 0, 7)->willReturn($this->element());

        $this->call($this->controller($reader), ['table' => 'tt_content', 'field' => 'image', 'uid' => 'NEW123', 'pid' => '7']);
    }

    #[Test]
    public function theRouteRefusesWhileImageGenerationIsOff(): void
    {
        $reader = $this->createMock(RecordContextReader::class);
        $reader->expects(self::never())->method('readForImage');

        $response = $this->call($this->controller($reader, ['enable' => '0']), ['table' => 'tt_content', 'field' => 'image', 'uid' => '1']);

        self::assertSame(403, $response->getStatusCode());
        self::assertFalse($this->decode($response)['success']);
    }

    #[Test]
    public function theRouteRefusesWithoutBackendUser(): void
    {
        unset($GLOBALS['BE_USER']);
        $reader = $this->createMock(RecordContextReader::class);
        $reader->expects(self::never())->method('readForImage');

        self::assertSame(403, $this->call($this->controller($reader), [])->getStatusCode());
    }
}
