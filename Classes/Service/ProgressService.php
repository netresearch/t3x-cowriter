<?php

/**
 * This file is part of the package netresearch/t3-cowriter.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace Netresearch\T3Cowriter\Service;

use TYPO3\CMS\Core\Cache\Frontend\FrontendInterface;

class ProgressService
{
    /**
     * @var string
     */
    public const CACHE_IDENTIFIER = 't3_cowriter_progress';

    public function __construct(
        private readonly FrontendInterface $cache,
    ) {
    }

    /**
     * Record the progress of an operation.
     *
     * @param string $operationID
     * @param int    $current
     * @param int    $total
     *
     * @return void
     */
    public function recordProgress(string $operationID, int $current, int $total): void
    {
        $this->cache->set($this->getID($operationID), [$current, $total]);
    }

    /**
     * Get the progress of an operation.
     *
     * @param string $operationID
     *
     * @return int[]|null
     */
    public function getProgress(string $operationID): ?array
    {
        $progress = $this->cache->get($this->getID($operationID));

        return $progress === false ? null : $progress;
    }

    /**
     * Get the cache ID for an operation.
     *
     * @param string $operationID
     *
     * @return string
     */
    private function getID(string $operationID): string
    {
        return sha1(self::CACHE_IDENTIFIER . '_' . $operationID);
    }
}
