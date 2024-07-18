<?php
namespace Netresearch\T3Cowriter\Domain\Repository;

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

    public function findAllTitle(): string
    {

    }
}
