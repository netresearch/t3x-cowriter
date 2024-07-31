<?php

namespace Netresearch\T3Cowriter\UserFunctions\FormEngine;

use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * Provides methods to dynamically populate table and field selection lists.
 *
 * @package Netresearch\T3Cowriter
 * @author  Philipp Altmann <philipp.altmann@netresearch.de>
 * @license https://www.gnu.org/licenses/gpl-3.0.de.html GPL-3.0-or-later
 */
class ItemsProcFunc
{
    /**
     * Populates the available database tables into the selection list.
     *
     * @param $params
     * @return void
     * @throws \Doctrine\DBAL\Exception
     */
    public function selectTables(&$params): void
    {
        $connection = GeneralUtility::makeInstance(ConnectionPool::class)->getConnectionForTable('pages');
        $schemaManager = $connection->createSchemaManager();
        $tables = $schemaManager->listTableNames();

        $params = $this->getListItems($tables, $params, function($table) {
            return $table;
        });
    }

    /**
     * Populates the available fields of a selected table into the selection list.
     *
     * @param $params
     * @return void
     * @throws \Doctrine\DBAL\Exception
     */
    public function selectFields(&$params): void
    {
        if (!isset($params['row']['table'][0])) {
            return;
        }
        $table = strval($params['row']['table'][0]);

        $connection = GeneralUtility::makeInstance(ConnectionPool::class)->getConnectionForTable($table);
        $schemaManager = $connection->createSchemaManager();
        $columns = $schemaManager->listTableColumns($table);

        $params = $this->getListItems($columns, $params, function($column) {return $column->getName();});
    }

    /**
     * Helper method to add items to the selection list.
     *
     * @param array $items
     * @param array $params
     * @param callable $getName
     * @return array
     */
    public function getListItems(array $items, array $params, callable $getName): array
    {
        foreach ($items as $item) {
            $name = $getName($item);
            $params['items'][] = [$name, $name];
        }
        return $params;
    }
}