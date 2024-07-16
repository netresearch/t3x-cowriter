<?php

namespace Netresearch\T3Cowriter\Controller;

use TYPO3\CMS\Backend\Attribute\AsController;
use TYPO3\CMS\Backend\Template\ModuleTemplateFactory;
use Psr\Http\Message\ResponseInterface;
use TYPO3\CMS\Extbase\Mvc\Controller\ActionController;

#[AsController]
final class T3CowriterModuleController extends ActionController
{
    /**
     *  Constructor method for T3CowriterModuleController.
     * 
     * @param ModuleTemplateFactory $moduleTemplateFactory
     */
    public function __construct(
        protected readonly ModuleTemplateFactory $moduleTemplateFactory
    ) {
    }

    /**
     *  Index action method to render the default view.
     *
     * @return ResponseInterface
     */
    public function indexAction(): ResponseInterface {
        $moduleTemplate = $this->moduleTemplateFactory->create($this->request);
        return $moduleTemplate->renderResponse('Index');
    }
}
