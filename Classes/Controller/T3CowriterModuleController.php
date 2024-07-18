<?php

declare(strict_types=1);

namespace Netresearch\T3Cowriter\Controller;

use Netresearch\T3Cowriter\Domain\Repository\PromptRepository;
use ScssPhp\ScssPhp\Formatter\Debug;
use TYPO3\CMS\Backend\Attribute\AsController;
use TYPO3\CMS\Backend\Template\ModuleTemplateFactory;
use Psr\Http\Message\ResponseInterface;
use TYPO3\CMS\Extbase\Mvc\Controller\ActionController;
use Netresearch\T3Cowriter\Domain\Repository\ContentElementRepository;
use Netresearch\T3Cowriter\Domain\Model\ContentElement;
use TYPO3\CMS\Extbase\Persistence\Repository;
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
     * @return ResponseInterface
     */
    public function indexAction(): ResponseInterface {
        $contentElements = $this->contentElementRepository->findAll();
        $prompts = $this->promptRepository->findAll();

        $this->view->assign('contentElements', $contentElements);
        $this->view->assign('prompts', $prompts);
        return $this->moduleResponse();
    }

    /**
     * @return ResponseInterface
     */
    private function moduleResponse(): ResponseInterface
    {
        $moduleTemplate = $this->moduleTemplateFactory->create($this->request);
        $moduleTemplate->setContent($this->view->render());
        return $this->htmlResponse($moduleTemplate->renderContent());
    }
}
