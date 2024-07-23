<?php
namespace Netresearch\T3Cowriter\Domain\Repository;

use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Extbase\Persistence\Repository;
use Netresearch\T3Cowriter\Domain\Model\Prompt;

/**
 * Definition of the PromptsRepository class that extends the Repository class
 *
 * @package Netresearch\T3Cowriter
 * @author  Philipp Altmann <philipp.altmann@netresearch.de>
 * @license https://www.gnu.org/licenses/gpl-3.0.de.html GPL-3.0-or-later
 */
class PromptRepository extends Repository
{
    public function __construct(

    ) {
        parent::__construct();
    }

    public function findAllPrompts(): array {
        $queryBuilder = GeneralUtility::makeInstance(ConnectionPool::class)->getQueryBuilderForTable('tx_t3cowriter_domain_model_prompt');
        $queryBuilder
            ->select('*')
            ->from('tx_t3cowriter_domain_model_prompt');

        $results = $queryBuilder->execute()->fetchAllAssociative();
        return $results;
    }
}