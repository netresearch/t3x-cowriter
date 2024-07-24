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
     *
     *
     * @param PromptRepository $promptRepository
     * @param ModuleTemplateFactory $moduleTemplateFactory
     * @param ContentElementRepository $contentElementRepository
     * @param PromptRepository $promptRepository
     */
    public function __construct(
        ModuleTemplateFactory $moduleTemplateFactory,
        ContentElementRepository $contentElementRepository,
        PromptRepository $promptRepository
    )
    {
        $this->moduleTemplateFactory = $moduleTemplateFactory;
        $this->contentElementRepository = $contentElementRepository;
        $this->promptRepository = $promptRepository;
    }


    /**
     *  Index action method to render the default view.
     *
     * @return ResponseInterface
     * @throws \Doctrine\DBAL\Exception
     */
    public function indexAction(): ResponseInterface {
        $this->view->assign('contentElements', $this->contentElementRepository->findAll());
        $this->view->assign('prompts', $this->promptRepository->findAll());
        //$this->view->assign('pages', $this->getAllPagesWithTextFieldsElements(['tt_content', 'tx_news_domain_model_news']));
        return $this->moduleResponse();
    }

    /**
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
     *
     *  Retrieves pages with text field elements and organizes the data.
     *  Joins tt_content and pages tables, filters out empty and newline-only bodytext fields.
     *
     * @return array
     * @throws \Doctrine\DBAL\Exception
     */
    private function getAllPagesWithTextFieldsElements(array $tables, array $columns) : array
    {
        //DebuggerUtility::var_dump($tableNames);
        $results = [];
        foreach ($tables as $table) {
            DebuggerUtility::var_dump($table->getTable());
            $queryBuilder = GeneralUtility::makeInstance(ConnectionPool::class)->getQueryBuilderForTable($table->getTable());
            $queryBuilder
                ->select('*')
                ->from($table->getTable())
                ->join(
                    $table->getTable(),
                    'pages',
                    'pages',
                    $queryBuilder->expr()->eq( $table->getTable() .'.pid', $queryBuilder->quoteIdentifier('pages.uid'))
                )
                ->where(
                    $queryBuilder->expr()->eq('pages.uid', $this->request->getQueryParams()['id']),
                    $queryBuilder->expr()->neq('bodytext', $queryBuilder->createNamedParameter('')),
                    $queryBuilder->expr()->isNotNull('bodytext'),
                    $queryBuilder->expr()->neq('bodytext', $queryBuilder->createNamedParameter('\n'))
                );

            $statement = $queryBuilder->executeQuery();
            $tableResults = $statement->fetchAllAssociative();
            $results = array_merge($results, $tableResults);
        }
        //DebuggerUtility::var_dump($results);
        return $results;
    }

    public function getAllSelectedTableNames(array $selectedContentElements = [], array &$tableNames = [])
    {
        $contentElements = [];
        $contentElements = $this->fetchContentElementsByUids($selectedContentElements, $contentElements);
        return $contentElements;
    }

    public function getAllSelectedColumns(array $selectedContentElements = [], array &$columns = [])
    {
        $contentElements = [];
        $contentElements = $this->fetchContentElementsByUids($selectedContentElements, $contentElements);

        foreach ($contentElements as $element) {
            $tableName = $element->getField();
            if (!in_array($tableName, $columns)) {
                $columns[] = $tableName;
            }
        }
        return $columns;
    }

    /**
     *  Handles the start button action. If no prompt or content elements are selected,
     *  redirects to the index action. Otherwise, processes the selected prompt and content elements.
     *
     * @param int $selectedPrompt
     * @param array $selectedContentElements
     * @return ResponseInterface
     * @throws \Doctrine\DBAL\Exception
     */
    public function sendPromptToAiButtonAction(int $selectedPrompt = 0, array $selectedContentElements = []): ResponseInterface
    {
        if ($selectedPrompt === 0 || empty($selectedContentElements)) {
            return $this->indexAction();
        }
        // Process the selected prompt
        $prompt = $this->promptRepository->findByUid($selectedPrompt);

        // Process the selected content elements
        $contentElements = [];
        $contentElements = $this->fetchContentElementsByUids($selectedContentElements, $contentElements);
        DebuggerUtility::var_dump($contentElements);

        return $this->moduleResponse('sendPromptToAiButton');
    }

    public function searchSelectedContentElementsAction(array $selectedContentElements = []): ResponseInterface
    {
        $this->view->assign('pages', $this->getAllPagesWithTextFieldsElements($this->getAllSelectedTableNames($selectedContentElements), $this->getAllSelectedColumns($selectedContentElements)));
        return $this->indexAction();
    }

    /**
     *  Populates the content elements array with elements corresponding to the given UIDs.
     *
     * @param array $selectedContentElements
     * @param array $contentElements
     * @return void
     */
    public function fetchContentElementsByUids(array $selectedContentElements, array $contentElements): array
    {
        foreach ($selectedContentElements as $uid) {
            $contentElements[] = $this->contentElementRepository->findByUid($uid);
        }
        return $contentElements;
    }


}
