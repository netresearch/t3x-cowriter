<?php

namespace Netresearch\T3Cowriter\Controller;

use TYPO3\CMS\Backend\Attribute\AsController;
use TYPO3\CMS\Backend\Template\ModuleTemplateFactory;
use Psr\Http\Message\ResponseInterface;
use TYPO3\CMS\Extbase\Mvc\Controller\ActionController;

#[AsController]
final class T3CowriterModuleController extends ActionController
{
    public function __construct(
        protected readonly ModuleTemplateFactory $moduleTemplateFactory
    ) {
    }

    // use Psr\Http\Message\ResponseInterface
    public function indexAction(): ResponseInterface {
        $moduleTemplate = $this->moduleTemplateFactory->create($this->request);
        /*
            $moduleTemplate->assign('aVariable', 'aValue');
        */
        return $moduleTemplate->renderResponse('Index');
    }
}
