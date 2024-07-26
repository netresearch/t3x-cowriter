<?php
namespace Netresearch\T3Cowriter\Domain\Repository;

use Netresearch\T3Cowriter\Controller\T3CowriterModuleController;
use phpDocumentor\Reflection\Types\Parent_;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Extbase\Persistence\Generic\Typo3QuerySettings;
use TYPO3\CMS\Extbase\Persistence\Repository;
use Netresearch\T3Cowriter\Domain\Model\ContentElement;

class ContentElementRepository extends Repository
{
    public function __construct(

    ) {
        parent::__construct();
        /** @var QuerySettingsInterface $querySettings */
        $querySettings = GeneralUtility::makeInstance(Typo3QuerySettings::class);
        // Show comments from all pages
        $querySettings->setRespectStoragePage(false);
        $this->setDefaultQuerySettings($querySettings);
    }

    /**
     * Adds where conditions for all fields in the query.
     *
     * @param array $fields
     * @param \TYPO3\CMS\Core\Database\Query\QueryBuilder $queryBuilder
     * @return void
     */
    public function addWhereForAllFields(array $fields, \TYPO3\CMS\Core\Database\Query\QueryBuilder $queryBuilder): void
    {
        foreach ($fields as $field) {
            $queryBuilder->andWhere(
                $queryBuilder->expr()->neq('table.' . $field, $queryBuilder->createNamedParameter('')),
                $queryBuilder->expr()->isNotNull('table.' . $field),
                $queryBuilder->expr()->neq('table.' . $field, $queryBuilder->createNamedParameter('\n'))
            );
        };
    }

    /**
     * Fetches content elements by their UIDs.
     *
     * @param array $selectedContentElements
     * @param T3CowriterModuleController $t3CowriterModuleController
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
     * Retrieves all text field elements.
     *
     * @param array $contentElements
     * @param T3CowriterModuleController $t3CowriterModuleController
     * @return array
     * @throws \Doctrine\DBAL\Exception
     */
    public function getAllTextFieldElements(array $contentElements, int $pageId): array
    {
        $results = [];
        foreach ($contentElements as $contentElement) {
            $fields = explode(',', $contentElement->getField());
            $tableName = $contentElement->getTable();

            if (!isset($results[$tableName])) {
                $results[$tableName] = [
                    'fields' => [],
                    'elements' => []
                ];
            }

            $results[$tableName]['fields'] = array_unique(array_merge($results[$tableName]['fields'], $fields));

            $queryBuilder = GeneralUtility::makeInstance(ConnectionPool::class)->getQueryBuilderForTable($tableName);
            $queryBuilder
                ->select('*')
                ->from($tableName, 'table')
                ->where(
                    $queryBuilder->expr()->eq('table.pid', $queryBuilder->createNamedParameter($pageId))
                );
            $this->addWhereForAllFields($fields, $queryBuilder);
            $statement = $queryBuilder->executeQuery();
            $tableResults = $statement->fetchAllAssociative();

            $results[$tableName]['elements'] = $tableResults;
        }
        return $results;
    }
}
