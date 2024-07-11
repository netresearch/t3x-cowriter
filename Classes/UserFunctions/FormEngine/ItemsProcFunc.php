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
     *
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

        foreach ($tables as $table) {
            $params['items'][] = [$table, $table];
        }
    }

    /**
     *
     * Populates the available fields of a selected table into the selection list.
     * 
     * @param $params
     * @return void
     * @throws \Doctrine\DBAL\Exception
     */
    public function selectFields(&$params): void
    {
        $table = $params['row']['table'];
        if ($table) {
            $table = strval($table[0]);

            $connection = GeneralUtility::makeInstance(ConnectionPool::class)->getConnectionForTable($table);
            $schemaManager = $connection->createSchemaManager();
            $columns = $schemaManager->listTableColumns($table);

            foreach ($columns as $column) {
                $params['items'][] = [$column->getName(), $column->getName()];
            }
        }
    }
}