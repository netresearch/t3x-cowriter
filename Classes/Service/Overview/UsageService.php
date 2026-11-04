<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\T3Cowriter\Service\Overview;

use DateTimeImmutable;
use DateTimeZone;
use Netresearch\NrLlm\Domain\Model\Task;
use Netresearch\NrLlm\Domain\Repository\TaskRepository;
use Netresearch\NrLlm\Service\Usage\ExtensionUsageReaderInterface;
use Netresearch\T3Cowriter\Service\CallerSource;
use Throwable;

/**
 * The cowriter's usage in a period, read through nr-llm's public usage reader
 * for the source extension `t3_cowriter`: cost, requests and tokens from the
 * daily aggregate, calls and failures per operation from the telemetry.
 *
 * An editor gets their own figures; only an administrator gets the
 * installation's. Per-user breakdowns stay in nr-llm's Analytics module.
 *
 * @internal
 */
final readonly class UsageService
{
    /** Selectable periods in days. */
    public const PERIODS = [7, 30, 90];

    public const DEFAULT_PERIOD = 30;

    /**
     * Operation names the cowriter passes to nr-llm, each with a label
     * (usage.operation.<name>). A task runs under its own identifier and is
     * shown with the task's name.
     */
    public const OPERATIONS = [
        'chat', 'complete', 'streamComplete', 'customInstruction', 'executeTask', 'taskTools', 'toolCall',
        'altText', 'translate', 'generateImage', 'inlineSuggestion', 'fieldSuggestions',
        'fieldSuggestionsOverview', 'alternativePresentation',
    ];

    private const DAY = 86400;

    public function __construct(
        private ExtensionUsageReaderInterface $reader,
        private ?TaskRepository $taskRepository = null,
    ) {}

    /**
     * The period a request asks for; anything but a selectable one is the default.
     */
    public static function period(mixed $requested): int
    {
        $days = filter_var($requested, FILTER_VALIDATE_INT);

        return in_array($days, self::PERIODS, true) ? $days : self::DEFAULT_PERIOD;
    }

    /**
     * @param int  $beUserUid    the viewer
     * @param bool $installation the viewer may see the installation's figures (an administrator)
     */
    public function forViewer(int $beUserUid, bool $installation, int $days, int $now): UsageOverview
    {
        $to   = self::periodEnd($now);
        $from = self::periodStart($days, $now);
        $user = $installation ? null : $beUserUid;

        $totals   = $this->reader->totals(CallerSource::EXTENSION, $from, $to, $user);
        $coverage = $this->reader->coverage();

        $operations = [];
        foreach ($this->reader->operations(CallerSource::EXTENSION, $from, $to, $user) as $usage) {
            [$labelKey, $argument] = $this->label($usage->operation);
            $operations[]          = new UsageOperation($usage->operation, $labelKey, $argument, $usage->calls, $usage->failedCalls, $usage->lastCallAt);
        }

        return new UsageOverview(
            $days,
            $from->getTimestamp(),
            $now,
            !$installation,
            $totals->estimatedCost,
            $totals->requests,
            $totals->tokens,
            $operations,
            $coverage->enabled,
            $this->completeFrom($from->getTimestamp(), $now, $coverage->retentionDays, $coverage->oldestCallAt),
        );
    }

    /**
     * 00:00 of the period's first day in the installation's time zone: today
     * and the days before it, $days calendar days in all. nr-llm's daily
     * aggregate is stamped with local midnight (strtotime('today')), so cost,
     * requests and tokens and the per-operation counts cover the same days.
     */
    public static function periodStart(int $days, int $now): DateTimeImmutable
    {
        return self::periodEnd($now)->modify('-' . ($days - 1) . ' days')->setTime(0, 0);
    }

    private static function periodEnd(int $now): DateTimeImmutable
    {
        // Not '@' . $now: that is UTC whatever the installation's time zone.
        return (new DateTimeImmutable('now', new DateTimeZone(date_default_timezone_get())))->setTimestamp($now);
    }

    /**
     * From when the per-operation counts are complete, if later than $from.
     *
     * The purge command deletes telemetry older than the retention window,
     * always the oldest rows first. Rows inside the window were never purged,
     * and rows from the oldest stored one on are all still there. So the
     * counts are complete from the earlier of the two.
     */
    private function completeFrom(int $from, int $now, int $retentionDays, ?int $oldestCallAt): ?int
    {
        $retentionStart = $now - $retentionDays * self::DAY;
        $bound          = $oldestCallAt !== null ? min($oldestCallAt, $retentionStart) : $retentionStart;

        return $bound > $from ? $bound : null;
    }

    /**
     * @return array{?string, string} label key (null: show the name) and its argument
     */
    private function label(string $operation): array
    {
        if ($operation === '') {
            return ['unnamed', ''];
        }

        if (in_array($operation, self::OPERATIONS, true)) {
            return [$operation, ''];
        }

        try {
            $task = $this->taskRepository?->findOneByIdentifier($operation);
        } catch (Throwable) {
            $task = null;
        }

        return $task instanceof Task ? ['task', $task->getName()] : [null, $operation];
    }
}
