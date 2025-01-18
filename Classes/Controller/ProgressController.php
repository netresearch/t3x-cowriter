<?php

/**
 * This file is part of the package netresearch/t3-cowriter.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace Netresearch\T3Cowriter\Controller;

use Netresearch\T3Cowriter\Service\ProgressService;
use Psr\Http\Message\ResponseInterface;
use TYPO3\CMS\Backend\Attribute\AsController;
use TYPO3\CMS\Core\Http\JsonResponse;
use TYPO3\CMS\Core\Http\ServerRequest;

/**
 * ProgressController.
 *
 * @author  Philipp Altmann <philipp.altmann@netresearch.de>
 * @license Netresearch https://www.netresearch.de
 * @link    https://www.netresearch.de
 */
#[AsController]
class ProgressController
{
    /**
     * Constructor for the CowriterModuleController.
     *
     * Initializes the controller with the necessary dependencies.
     *q
     *
     * @param ProgressService $progressService
     */
    public function __construct(
        private readonly ProgressService $progressService,
    ) {
    }

    /**
     * @param ServerRequest $request
     *
     * @return ResponseInterface
     */
    public function indexAction(ServerRequest $request): ResponseInterface
    {
        $queryParams = $request->getQueryParams();
        $operationID = $queryParams['operationID'];

        if (empty($operationID)) {
            return new JsonResponse([
                'error' => 'Missing operationID parameter',
            ], 400);
        }

        $data = $this->progressService->getProgress($operationID);

        if ($data === null) {
            return new JsonResponse([
                'error' => 'Operation not found',
            ], 404);
        }

        return new JsonResponse([
            'current' => $data[0],
            'total'   => $data[1],
        ]);
    }
}