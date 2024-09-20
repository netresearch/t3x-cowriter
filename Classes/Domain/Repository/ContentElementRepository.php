<?php

/**
 * This file is part of the package netresearch/t3-cowriter.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace Netresearch\T3Cowriter\Domain\Repository;

use Doctrine\DBAL\Exception;
use Netresearch\T3Cowriter\Controller\T3CowriterModuleController;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Database\Query\QueryBuilder;
use TYPO3\CMS\Core\DataHandling\DataHandler;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Extbase\Persistence\Generic\Typo3QuerySettings;
use TYPO3\CMS\Extbase\Persistence\Repository;

/**
 * Model representing a content element with a table and field.
 *
 * @author  Philipp Altmann <philipp.altmann@netresearch.de>
 * @license https://www.gnu.org/licenses/gpl-3.0.de.html GPL-3.0-or-later
 */
class ContentElementRepository extends Repository
{
    public function __construct(
    ) {
        parent::__construct();
        $querySettings = GeneralUtility::makeInstance(Typo3QuerySettings::class);
        $querySettings->setRespectStoragePage(false);
        $this->setDefaultQuerySettings($querySettings);
    }

    /**
     * Fetches content elements by their UIDs.
     *
     * @param array $selectedContentElements
     *
     * @return array
     */
    public function fetchContentElementsByUid(array $selectedContentElements): array
    {
        $contentElements = [];
        foreach ($selectedContentElements as $uid) {
            $contentElements[] = $this->findByUid($uid);
        }

        return $contentElements;
    }

    /**
     * Retrieves all text field elements for the given content elements and page ID.
     *
     * @param array $contentElements
     * @param int   $pageId
     *
     * @return array
     *
     * @throws Exception
     */
    public function getAllTextFieldElements(array $contentElements, int $pageId): array
    {
        $results = [];
        foreach ($contentElements as $contentElement) {
            $fields    = explode(',', $contentElement->getField());
            $tableName = $contentElement->getTable();

            $queryBuilder = GeneralUtility::makeInstance(ConnectionPool::class)->getQueryBuilderForTable($tableName);
            $queryBuilder
                ->select('uid', ...$fields)
                ->from($tableName, 'table')
                ->where(
                    $queryBuilder->expr()->eq('table.pid', $queryBuilder->createNamedParameter($pageId))
                );
            $this->addWhereForAllFields($fields, $queryBuilder);
            $results = array_merge(
                $results,
                $this->addTableNameToResults(
                    $queryBuilder->executeQuery()->fetchAllAssociative(),
                    $tableName
                )
            );
        }

        return $results;
    }

    /**
     * Adds WHERE conditions for all fields to the query builder.
     *
     * @param array        $fields
     * @param QueryBuilder $queryBuilder
     *
     * @return void
     */
    public function addWhereForAllFields(array $fields, QueryBuilder $queryBuilder): void
    {
        foreach ($fields as $field) {
            $queryBuilder->andWhere(
                $queryBuilder->expr()->neq('table.' . $field, $queryBuilder->createNamedParameter('')),
                $queryBuilder->expr()->isNotNull('table.' . $field),
                $queryBuilder->expr()->neq('table.' . $field, $queryBuilder->createNamedParameter('\n'))
            );
        }
    }

    /**
     * Sets all text field elements for the given content and element.
     *
     * @param string $content
     * @param array  $element
     * @param string $currentElementName
     *
     * @return void
     */
    public function setAllTextFieldElements(string $content, array $element, string $currentElementName): void
    {
        $data                                         = [];
        $data[$element['tablename']][$element['uid']] = [
            $currentElementName => $content,
        ];
        $dataHandler = GeneralUtility::makeInstance(DataHandler::class);
        $dataHandler->start($data, []);
        $dataHandler->process_datamap();
    }

    /**
     * Creates an array to count content elements.
     *
     * @param array                      $result
     * @param T3CowriterModuleController $t3CowriterModuleController
     *
     * @return array
     */
    public function createArrayToCountContentElements(array $result, T3CowriterModuleController $t3CowriterModuleController): array
    {
        $processed = [];
        foreach ($result as $item) {
            $tableName = $item['tablename'];
            $table     = $processed[$tableName] ?? [];

            $processed[$tableName] = $this->countContentElements($item, $table);
        }

        return $processed;
    }

    /**
     * Counts content elements in the given item and table.
     *
     * @param mixed $item
     * @param mixed $table
     *
     * @return mixed
     */
    public function countContentElements(mixed $item, mixed $table): mixed
    {
        foreach ($item as $key => $value) {
            if ($key == 'uid') {
                continue;
            }

            if ($key == 'tablename') {
                continue;
            }

            $table[$key] = ($table[$key] ?? 0) + 1;
        }

        return $table;
    }

    /**
     * Adds the table name to the results.
     *
     * @param array $tableResults
     * @param mixed $tableName
     *
     * @return array
     */
    public function addTableNameToResults(array $tableResults, mixed $tableName): array
    {
        foreach (array_keys($tableResults) as $key) {
            $tableResults[$key]['tablename'] = $tableName;
        }

        return $tableResults;
    }
}
