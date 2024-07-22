<?php

declare(strict_types=1);

namespace Netresearch\T3Cowriter\Controller;

use Netresearch\T3Cowriter\Domain\Repository\PromptRepository;
use ScssPhp\ScssPhp\Formatter\Debug;
use TYPO3\CMS\Backend\Attribute\AsController;
use TYPO3\CMS\Backend\Template\ModuleTemplateFactory;
use Psr\Http\Message\ResponseInterface;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Extbase\Mvc\Controller\ActionController;
use Netresearch\T3Cowriter\Domain\Repository\ContentElementRepository;
use Netresearch\T3Cowriter\Domain\Model\ContentElement;
use TYPO3\CMS\Extbase\Persistence\Repository;
use TYPO3\CMS\Extbase\Utility\DebuggerUtility;
use function Symfony\Component\DependencyInjection\Loader\Configurator\expr;

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
        $request = $this->request->getQueryParams()['id'];
        $this->view->assign('contentElements', $this->contentElementRepository->findAll());
        $this->view->assign('prompts', $this->promptRepository->findAll());
        $this->view->assign('pages', $this->getAllPagesWithTextFieldsElements($request));
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
    private function getAllPagesWithTextFieldsElements($pageId) : array
    {
        $queryBuilder = GeneralUtility::makeInstance(ConnectionPool::class)->getQueryBuilderForTable('tt_content');
        $queryBuilder
            ->select('tt_content.pid', 'pages.title', 'tt_content.CType')
            ->addSelectLiteral('COUNT(*) AS quantity')
            ->from('tt_content')
            ->join(
                'tt_content',
                'pages',
                'pages',
                $queryBuilder->expr()->eq('tt_content.pid', $queryBuilder->quoteIdentifier('pages.uid'))
            )
            ->where(
                $queryBuilder->expr()->eq('pages.uid', $pageId),
                $queryBuilder->expr()->neq('bodytext', $queryBuilder->createNamedParameter('')),
                $queryBuilder->expr()->isNotNull('bodytext'),
                $queryBuilder->expr()->neq('bodytext', $queryBuilder->createNamedParameter('\n'))
            )
            ->groupBy('tt_content.pid', 'tt_content.CType', 'pages.title');

        $statement = $queryBuilder->executeQuery();
        $results = $statement->fetchAllAssociative();

        // Organize the data into an array by pid and CType
        $organizedResults = [];
        $organizedResults = $this->getOrganizedResults($results, $organizedResults);
        return $organizedResults;
    }

    /**
     * @param array $results
     * @param array $organizedResults
     * @return array
     */
    public function getOrganizedResults(array $results, array $organizedResults): array
    {
        foreach ($results as $row) {
            $pid = $row['pid'];
            $CType = $row['CType'];
            $quantity = $row['quantity'];
            $title = $row['title'];

            if (!isset($organizedResults[$pid])) {
                $organizedResults[$pid] = [
                    'title' => $title,
                    'CType' => []
                ];
            }
            $organizedResults[$pid]['CType'][$CType] = $quantity;
        }
        return $organizedResults;
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
    public function startButtonAction(int $selectedPrompt = 0, array $selectedContentElements = []): ResponseInterface
    {
        if ($selectedPrompt === 0 || empty($selectedContentElements)) {
            return $this->indexAction();
        }
        // Process the selected prompt
        $prompt = $this->promptRepository->findByUid($selectedPrompt);

        // Process the selected content elements
        $contentElements = [];
        $this->fetchContentElementsByUids($selectedContentElements, $contentElements);
        return $this->moduleResponse('startButton');
    }

    /**
     *  Populates the content elements array with elements corresponding to the given UIDs.
     *
     * @param array $selectedContentElements
     * @param array $contentElements
     * @return void
     */
    public function fetchContentElementsByUids(array $selectedContentElements, array $contentElements): void
    {
        foreach ($selectedContentElements as $uid) {
            $contentElements[] = $this->contentElementRepository->findByUid($uid);
        }
    }
}