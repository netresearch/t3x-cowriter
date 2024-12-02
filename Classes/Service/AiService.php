<?php

use TYPO3\CMS\Core\Configuration\Exception\ExtensionConfigurationExtensionNotConfiguredException;
use TYPO3\CMS\Core\Configuration\Exception\ExtensionConfigurationPathDoesNotExistException;

class AiService
{
    public function __construct()
    {
        // Your constructor code here
    }

    /**
     *  Modifies a content element using AI.
     *
     * @param string $operationID
     * @param array $result
     * @param int $selectedPrompt
     * @return void
     * @throws ExtensionConfigurationExtensionNotConfiguredException
     * @throws ExtensionConfigurationPathDoesNotExistException
     */
    public function modifyResultsByAi(string $operationID, array $result, int $selectedPrompt): void
    {
        $progress = 0;

        foreach ($result as $element) {
            $this->modifyElement($element, $selectedPrompt);
            $progress++;

            $this->progressService->recordProgress($operationID, $progress, count($result));
        }
    }
}