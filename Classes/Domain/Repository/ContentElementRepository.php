<?php
namespace Netresearch\T3Cowriter\Domain\Repository;

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
}

