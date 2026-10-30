<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\T3Cowriter\Tests\Functional\Transparency;

use Netresearch\T3Cowriter\Controller\ImageContextController;
use Netresearch\T3Cowriter\Service\FieldSuggestion\RecordContextReader;
use Netresearch\T3Cowriter\Service\FieldSuggestion\RecordFinder;
use Netresearch\T3Cowriter\Service\Image\ImageGenerationConfiguration;
use Netresearch\T3Cowriter\Service\Image\ImagePurpose;
use Netresearch\T3Cowriter\Service\Transparency\AboutBuilder;
use Netresearch\T3Cowriter\Service\Transparency\ModelUse;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Psr\Http\Message\ResponseInterface;
use TYPO3\CMS\Core\Http\ServerRequest;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;

/**
 * The image dialog's context route, built by the DI container, against real
 * records and page permissions.
 *
 * image.model is set, so the route never asks nr-llm's model registry for
 * its default image model.
 */
#[CoversClass(ImageContextController::class)]
#[CoversClass(ImagePurpose::class)]
#[CoversClass(ImageGenerationConfiguration::class)]
#[CoversClass(RecordContextReader::class)]
#[CoversClass(RecordFinder::class)]
#[CoversClass(AboutBuilder::class)]
#[CoversClass(ModelUse::class)]
final class ImageContextControllerTest extends FunctionalTestCase
{
    protected array $coreExtensionsToLoad = ['rte_ckeditor'];

    protected array $testExtensionsToLoad = [
        'netresearch/nr-vault',
        'netresearch/nr-llm',
        'netresearch/t3-cowriter',
    ];

    protected array $configurationToUseInTestInstance = [
        'EXTENSIONS' => [
            't3_cowriter' => [
                'image' => ['enable' => '1', 'model' => 'gpt-image-2'],
            ],
        ],
    ];

    protected function setUp(): void
    {
        parent::setUp();
        $this->importCSVDataSet(__DIR__ . '/Fixtures/neighbourhood.csv');
        $this->setUpBackendUser(2);
    }

    /**
     * @param array<string, string> $query
     */
    private function context(array $query): ResponseInterface
    {
        return $this->get(ImageContextController::class)->contextAction(
            (new ServerRequest('https://example.com/typo3/ajax/cowriter/image-context', 'GET'))->withQueryParams($query),
        );
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

    #[Test]
    public function elementTheEditorMayEditGetsPurposeAndProposal(): void
    {
        $response = $this->context(['table' => 'tt_content', 'field' => 'image', 'uid' => '100', 'pid' => '3']);

        self::assertSame(200, $response->getStatusCode(), (string) $response->getBody());
        $data = $this->json($response);
        self::assertTrue($data['success']);
        self::assertSame('for element "Ergonomic chairs" on page "Office chairs"', $data['purpose']);
        self::assertIsString($data['proposal']);
        self::assertStringStartsWith('Ergonomic chairs', $data['proposal']);
        self::assertStringContainsString('Adjustable seat height', $data['proposal']);
        self::assertNotSame('', $data['field']);
        self::assertIsArray($data['about']);
        self::assertSame(['Only your description of the image'], $data['about']['sources']);
    }

    #[Test]
    public function refusedMissingAndUnknownGetTheSameEmptyAnswer(): void
    {
        $answers = [];
        foreach ([
            'record on a page the editor may not edit' => ['table' => 'tt_content', 'field' => 'image', 'uid' => '105', 'pid' => '50'],
            'record that does not exist'               => ['table' => 'tt_content', 'field' => 'image', 'uid' => '9999', 'pid' => '3'],
            'unknown field'                            => ['table' => 'tt_content', 'field' => 'no_such_field', 'uid' => '100', 'pid' => '3'],
        ] as $case => $query) {
            $response = $this->context($query);
            self::assertSame(200, $response->getStatusCode(), $case);
            $data = $this->json($response);
            self::assertTrue($data['success'], $case);
            self::assertSame('', $data['purpose'], $case);
            self::assertSame('', $data['proposal'], $case);
            self::assertStringNotContainsString('LOCKED', (string) $response->getBody(), $case);
            $answers[$case] = $data;
        }

        // The route does not tell which of the three it was.
        $first = array_shift($answers);
        foreach ($answers as $case => $answer) {
            self::assertSame($first, $answer, $case);
        }
    }

    #[Test]
    public function switchedOffAnswers403(): void
    {
        // Control: on, the same request is served.
        self::assertSame(200, $this->context(['table' => 'tt_content', 'field' => 'image', 'uid' => '100'])->getStatusCode());

        $GLOBALS['TYPO3_CONF_VARS']['EXTENSIONS']['t3_cowriter']['image']['enable'] = '0';
        $response                                                                   = $this->context(['table' => 'tt_content', 'field' => 'image', 'uid' => '100']);

        self::assertSame(403, $response->getStatusCode());
        self::assertFalse($this->json($response)['success']);
        self::assertArrayNotHasKey('purpose', $this->json($response));
    }
}
