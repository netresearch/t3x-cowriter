<?php
namespace Netresearch\T3Cowriter\Domain\Repository;

use phpDocumentor\Reflection\Types\Parent_;
use TYPO3\CMS\Extbase\Persistence\Repository;
use Netresearch\T3Cowriter\Domain\Model\ContentElement;

class ContentElementRepository extends Repository
{
    public function __construct(

    ) {
        parent::__construct();
    }

    public function findAllTables(): string
    {

    }
}