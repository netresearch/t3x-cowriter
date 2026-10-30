<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\T3Cowriter\Tests\Unit\Domain\DTO;

use Netresearch\T3Cowriter\Domain\DTO\PresentationCreateRequest;
use Netresearch\T3Cowriter\Domain\DTO\PresentationProposeRequest;
use Netresearch\T3Cowriter\Service\Presentation\Layout;
use Netresearch\T3Cowriter\Service\Presentation\SectionBoundary;
use Netresearch\T3Cowriter\Service\Presentation\SectionPlanner;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(PresentationProposeRequest::class)]
#[CoversClass(PresentationCreateRequest::class)]
final class PresentationRequestTest extends TestCase
{
    private const FINGERPRINT = 'a1b2c3d4e5f60718293a4b5c6d7e8f90a1b2c3d4e5f60718293a4b5c6d7e8f90';

    /**
     * @return iterable<string, array{mixed, int}>
     */
    public static function uids(): iterable
    {
        yield 'integer' => [12, 12];
        yield 'digits' => ['12', 12];
        yield 'missing' => [null, 0];
        yield 'zero' => [0, 0];
        yield 'negative' => [-3, -3];
        yield 'negative as string' => ['-3', 0];
        yield 'digits with text' => ['12abc', 0];
        yield 'float' => [12.5, 0];
        yield 'true' => [true, 0];
        yield 'list' => [[12], 0];
    }

    #[Test]
    #[DataProvider('uids')]
    public function theUidIsAnIntegerOrDigitsOnly(mixed $raw, int $expected): void
    {
        $body = $raw === null ? [] : ['uid' => $raw];

        self::assertSame($expected, PresentationProposeRequest::fromArray($body)->uid);
        self::assertSame($expected, PresentationCreateRequest::fromArray($body)->uid);
        self::assertSame($expected > 0, PresentationProposeRequest::fromArray($body)->isValid());
    }

    /**
     * @param array<string, mixed> $overrides
     *
     * @return array<string, mixed>
     */
    private function body(array $overrides = []): array
    {
        return $overrides + [
            'uid'         => 10,
            'layout'      => 'accordion',
            'fingerprint' => self::FINGERPRINT,
            'sections'    => [
                ['title' => ' <b>Intro</b> ', 'start' => 0],
                ['title' => 'Seat', 'start' => 1, 'consumesHeading' => true],
            ],
        ];
    }

    #[Test]
    public function aCreateRequestCarriesLayoutFingerprintAndPlainTitles(): void
    {
        $dto = PresentationCreateRequest::fromArray($this->body());

        self::assertTrue($dto->isValid());
        self::assertSame(Layout::Accordion, $dto->layout);
        self::assertSame(self::FINGERPRINT, $dto->fingerprint);
        // consumesHeading is false unless sent.
        self::assertEquals([new SectionBoundary('<b>Intro</b>', 0, false), new SectionBoundary('Seat', 1, true)], $dto->sections);
    }

    #[Test]
    public function theMaximumNumberOfSectionsIsAccepted(): void
    {
        $sections = array_map(static fn (int $i): array => ['title' => 'S', 'start' => $i], range(0, SectionPlanner::MAX_SECTIONS - 1));

        $dto = PresentationCreateRequest::fromArray($this->body(['sections' => $sections]));

        self::assertCount(SectionPlanner::MAX_SECTIONS, $dto->sections);
        self::assertTrue($dto->isValid());

        $sections[] = ['title' => 'S', 'start' => SectionPlanner::MAX_SECTIONS];
        self::assertSame([], PresentationCreateRequest::fromArray($this->body(['sections' => $sections]))->sections);
        self::assertTrue((new PresentationCreateRequest(1, Layout::Tabs, self::FINGERPRINT, array_fill(0, 12, new SectionBoundary('x', 0))))->isValid());
        self::assertFalse((new PresentationCreateRequest(1, Layout::Tabs, self::FINGERPRINT, array_fill(0, 13, new SectionBoundary('x', 0))))->isValid());
    }

    #[Test]
    public function oneMalformedSectionDropsTheWholeList(): void
    {
        $dto = PresentationCreateRequest::fromArray($this->body(['sections' => [
            ['title' => 'A', 'start' => 0],
            ['title' => 'B', 'start' => '1'],
            ['title' => 'C', 'start' => 2],
        ]]));

        self::assertSame([], $dto->sections);
        self::assertFalse($dto->isValid());
    }

    /**
     * @return iterable<string, array{array<string, mixed>}>
     */
    public static function invalidBodies(): iterable
    {
        yield 'unknown layout' => [['layout' => 'carousel']];
        yield 'layout not a string' => [['layout' => 1]];
        yield 'fingerprint with a prefix' => [['fingerprint' => 'x' . self::FINGERPRINT]];
        yield 'fingerprint with a suffix' => [['fingerprint' => self::FINGERPRINT . 'x']];
        yield 'fingerprint in upper case' => [['fingerprint' => strtoupper(self::FINGERPRINT)]];
        yield 'fingerprint not a string' => [['fingerprint' => 5]];
        yield 'no sections' => [['sections' => []]];
        yield 'sections as an object' => [['sections' => ['a' => ['title' => 'A', 'start' => 0]]]];
        yield 'a section that is no object' => [['sections' => ['A']]];
        yield 'consumesHeading not a boolean' => [['sections' => [['title' => 'A', 'start' => 0, 'consumesHeading' => 1]]]];
        yield 'title not a string' => [['sections' => [['title' => 5, 'start' => 0]]]];
        yield 'no uid' => [['uid' => 0]];
    }

    /**
     * @param array<string, mixed> $overrides
     */
    #[Test]
    #[DataProvider('invalidBodies')]
    public function malformedCreateRequestsAreInvalid(array $overrides): void
    {
        self::assertFalse(PresentationCreateRequest::fromArray($this->body($overrides))->isValid());
    }
}
