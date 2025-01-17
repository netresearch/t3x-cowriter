<?php

/**
 * This file is part of the package netresearch/t3-cowriter.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace Netresearch\T3Cowriter\Domain\Repository;

use Netresearch\T3Cowriter\Domain\Model\Prompt;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Extbase\Persistence\Generic\QuerySettingsInterface;
use TYPO3\CMS\Extbase\Persistence\Generic\Typo3QuerySettings;
use TYPO3\CMS\Extbase\Persistence\Repository;

/**
 * Definition of the Prompt class that extends AbstractEntity.
 *
 * @author  Philipp Altmann <philipp.altmann@netresearch.de>
 * @license Netresearch https://www.netresearch.de
 * @link    https://www.netresearch.de
 *
 * @template T of Prompt
 *
 * @extends Repository<T>
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
     * Fetches a prompt by its UID.
     *
     * @param int $promptUid
     *
     * @return string
     */
    public function fetchPromptByUid(int $promptUid): string
    {
        $selectedPrompt = $this->findByUid($promptUid);

        if ($selectedPrompt instanceof Prompt) {
            return $selectedPrompt->getPrompt();
        }

        return '';
    }

    /**
     * Builds the final prompt by combining base prompt, selected prompt, and content.
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
