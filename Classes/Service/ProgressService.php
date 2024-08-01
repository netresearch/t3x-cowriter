<?php

declare(strict_types=1);

namespace Netresearch\T3Cowriter\Service;

use TYPO3\CMS\Core\Cache\Frontend\FrontendInterface;

class ProgressService
{
    /**
     * @var string
     */
    const CACHE_IDENTIFIER = 't3_cowriter_progress';

    public function __construct(
        private readonly FrontendInterface $cache
    )
    {
    }

    /**
     * Record the progress of an operation.
     *
     * @param string $operationID
     * @param int $current
     * @param int $total
     * @return void
     */
    function recordProgress(string $operationID, int $current, int $total): void
    {
        $this->cache->set(self::getID($operationID), [$current, $total]);
    }

    /**
     * Get the progress of an operation.
     *
     * @param string $operationID
     * @return int[]|null
     */
    function getProgress(string $operationID): array|null
    {
        $progress = $this->cache->get(self::getID($operationID));

        return $progress === false ? null : $progress;
    }

    /**
     * Get the cache ID for an operation.
     *
     * @param string $operationID
     * @return string
     */
    private static function getID(string $operationID): string
    {
        return sha1(self::CACHE_IDENTIFIER . '_' . $operationID);
    }
}
