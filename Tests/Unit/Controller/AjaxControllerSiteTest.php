<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\T3Cowriter\Tests\Unit\Controller;

use Generator;
use Netresearch\NrLlm\Domain\Model\LlmConfiguration;
use Netresearch\NrLlm\Provider\Middleware\ProviderCallContext;
use Netresearch\NrLlm\Service\LlmServiceManagerInterface;
use Netresearch\NrLlm\Testing\FakeCompletionService;
use Netresearch\T3Cowriter\Controller\AjaxController;
use Netresearch\T3Cowriter\Service\FieldSuggestion\RecordFinder;
use Netresearch\T3Cowriter\Service\RecordSite;
use Netresearch\T3Cowriter\Tests\Support\RecordingEventStream;
use Netresearch\T3Cowriter\Tests\Support\TaskRouteControllerTrait;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ServerRequestInterface;
use TYPO3\CMS\Core\Http\ServerRequest;
use TYPO3\CMS\Core\Site\Entity\Site;
use TYPO3\CMS\Core\Site\SiteFinder;

/**
 * The streamed answer and the versions of a task about a record state the
 * record's site, so its term lists apply (nr-llm ADR-238, ADR-244).
 */
#[CoversClass(AjaxController::class)]
final class AjaxControllerSiteTest extends TestCase
{
    use TaskRouteControllerTrait;

    #[Test]
    public function theStreamedAnswerStatesTheSite(): void
    {
        $metadata = null;
        $llm      = $this->createStub(LlmServiceManagerInterface::class);
        $llm->method('streamChatWithConfiguration')->willReturnCallback(
            static function (array $messages, LlmConfiguration $configuration, array $options, array $callMetadata) use (&$metadata): Generator {
                $metadata = $callMetadata;

                yield 'Short.';
            },
        );

        $this->taskRouteController($llm, eventStream: new RecordingEventStream(), recordSite: $this->recordSite())
            ->executeTaskStreamAction($this->request(1));

        self::assertSame('shop', $metadata[ProviderCallContext::METADATA_SITE] ?? null);
    }

    #[Test]
    public function theVersionsStateTheSite(): void
    {
        $completion                   = new FakeCompletionService();
        $completion->structuredResult = ['variants' => ['<p>One</p>', '<p>Two</p>']];

        $this->taskRouteController($this->createStub(LlmServiceManagerInterface::class), completionService: $completion, recordSite: $this->recordSite())
            ->executeTaskAction($this->request(2));

        self::assertCount(1, $completion->completeStructuredForConfigurationCalls);
        self::assertSame('shop', $completion->completeStructuredForConfigurationCalls[0]['options']?->getSite());
    }

    private function recordSite(): RecordSite
    {
        $records = $this->createStub(RecordFinder::class);
        $records->method('findRecord')->willReturn(['uid' => 42, 'pid' => 10]);
        $sites = $this->createStub(SiteFinder::class);
        $sites->method('getSiteByPageId')->willReturn(new Site('shop', 1, []));

        return new RecordSite($records, $sites);
    }

    private function request(int $variants): ServerRequestInterface
    {
        return (new ServerRequest('https://example.com/typo3/ajax/cowriter/task-execute', 'POST'))
            ->withParsedBody([
                'taskUid'       => 1,
                'context'       => '<p>Some text</p>',
                'contextType'   => 'selection',
                'instruction'   => 'Shorten it',
                'variants'      => $variants,
                'recordContext' => ['table' => 'tt_content', 'uid' => 42, 'field' => 'bodytext'],
            ]);
    }
}
