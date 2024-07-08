<?php

namespace netresearch\t3_cowriter\Controller;

use netresearch\t3_cowriter\Domain\Model\Prompts;
use netresearch\t3_cowriter\Domain\Repository\PromptsRepository;
use TYPO3\CMS\Extbase\Mvc\Controller\ActionController;

class PromptsController extends ActionController
{
    /**
     * @var PromptsRepository
     */
    protected $promptsRepository = null;

    public function injectPromptsRepository(PromptsRepository $promptsRepository)
    {
        $this->promptsRepository = $promptsRepository;
    }

    public function listAction()
    {
        $models = $this->promptsRepository->findAll();
        $this->view->assign('models', $models);
    }

    public function showAction(Prompts $model)
    {
        $this->view->assign('model', $model);
    }
}