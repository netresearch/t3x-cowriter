<?php

/**
 * This file is part of the package netresearch/t3-cowriter.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace Netresearch\T3Cowriter\Backend;

use Doctrine\DBAL\Exception;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * Provides methods to dynamically populate table and field selection lists.
 *
 * @author  Philipp Altmann <philipp.altmann@netresearch.de>
 * @license Netresearch https://www.netresearch.de
 * @link    https://www.netresearch.de
 */
class ItemsProcFunc
{
    /**
     * Populates the available database tables into the selection list.
     *
     * @param array<string, mixed> $config Configuration array
     *
     * @return void
     *
     * @throws Exception
     */
    public function selectTables(array &$config): void
    {
        $connection = GeneralUtility::makeInstance(ConnectionPool::class)
            ->getConnectionForTable('pages');

        $schemaManager = $connection->createSchemaManager();
        $tables        = $schemaManager->listTableNames();

        $config = $this->getListItems(
            $tables,
            $config,
            fn ($table) => $table
        );
    }

    /**
     * Populates the available fields of a selected table into the selection list.
     *
     * @param array<string, mixed> $config Configuration array
     *
     * @return void
     *
     * @throws Exception
     */
    public function selectFields(array &$config): void
    {
        if (!isset($config['row']['table'][0])) {
            return;
        }

        $table = (string) $config['row']['table'][0];

        $connection = GeneralUtility::makeInstance(ConnectionPool::class)
            ->getConnectionForTable($table);

        $schemaManager = $connection->createSchemaManager();
        $columns       = $schemaManager->listTableColumns($table);

        $config = $this->getListItems(
            $columns,
            $config,
            fn ($column) => $column->getName()
        );
    }

    /**
     * Helper method to add items to the selection list.
     *
     * @param array<string, mixed> $items
     * @param array<string, mixed> $config  Configuration array
     * @param callable             $getName
     *
     * @return array<string, mixed>
     */
    private function getListItems(array $items, array $config, callable $getName): array
    {
        foreach ($items as $item) {
            $name              = $getName($item);
            $config['items'][] = [$name, $name];
        }

        return $config;
    }
}
