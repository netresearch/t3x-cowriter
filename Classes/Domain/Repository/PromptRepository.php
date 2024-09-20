<?php

/**
 * This file is part of the package netresearch/t3-cowriter.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace Netresearch\T3Cowriter\Domain\Repository;

use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Extbase\Persistence\Generic\Typo3QuerySettings;
use TYPO3\CMS\Extbase\Persistence\Repository;

/**
 * Definition of the Prompt class that extends AbstractEntity.
 *
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
        $querySettings->setRespectStoragePage(false);
        $this->setDefaultQuerySettings($querySettings);
    }

    /**
     *  Fetches a prompt by its UID.
     *
     *  Retrieves the prompt associated with the given UID.
     *
     * @param int $selectedPrompt
     *
     * @return string
     */
    public function fetchPromptByUid(int $selectedPrompt): string
    {
        $selectedPrompt = $this->findByUid($selectedPrompt);

        return $selectedPrompt->getPrompt();
    }

    /**
     *  Builds the final prompt by combining base prompt, selected prompt, and content.
     *
     * @param string $prompt
     * @param string $basePrompt
     * @param string $content
     *
     * @return string
     */
    public function buildFinalPrompt(string $prompt, string $basePrompt, string $content): string
    {
        return $basePrompt . ' ' . $prompt . ' TEXT: ' . $content;
    }
}
