<?php

namespace Netresearch\T3Cowriter\UserFunctions\FormEngine;

use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * A user function used in select_2
 */
class ItemsProcFunc
{
    /**
     *
     * @param array $params
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
     * @param array $params
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