<?php

declare(strict_types=1);

namespace Netresearch\T3Cowriter\Controller;

use Doctrine\DBAL\Exception;
use Netresearch\T3Cowriter\Domain\Repository\ContentElementRepository;
use Netresearch\T3Cowriter\Domain\Repository\PromptRepository;
use Netresearch\T3Cowriter\Service\ProgressService;
use OpenAI;
use Psr\Http\Message\ResponseInterface;
use Random\RandomException;
use TYPO3\CMS\Backend\Attribute\AsController;
use TYPO3\CMS\Backend\Template\ModuleTemplateFactory;
use TYPO3\CMS\Core\Configuration\Exception\ExtensionConfigurationExtensionNotConfiguredException;
use TYPO3\CMS\Core\Configuration\Exception\ExtensionConfigurationPathDoesNotExistException;
use TYPO3\CMS\Core\Configuration\ExtensionConfiguration;
use TYPO3\CMS\Extbase\Mvc\Controller\ActionController;


/**
 * Definition of the T3CowriterModuleController class that extends the ActionController class
 *
 * @package Netresearch\T3Cowriter
 * @author  Philipp Altmann <philipp.altmann@netresearch.de>
 * @license https://www.gnu.org/licenses/gpl-3.0.de.html GPL-3.0-or-later
 */
#[AsController]
class T3CowriterModuleController extends ActionController
{
    /**
     * @var ContentElementRepository
     */
    protected readonly ContentElementRepository $contentElementRepository;

    /**
     * @var PromptRepository
     */
    protected readonly PromptRepository $promptRepository;

    /**
     * @var ModuleTemplateFactory
     */
    protected readonly ModuleTemplateFactory $moduleTemplateFactory;

    /**
     * @var ExtensionConfiguration
     */
    protected readonly ExtensionConfiguration $extensionConfiguration;

    protected readonly ProgressService $progressService;


    /**
     * Constructor for the T3CowriterModuleController.
     *
     * Initializes the controller with the necessary dependencies.
     *
     * @param ModuleTemplateFactory $moduleTemplateFactory
     * @param ContentElementRepository $contentElementRepository
     * @param PromptRepository $promptRepository
     * @param ExtensionConfiguration $extensionConfiguration
     * @param ProgressService $progressService
     */
    public function __construct(
        ModuleTemplateFactory    $moduleTemplateFactory,
        ContentElementRepository $contentElementRepository,
        PromptRepository         $promptRepository,
        ExtensionConfiguration   $extensionConfiguration,
        ProgressService          $progressService
    )
    {
        $this->moduleTemplateFactory = $moduleTemplateFactory;
        $this->contentElementRepository = $contentElementRepository;
        $this->promptRepository = $promptRepository;
        $this->extensionConfiguration = $extensionConfiguration;
        $this->progressService = $progressService;
    }


    /**
     *  Handles the default action for the module.
     *
     *  Assigns content elements and prompts to the view and returns the module response.
     *
     * @return ResponseInterface
     * @throws RandomException
     */
    public function indexAction(): ResponseInterface
    {
        $this->view->assign('contentElements', $this->contentElementRepository->findAll());
        $operationID = base64_encode(random_bytes(32));
        $this->view->assign("operationID", $operationID);
        $this->view->assign('prompts', $this->promptRepository->findAll());
        return $this->moduleResponse();
    }

    /**
     *  Generates the module response.
     *
     *  Creates and renders the module template and returns the HTML response.
     *
     * @param string $operationID
     * @param int $selectedPrompt
     * @param array $selectedContentElements
     * @return ResponseInterface
     * @throws Exception
     * @throws ExtensionConfigurationExtensionNotConfiguredException
     * @throws ExtensionConfigurationPathDoesNotExistException
     */
    public function sendPromptToAiButtonAction(string $operationID, int $selectedPrompt = 0, array $selectedContentElements = []): ResponseInterface
    {
        if ($selectedPrompt === 0 || empty($selectedContentElements)) {
            return $this->indexAction();
        }

        $result = $this->contentElementRepository->getAllTextFieldElements(
            $this->contentElementRepository->fetchContentElementsByUid($selectedContentElements),
            (int)$this->request->getQueryParams()['id']
        );

        $this->modifyResultsByAi($operationID, $result, $selectedPrompt);
        return $this->indexAction();
    }

    /**
     *  Handles the action of sending a prompt to the AI.
     *
     *  Validates selected prompt and content elements, processes the text fields, and modifies them via AI.
     *
     * @param array $selectedContentElements
     * @return ResponseInterface
     * @throws Exception
     */
    public function searchSelectedContentElementsAction(array $selectedContentElements = []): ResponseInterface
    {
        $processed = $this->contentElementRepository->createArrayToCountContentElements(
            $this->contentElementRepository->getAllTextFieldElements(
                $this->contentElementRepository->fetchContentElementsByUid($selectedContentElements),
                (int)$this->request->getQueryParams()['id']
            ),
            $this
        );
        $this->view->assign('result', $processed);

        return $this->indexAction();
    }

    /**
     *  Searches for and processes selected content elements and prompts.
     *
     *  Sends the selected content elements and prompts to the AI and processes the results.
     *
     * @param array $element
     * @param int $selectedPrompt
     * @return void
     * @throws ExtensionConfigurationExtensionNotConfiguredException
     * @throws ExtensionConfigurationPathDoesNotExistException
     */
    public function modifyElement(array $element, int $selectedPrompt): void
    {
        foreach ($element as $key => $value) {
            if ($key == 'uid' || $key == 'tablename') {
                continue;
            }
            $prompt = $this->promptRepository->buildFinalPrompt(
                $this->promptRepository->fetchPromptByUid($selectedPrompt),
                $this->extensionConfiguration->get('t3_cowriter', 'basePrompt'),
                $value
            );

            $cowriterApiKey = $this->extensionConfiguration->get('t3_cowriter', 'apiToken');
            $client = OpenAI::client($cowriterApiKey);
            $gptResult = $client->chat()->create([
                'model' => 'gpt-4-turbo',
                'messages' => [
                    ['role' => 'user', 'content' => $prompt],
                ],
            ]);
            $gptResult = $gptResult->choices[0]->message->content;

            $this->contentElementRepository->setAllTextFieldElements($gptResult, $element, $key);
        }
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

    /**
     * Generates the module response.
     *
     * @param string $templateName
     * @return ResponseInterface
     */
    private function moduleResponse(string $templateName = 'index'): ResponseInterface
    {
        $moduleTemplate = $this->moduleTemplateFactory->create($this->request);
        $moduleTemplate->setContent($this->view->render($templateName));
        return $this->htmlResponse($moduleTemplate->renderContent());
    }
}
