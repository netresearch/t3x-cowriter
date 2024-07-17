<?php

declare(strict_types=1);

namespace Netresearch\T3Cowriter\Controller;

use TYPO3\CMS\Backend\Attribute\AsController;
use TYPO3\CMS\Backend\Template\ModuleTemplateFactory;
use Psr\Http\Message\ResponseInterface;
use TYPO3\CMS\Extbase\Mvc\Controller\ActionController;
use Netresearch\T3Cowriter\Domain\Repository\ContentElementRepository;
use Netresearch\T3Cowriter\Domain\Model\ContentElement;

#[AsController]
class T3CowriterModuleController extends ActionController
{
    /**
     * @var ContentElementRepository
     */
    protected readonly ContentElementRepository $contentElementRepository;

    protected readonly ModuleTemplateFactory $moduleTemplateFactory;

    /**
     *
     *
     *
     * @param ModuleTemplateFactory $moduleTemplateFactory
     * @param ContentElementRepository $contentElementRepository
     */
    public function __construct(
        ModuleTemplateFactory $moduleTemplateFactory,
        ContentElementRepository $contentElementRepository
    )
    {
        $this->moduleTemplateFactory = $moduleTemplateFactory;
        $this->contentElementRepository = $contentElementRepository;
    }


    /**
     *  Index action method to render the default view.
     *
     * @return ResponseInterface
     */
    public function indexAction(
        ?ContentElement $contentElement = null
    ): ResponseInterface {
        $contentElement = $this->contentElementRepository;

        return $this->moduleResponse([
            'content' => 'Test2'
        ]);
    }

    /**
     * @return ResponseInterface
     */
    private function moduleResponse(array $variables = []): ResponseInterface
    {
        $moduleTemplate = $this->moduleTemplateFactory->create($this->request);
        $moduleTemplate->assignMultiple($variables);

        return $moduleTemplate->renderResponse('Index');
    }

    public function contentElementAction(): ResponseInterface {
        $this->contentElementRepository;
        $this->view->assign('content', 'Test');
        return $this->moduleResponse();
    }
}
