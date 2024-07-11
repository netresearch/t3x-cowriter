<?php

namespace Netresearch\T3Cowriter\Controller;

use Netresearch\T3Cowriter\Domain\Model\Prompts;
use Netresearch\T3Cowriter\Domain\Repository\PromptsRepository;
use TYPO3\CMS\Extbase\Mvc\Controller\ActionController;

/**
 *
 * Controller for handling Prompts actions
 *
 * @package Netresearch\T3Cowriter
 * @author  Philipp Altmann <philipp.altmann@netresearch.de>
 * @license https://www.gnu.org/licenses/gpl-3.0.de.html GPL-3.0-or-later
 *
 */
class PromptsController extends ActionController
{
    /**
     * @var PromptsRepository
     */
    protected $promptsRepository = null;


    /**
     *
     * Injects the PromptsRepository
     *
     * @param PromptsRepository $promptsRepository
     * @return void
     */
    public function injectPromptsRepository(PromptsRepository $promptsRepository)
    {
        $this->promptsRepository = $promptsRepository;
    }

    /**
     *
     * Lists all prompt models
     *
     * @return void
     */
    public function listAction()
    {
        $models = $this->promptsRepository->findAll();
        $this->view->assign('models', $models);
    }

    /**
     *
     * Displays a single prompt model
     *
     * @param Prompts $model
     * @return void
     */
    public function showAction(Prompts $model)
    {
        $this->view->assign('model', $model);
    }
}