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
use Netresearch\T3Cowriter\Domain\Model\ContentElement;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Database\Query\QueryBuilder;
use TYPO3\CMS\Core\DataHandling\DataHandler;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Extbase\Persistence\Exception\InvalidQueryException;
use TYPO3\CMS\Extbase\Persistence\Generic\Typo3QuerySettings;
use TYPO3\CMS\Extbase\Persistence\Repository;

/**
 * Model representing a content element with a table and field.
 *
 * @author  Philipp Altmann <philipp.altmann@netresearch.de>
 * @license Netresearch https://www.netresearch.de
 * @link    https://www.netresearch.de
 *
 * @template T of ContentElement
 *
 * @extends Repository<T>
 */
class ContentElementRepository extends Repository
{
    /**
     * Constructor.
     */
    public function __construct()
    {
        parent::__construct();

        $querySettings = GeneralUtility::makeInstance(Typo3QuerySettings::class);
        $querySettings->setRespectStoragePage(false);

        $this->setDefaultQuerySettings($querySettings);
    }

    /**
     * Fetches content elements by their UIDs.
     *
     * @param int[] $contentElementUids
     *
     * @return ContentElement[]
     *
     * @throws InvalidQueryException
     */
    public function fetchContentElementsByUid(array $contentElementUids): array
    {
        $query = $this->createQuery();

        return $query
            ->matching(
                $query->in('uid', $contentElementUids)
            )
            ->execute()
            ->toArray();
    }

    /**
     * Retrieves all text field elements for the given content elements and page ID.
     *
     * @param ContentElement[] $contentElements
     * @param int              $pageId
     *
     * @return array<int, array<string, int|string>>
     *
     * @throws Exception
     */
    public function getAllTextFieldElements(array $contentElements, int $pageId): array
    {
        /** @var ConnectionPool $connectionPool */
        $connectionPool = GeneralUtility::makeInstance(ConnectionPool::class);
        $results        = [[]];

        foreach ($contentElements as $contentElement) {
            $fields    = explode(',', $contentElement->getField());
            $tableName = $contentElement->getTable();

            $queryBuilder = $connectionPool->getQueryBuilderForTable($tableName);
            $queryBuilder
                ->select('uid', ...$fields)
                ->from($tableName, 'table')
                ->where(
                    $queryBuilder->expr()->eq(
                        'table.pid',
                        $queryBuilder->createNamedParameter($pageId)
                    )
                );

            $this->addWhereForAllFields($fields, $queryBuilder);

            /** @var array<int, array<string, int|string>> $tableResults */
            $tableResults = $queryBuilder->executeQuery()->fetchAllAssociative();

            $results[] = $this->addTableNameToResults(
                $tableResults,
                $tableName
            );
        }

        return array_merge(...$results);
    }

    /**
     * Adds WHERE conditions for all fields to the query builder.
     *
     * @param string[]     $fields
     * @param QueryBuilder $queryBuilder
     *
     * @return void
     */
    private function addWhereForAllFields(array $fields, QueryBuilder $queryBuilder): void
    {
        foreach ($fields as $field) {
            $queryBuilder->andWhere(
                $queryBuilder->expr()->neq(
                    'table.' . $field,
                    $queryBuilder->createNamedParameter('')
                ),
                $queryBuilder->expr()->isNotNull('table.' . $field),
                $queryBuilder->expr()->neq(
                    'table.' . $field,
                    $queryBuilder->createNamedParameter('\n')
                )
            );
        }
    }

    /**
     * Sets all text field elements for the given content and element.
     *
     * @param string                    $content
     * @param array<string, int|string> $element
     * @param string                    $currentElementName
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
     * @param array<int, array<string, int|string>> $items
     *
     * @return array<string, array<string, int>>
     */
    public function createArrayToCountContentElements(array $items): array
    {
        $processed = [];
        foreach ($items as $item) {
            $tableName = $item['tablename'];
            $table     = $processed[$tableName] ?? [];

            $processed[$tableName] = $this->countContentElements($item, $table);
        }

        return $processed;
    }

    /**
     * Counts content elements in the given item and table.
     *
     * @param array<string, int|string> $item
     * @param array<string, int>        $table
     *
     * @return array<string, int>
     */
    private function countContentElements(array $item, array $table): mixed
    {
        foreach (array_keys($item) as $key) {
            if ($key === 'uid') {
                continue;
            }

            if ($key === 'tablename') {
                continue;
            }

            $table[$key] = ($table[$key] ?? 0) + 1;
        }

        return $table;
    }

    /**
     * Adds the table name to the results.
     *
     * @param array<int, array<string, int|string>> $tableResults
     * @param string                                $tableName
     *
     * @return array<int, array<string, int|string>>
     */
    private function addTableNameToResults(array $tableResults, string $tableName): array
    {
        foreach (array_keys($tableResults) as $key) {
            $tableResults[$key]['tablename'] = $tableName;
        }

        return $tableResults;
    }
}
