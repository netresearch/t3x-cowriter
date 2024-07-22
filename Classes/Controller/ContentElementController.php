<?php

namespace Netresearch\T3Cowriter\Controller;

use Netresearch\T3Cowriter\Domain\Model\ContentElement;
use Netresearch\T3Cowriter\Domain\Repository\ContentElementRepository;
use TYPO3\CMS\Extbase\Mvc\Controller\ActionController;

class ContentElementController extends ActionController
{
    /**
     * @var ContentElementRepository
     */
    protected $contentElementRepository = null;

    public function injectPromptsRepository(ContentElementRepository $contentElementRepository)
    {
        $this->contentElementRepository = $contentElementRepository;
    }

    public function listAction()
    {
        $models = $this->contentElementRepository->findAll();
        $this->view->assign('models', $models);
    }

    public function showAction(ContentElement $model)
    {
        $this->view->assign('model', $model);
    }
}