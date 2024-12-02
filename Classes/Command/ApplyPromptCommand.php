<?php

/**
 * This file is part of the package netresearch/t3-cowriter.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace Netresearch\T3Cowriter\Controller;

use Symfony\Component\Console\Command\Command;

/**
 * Class ApplyPromptCommand.
 *
 *  Uses choosen prompts on a chosen page tree.
 *
 * @author  Thomas Schöne <thomas.schoene@netresearch.de>
 * @license Netresearch https://www.netresearch.de
 * @link    https://www.netresearch.de
 */
class ApplyPromptCommand extends Command
{

    public function __construct(LoggerInterface $logger)
    {
        $this->logger = $logger;

        $this->databaseService       = GeneralUtility::makeInstance(DatabaseService::class);
        $this->databaseDeleteService = GeneralUtility::makeInstance(DatabaseDeleteService::class);

        parent::__construct();
    }

}
