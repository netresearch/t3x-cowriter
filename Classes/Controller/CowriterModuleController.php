<?php

/**
 * This file is part of the package netresearch/t3-cowriter.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace Netresearch\T3Cowriter\Controller;

use Doctrine\DBAL\Exception;
use Netresearch\T3Cowriter\Domain\Model\ContentElement;
use Netresearch\T3Cowriter\Domain\Model\Prompt;
use Netresearch\T3Cowriter\Domain\Repository\ContentElementRepository;
use Netresearch\T3Cowriter\Domain\Repository\PromptRepository;
use Netresearch\T3Cowriter\Service\ProgressService;
use OpenAI;
use OpenAI\Exceptions\ErrorException;
use Psr\Http\Message\ResponseInterface;
use TYPO3\CMS\Backend\Attribute\AsController;
use TYPO3\CMS\Backend\Template\ModuleTemplate;
use TYPO3\CMS\Backend\Template\ModuleTemplateFactory;
use TYPO3\CMS\Core\Configuration\Exception\ExtensionConfigurationExtensionNotConfiguredException;
use TYPO3\CMS\Core\Configuration\Exception\ExtensionConfigurationPathDoesNotExistException;
use TYPO3\CMS\Core\Configuration\ExtensionConfiguration;
use TYPO3\CMS\Core\Page\PageRenderer;
use TYPO3\CMS\Core\Type\ContextualFeedbackSeverity;
use TYPO3\CMS\Extbase\Http\ForwardResponse;
use TYPO3\CMS\Extbase\Mvc\Controller\ActionController;
use TYPO3\CMS\Extbase\Persistence\Exception\InvalidQueryException;

use function count;

/**
 * CowriterModuleController.
 *
 * @author  Philipp Altmann <philipp.altmann@netresearch.de>
 * @license Netresearch https://www.netresearch.de
 * @link    https://www.netresearch.de
 */
#[AsController]
class CowriterModuleController extends ActionController
{
    /**
     * @var PageRenderer
     */
    protected readonly PageRenderer $pageRenderer;

    /**
     * @var ModuleTemplate
     */
    protected ModuleTemplate $moduleTemplate;

    /**
     * @var ContentElementRepository<ContentElement>
     */
    protected readonly ContentElementRepository $contentElementRepository;

    /**
     * @var PromptRepository<Prompt>
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
     * @var ProgressService
     */
    protected readonly ProgressService $progressService;

    /**
     * Constructor.
     *
     * @param ModuleTemplateFactory                    $moduleTemplateFactory
     * @param PageRenderer                             $pageRenderer
     * @param ContentElementRepository<ContentElement> $contentElementRepository
     * @param PromptRepository<Prompt>                 $promptRepository
     * @param ExtensionConfiguration                   $extensionConfiguration
     * @param ProgressService                          $progressService
     */
    public function __construct(
        ModuleTemplateFactory $moduleTemplateFactory,
        PageRenderer $pageRenderer,
        ContentElementRepository $contentElementRepository,
        PromptRepository $promptRepository,
        ExtensionConfiguration $extensionConfiguration,
        ProgressService $progressService,
    ) {
        $this->moduleTemplateFactory = $moduleTemplateFactory;

        $this->pageRenderer = $pageRenderer;
        $this->pageRenderer->loadJavaScriptModule('@netresearch/t3-cowriter/progress_bar.js');

        $this->contentElementRepository = $contentElementRepository;
        $this->promptRepository         = $promptRepository;
        $this->extensionConfiguration   = $extensionConfiguration;
        $this->progressService          = $progressService;
    }

    /**
     * Initialize action.
     *
     * @return void
     */
    protected function initializeAction(): void
    {
        parent::initializeAction();

        $this->moduleTemplate = $this->getModuleTemplate();
    }

    /**
     * Returns the module template instance.
     *
     * @return ModuleTemplate
     */
    private function getModuleTemplate(): ModuleTemplate
    {
        return $this->moduleTemplateFactory->create($this->request);
    }

    /**
     * Handles the default action for the module.
     *
     * Assigns content elements and prompts to the view and returns the module response.
     *
     * @return ResponseInterface
     */
    public function indexAction(): ResponseInterface
    {
        $operationID = base64_encode(random_bytes(32));

        $this->view->assign('contentElements', $this->contentElementRepository->findAll());
        $this->view->assign('operationID', $operationID);
        $this->view->assign('prompts', $this->promptRepository->findAll());

        if ($this->request->hasArgument('processed')) {
            $this->view->assign(
                'processed',
                $this->request->getArgument('processed')
            );
        }

        return $this->moduleResponse();
    }

    /**
     * Generates the module response.
     *
     * Creates and renders the module template and returns the HTML response.
     *
     * @param string   $operationID
     * @param int      $selectedPrompt
     * @param string[] $selectedContentElements
     *
     * @return ResponseInterface
     *
     * @throws Exception
     * @throws ExtensionConfigurationExtensionNotConfiguredException
     * @throws ExtensionConfigurationPathDoesNotExistException
     * @throws InvalidQueryException
     */
    public function sendPromptToAiButtonAction(
        string $operationID,
        int $selectedPrompt = 0,
        array $selectedContentElements = [],
    ): ResponseInterface {
        if (($selectedPrompt === 0)
            || ($selectedContentElements === [])
            || !$this->request->hasArgument('id')
        ) {
            // TODO Add some flash message to backend to inform user
            return (new ForwardResponse('index'))
                ->withControllerName('CowriterModule')
                ->withExtensionName('T3Cowriter');
        }

        // Convert string[] to int[]
        $selectedContentElements = array_map('\intval', $selectedContentElements);

        $contentElements = $this->contentElementRepository
            ->fetchContentElementsByUid($selectedContentElements);

        $textFieldElements = $this->contentElementRepository
            ->getAllTextFieldElements(
                $contentElements,
                $this->getPageId()
            );

        try {
            $this->modifyResultsByAi(
                $operationID,
                $textFieldElements,
                $selectedPrompt
            );
        } catch (ErrorException $exception) {
            $this->moduleTemplate->addFlashMessage(
                $exception->getMessage(),
                'OpenAI',
                ContextualFeedbackSeverity::ERROR
            );
        }

        return (new ForwardResponse('index'))
            ->withControllerName('CowriterModule')
            ->withExtensionName('T3Cowriter');
    }

    /**
     * Handles the action of sending a prompt to the AI.
     *
     * Validates selected prompt and content elements, processes the text fields, and modifies them via AI.
     *
     * @param string[] $selectedContentElements
     *
     * @return ResponseInterface
     *
     * @throws InvalidQueryException
     * @throws Exception
     */
    public function searchSelectedContentElementsAction(array $selectedContentElements = []): ResponseInterface
    {
        if ($selectedContentElements === []) {
            // TODO Add some flash message to backend to inform user
            return (new ForwardResponse('index'))
                ->withControllerName('CowriterModule')
                ->withExtensionName('T3Cowriter');
        }

        // Convert string[] to int[]
        $selectedContentElements = array_map('\intval', $selectedContentElements);

        $contentElements = $this->contentElementRepository
            ->fetchContentElementsByUid($selectedContentElements);

        $textFieldElements = $this->contentElementRepository
            ->getAllTextFieldElements(
                $contentElements,
                $this->getPageId()
            );

        $processed = $this->contentElementRepository
            ->createArrayToCountContentElements($textFieldElements);

        return (new ForwardResponse('index'))
            ->withControllerName('CowriterModule')
            ->withExtensionName('T3Cowriter')
            ->withArguments([
                'processed' => $processed,
            ]);
    }

    /**
     * Returns the page ID extracted from the given request object.
     *
     * @return int
     */
    private function getPageId(): int
    {
        return (int) ($this->request->getParsedBody()['id'] ?? $this->request->getQueryParams()['id'] ?? -1);
    }

    /**
     * Modifies a content element using AI.
     *
     * @param string                                $operationID
     * @param array<int, array<string, int|string>> $result
     * @param int                                   $selectedPrompt
     *
     * @return void
     *
     * @throws ErrorException
     * @throws ExtensionConfigurationExtensionNotConfiguredException
     * @throws ExtensionConfigurationPathDoesNotExistException
     */
    private function modifyResultsByAi(string $operationID, array $result, int $selectedPrompt): void
    {
        $progress = 0;

        foreach ($result as $element) {
            $this->modifyElement($element, $selectedPrompt);

            ++$progress;

            $this->progressService->recordProgress($operationID, $progress, count($result));
        }
    }

    /**
     * Searches for and processes selected content elements and prompts.
     *
     * Sends the selected content elements and prompts to the AI and processes the results.
     *
     * @param array<string, int|string> $element
     * @param int                       $selectedPrompt
     *
     * @return void
     *
     * @throws ExtensionConfigurationExtensionNotConfiguredException
     * @throws ExtensionConfigurationPathDoesNotExistException
     * @throws ErrorException
     */
    private function modifyElement(array $element, int $selectedPrompt): void
    {
        foreach ($element as $key => $value) {
            if ($key === 'uid') {
                continue;
            }

            if ($key === 'tablename') {
                continue;
            }

            $prompt = $this->promptRepository->buildFinalPrompt(
                $this->promptRepository->fetchPromptByUid($selectedPrompt),
                $this->extensionConfiguration->get('t3_cowriter', 'basePrompt'),
                $value
            );

            $cowriterApiKey = $this->extensionConfiguration->get('t3_cowriter', 'apiToken');
            $cowriterModel  = $this->extensionConfiguration->get('t3_cowriter', 'preferredModel');

            $client = OpenAI::client($cowriterApiKey);

            $gptResult = $client->chat()->create([
                'model'    => $cowriterModel,
                'messages' => [
                    ['role'       => 'user',
                        'content' => $prompt,
                    ],
                ],
            ]);

            $gptResult = $gptResult->choices[0]->message->content;

            $this->contentElementRepository
                ->setAllTextFieldElements(
                    $gptResult,
                    $element,
                    $key
                );
        }
    }

    /**
     * Generates the module response.
     *
     * @return ResponseInterface
     */
    private function moduleResponse(): ResponseInterface
    {
        $this->moduleTemplate->assign('content', $this->view->render());

        return $this->moduleTemplate->renderResponse('Backend/BackendModule.html');
    }
}
