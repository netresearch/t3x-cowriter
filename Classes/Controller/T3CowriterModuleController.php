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
     *  Constructor to initialize dependencies.
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
        // Process the selected prompt
        $prompt = $this->promptRepository->findByUid($selectedPrompt);

        // Process the selected content elements
        $contentElements = [];
        $contentElements = $this->fetchContentElementsByUids($selectedContentElements, $contentElements);

        return $this->moduleResponse('sendPromptToAiButton');
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
        $this->view->assign('result', $this->getAllTextFieldsElements($this->fetchContentElementsByUids($selectedContentElements)));
        return $this->indexAction();
    }

    /**
     * Retrieves all text field elements.
     *
     * @param array $contentElements
     * @return array
     * @throws \Doctrine\DBAL\Exception
     */
    private function getAllTextFieldsElements(array $contentElements) : array
    {
	    $results = [];
        foreach ($contentElements as $contentElement) {
            $fields = explode(',', $contentElement->getField());
            $tableName = $contentElement->getTable();

            if (!isset($results[$tableName])) {
                $results[$tableName] = [
                    'fields' => [],
                    'elements' => []
                ];
            }

            $results[$tableName]['fields'] = array_unique(array_merge($results[$tableName]['fields'], $fields));

            $queryBuilder = GeneralUtility::makeInstance(ConnectionPool::class)->getQueryBuilderForTable($tableName);
            $queryBuilder
                ->select('*')
                ->from($tableName, 'table')
                ->where(
                    $queryBuilder->expr()->eq('table.pid', $this->request->getQueryParams()['id'])
                );
            $this->addWhereForAllFields($fields, $queryBuilder);
            $statement = $queryBuilder->executeQuery();
            $tableResults = $statement->fetchAllAssociative();

            $results[$tableName]['elements'] = $tableResults;
        }
        return $results;
    }

    /**
     * Fetches content elements by their UIDs.
     *
     * @param array $selectedContentElements
     * @return array
     */
    public function fetchContentElementsByUids(array $selectedContentElements): array
    {
        $contentElements = [];
        foreach ($selectedContentElements as $uid) {
            $contentElements[] = $this->contentElementRepository->findByUid($uid);
        }
        return $contentElements;
    }

    /**
     * Adds where conditions for all fields in the query.
     *
     * @param array $fields
     * @param \TYPO3\CMS\Core\Database\Query\QueryBuilder $queryBuilder
     * @return void
     */
    public function addWhereForAllFields(array $fields, \TYPO3\CMS\Core\Database\Query\QueryBuilder $queryBuilder): void
    {
        foreach ($fields as $field) {
            $queryBuilder->andWhere(
                $queryBuilder->expr()->neq('table.' . $field, $queryBuilder->createNamedParameter('')),
                $queryBuilder->expr()->isNotNull('table.' . $field),
                $queryBuilder->expr()->neq('table.' . $field, $queryBuilder->createNamedParameter('\n'))
            );
        };
    }
}
