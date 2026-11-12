<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\T3Cowriter\Tests\Support;

use TYPO3\CMS\Core\DataHandling\DataHandler;
use TYPO3\CMS\Core\SingletonInterface;

/**
 * DataHandler hooks shaped like those of an extension that translates a
 * saved element (EXT:autotranslate 3.6): paused from the copy command until
 * its post-processing, it queues every saved record with the fields of the
 * save and handles the queue when the run's operations are done. It only
 * records what it would translate.
 */
final class TranslateOnSaveHook implements SingletonInterface
{
    /**
     * @var list<array{status: string, table: string, uid: int|string, fields: list<string>}>
     */
    public static array $translated = [];

    private bool $suspended = false;

    /**
     * @var list<array{status: string, table: string, uid: int|string, fields: list<string>}>
     */
    private array $queue = [];

    /**
     * @param array<string, mixed> $fields
     */
    public function processDatamap_afterDatabaseOperations(string $status, string $table, int|string $id, array $fields, DataHandler $dataHandler): void
    {
        if ($this->suspended) {
            return;
        }

        $uid           = $dataHandler->substNEWwithIDs[$id] ?? $id;
        $this->queue[] = ['status' => $status, 'table' => $table, 'uid' => is_int($uid) || is_string($uid) ? $uid : 0, 'fields' => array_keys($fields)];
    }

    public function processDatamap_afterAllOperations(DataHandler $dataHandler): void
    {
        if ($this->suspended) {
            return;
        }

        self::$translated = [...self::$translated, ...$this->queue];
        $this->queue      = [];
    }

    public function processCmdmap(string $command, string $table, int|string $id, mixed $value, bool &$commandIsProcessed, DataHandler $dataHandler, mixed $pasteUpdate): void
    {
        if ($command === 'copy') {
            $this->suspended = true;
        }
    }

    public function processCmdmap_postProcess(string $command, string $table, int|string $id, mixed $value, DataHandler $dataHandler, mixed $pasteUpdate, mixed $pasteDatamap): void
    {
        if ($command === 'copy') {
            $this->suspended = false;
        }
    }
}
