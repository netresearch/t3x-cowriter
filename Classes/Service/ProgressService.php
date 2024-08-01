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

    function recordProgress(string $operationID, int $current, int $total): void
    {
        $this->cache->set(self::getID($operationID), [$current, $total]);
    }

    /**
     * @param string $operationID
     * @return int[]|null
     */
    function getProgress(string $operationID): array|null
    {
        $progress = $this->cache->get(self::getID($operationID));

        return $progress === false ? null : $progress;
    }

    private static function getID(string $operationID): string
    {
        return sha1(self::CACHE_IDENTIFIER . '_' . $operationID);
    }
}
