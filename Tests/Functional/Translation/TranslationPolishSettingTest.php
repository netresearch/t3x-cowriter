<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\T3Cowriter\Tests\Functional\Translation;

use Netresearch\T3Cowriter\Controller\TranslationController;
use Netresearch\T3Cowriter\Service\Translation\TranslationPolishConfiguration;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use ReflectionProperty;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;

/**
 * The translation controller as the container builds it reads the revision
 * settings from the extension configuration (`translation.polish`,
 * `translation.polishInstruction` in ext_conf_template.txt).
 */
#[CoversClass(TranslationPolishConfiguration::class)]
final class TranslationPolishSettingTest extends FunctionalTestCase
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
                'translation' => [
                    'polish'            => '1',
                    'polishInstruction' => 'Address the reader directly.',
                ],
            ],
        ],
    ];

    #[Test]
    public function theControllerReadsTheRevisionSettings(): void
    {
        $configuration = (new ReflectionProperty(TranslationController::class, 'polishConfiguration'))
            ->getValue($this->get(TranslationController::class));

        self::assertInstanceOf(TranslationPolishConfiguration::class, $configuration);
        self::assertTrue($configuration->isEnabled());
        self::assertSame('Address the reader directly.', $configuration->instruction());
    }
}
