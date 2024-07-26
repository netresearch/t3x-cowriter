<?php

declare(strict_types=1);

namespace Netresearch\T3Cowriter\Controller;

use Netresearch\T3Cowriter\Domain\Repository\PromptRepository;
use TYPO3\CMS\Backend\Attribute\AsController;
use TYPO3\CMS\Backend\Template\ModuleTemplateFactory;
use Psr\Http\Message\ResponseInterface;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Extbase\Mvc\Controller\ActionController;
use Netresearch\T3Cowriter\Domain\Repository\ContentElementRepository;
use TYPO3\CMS\Extbase\Utility\DebuggerUtility;
use TYPO3\CMS\Core\Configuration\ExtensionConfiguration;

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


    /**
     *  Constructor to initialize dependencies.
     *
     * @param PromptRepository $promptRepository
     * @param ModuleTemplateFactory $moduleTemplateFactory
     * @param ContentElementRepository $contentElementRepository
     */
    public function __construct(
        ModuleTemplateFactory $moduleTemplateFactory,
        ContentElementRepository $contentElementRepository,
        PromptRepository $promptRepository,
        ExtensionConfiguration $extensionConfiguration
    )
    {
        $this->moduleTemplateFactory = $moduleTemplateFactory;
        $this->contentElementRepository = $contentElementRepository;
        $this->promptRepository = $promptRepository;
        $this->extensionConfiguration = $extensionConfiguration;
    }


    /**
     * Renders the index action view.
     *
     * @return ResponseInterface
     */
    public function indexAction(): ResponseInterface {
        $this->view->assign('contentElements', $this->contentElementRepository->findAll());
        $this->view->assign('prompts', $this->promptRepository->findAll());
        return $this->moduleResponse();
    }

    /**
     * Generates a module response with the specified template.
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

    /**
     * Handles the action to send a prompt to AI.
     *
     * @param int $selectedPrompt
     * @param array $selectedContentElements
     * @return ResponseInterface
     */
    public function sendPromptToAiButtonAction(int $selectedPrompt = 0, array $selectedContentElements = []): ResponseInterface
    {
        if ($selectedPrompt === 0 || empty($selectedContentElements)) {
            return $this->indexAction();
        }

        $cowriterBasePrompt = $this->extensionConfiguration->get('t3_cowriter', 'basePrompt');
        $prompt = $this->promptRepository->buildFinalPrompt($this->promptRepository->fetchPromptByUid($selectedPrompt), $cowriterBasePrompt);

        $pageId = (int)$this->request->getQueryParams()['id'];
        $contentElements = $this->contentElementRepository->fetchContentElementsByUid($selectedContentElements);
        $text = $this->view->assign('result', $this->contentElementRepository->getAllTextFieldElements($contentElements, $pageId));
        DebuggerUtility::var_dump($this->view->assign('result', $this->contentElementRepository->getAllTextFieldElements($contentElements, $pageId)));

        /*
        $cowriterApiKey = $this->extensionConfiguration->get('t3_cowriter', 'apiToken');

        $client = \OpenAI::client($cowriterApiKey);

        $gptResult = $client->chat()->create([
            'model' => 'gpt-4-turbo',
            'messages' => [
                ['role' => 'user', 'content' => $prompt],
            ],
        ]);
        DebuggerUtility::var_dump($gptResult->choices[0]->message->content);
*/

        return $this->indexAction();
    }

    /**
     * Searches for the selected content elements.
     *
     * @param array $selectedContentElements
     * @return ResponseInterface
     * @throws \Doctrine\DBAL\Exception
     */
    public function searchSelectedContentElementsAction(array $selectedContentElements = []): ResponseInterface
    {
        $pageId = (int)$this->request->getQueryParams()['id'];

        $contentElements = $this->contentElementRepository->fetchContentElementsByUid($selectedContentElements);
        $this->view->assign('result', $this->contentElementRepository->getAllTextFieldElements($contentElements, $pageId));
        return $this->indexAction();
    }
}
