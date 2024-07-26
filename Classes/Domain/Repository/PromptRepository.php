<?php
namespace Netresearch\T3Cowriter\Domain\Repository;

use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Extbase\Persistence\Generic\Typo3QuerySettings;
use TYPO3\CMS\Extbase\Persistence\Repository;
use TYPO3\CMS\Extbase\Utility\DebuggerUtility;

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
        /** @var QuerySettingsInterface $querySettings */
        $querySettings = GeneralUtility::makeInstance(Typo3QuerySettings::class);
        // Show comments from all pages
        $querySettings->setRespectStoragePage(false);
        $this->setDefaultQuerySettings($querySettings);
    }

    public function fetchPromptByUid(int $selectedPrompt): string
    {
        $selectedPrompt = $this->findByUid($selectedPrompt);
        $prompt = $selectedPrompt->getPrompt();
        return $prompt;
    }

    public function buildFinalPrompt($prompt, $basePrompt) : string
    {
        $finalPrompt = $basePrompt . ' ' . $prompt;
        DebuggerUtility::var_dump($finalPrompt);
        return $finalPrompt;
    }
}
