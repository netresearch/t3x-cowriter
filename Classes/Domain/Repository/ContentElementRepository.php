<?php
namespace Netresearch\T3Cowriter\Domain\Repository;

use phpDocumentor\Reflection\Types\Parent_;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Extbase\Persistence\Repository;
use Netresearch\T3Cowriter\Domain\Model\ContentElement;

class ContentElementRepository extends Repository
{
    public function __construct(

    ) {
        parent::__construct();
    }

    public function findAllContentElements(): array {
        $queryBuilder = GeneralUtility::makeInstance(ConnectionPool::class)->getQueryBuilderForTable('tx_t3cowriter_domain_model_contentelement');
        $queryBuilder
            ->select('*')
            ->from('tx_t3cowriter_domain_model_contentelement');

        $results = $queryBuilder->execute()->fetchAllAssociative();
        return $results;
    }
}
