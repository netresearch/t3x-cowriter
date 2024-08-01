<?php

declare(strict_types=1);

namespace Netresearch\T3Cowriter\Controller;

use Netresearch\T3Cowriter\Service\ProgressService;
use Psr\Http\Message\ResponseInterface;
use TYPO3\CMS\Backend\Attribute\AsController;
use TYPO3\CMS\Core\Http\JsonResponse;
use TYPO3\CMS\Core\Http\ServerRequest;


/**
 * Definition of the T3CowriterModuleController class that extends the ActionController class
 *
 * @package Netresearch\T3Cowriter
 * @author  Philipp Altmann <philipp.altmann@netresearch.de>
 * @license https://www.gnu.org/licenses/gpl-3.0.de.html GPL-3.0-or-later
 */
#[AsController]
class ProgressController
{
    /**
     * Constructor for the T3CowriterModuleController.
     *
     * Initializes the controller with the necessary dependencies.
     *q
     * @param ProgressService $progressService
     */
    public function __construct(
        private readonly ProgressService $progressService
    )
    {
    }

    /**
     * @return ResponseInterface
     */
    public function indexAction(ServerRequest $request): ResponseInterface
    {
        $queryParams = $request->getQueryParams();
        $operationID = $queryParams['operationID'];
        if (empty($operationID)) {
            return new JsonResponse([
                "error" => "Missing operationID parameter",
            ], 400);
        }

        $data = $this->progressService->getProgress($operationID);
        if ($data === null){
            return new JsonResponse([
                "error" => "Operation not found",
            ], 404);
        }

        return new JsonResponse([
            "current" => $data[0],
            "total" => $data[1],
        ]);
    }
}
