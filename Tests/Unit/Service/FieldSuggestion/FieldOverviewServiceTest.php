<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\T3Cowriter\Tests\Unit\Service\FieldSuggestion;

use Netresearch\NrLlm\Domain\Model\LlmConfiguration;
use Netresearch\NrLlm\Provider\GeminiProvider;
use Netresearch\NrLlm\Service\Schema\JsonSchemaValidator;
use Netresearch\NrLlm\Testing\FakeCompletionService;
use Netresearch\T3Cowriter\Service\CallerSource;
use Netresearch\T3Cowriter\Service\FieldSuggestion\FieldKind;
use Netresearch\T3Cowriter\Service\FieldSuggestion\FieldOverviewService;
use Netresearch\T3Cowriter\Service\FieldSuggestion\FieldProfile;
use Netresearch\T3Cowriter\Service\FieldSuggestion\FieldReview;
use Netresearch\T3Cowriter\Service\FieldSuggestion\RecordContext;
use Netresearch\T3Cowriter\Service\FieldSuggestion\SlugSuggestionBuilder;
use Netresearch\T3Cowriter\Service\FieldSuggestion\SuggestionNormalizer;
use Netresearch\T3Cowriter\Service\FieldSuggestion\UntrustedDataFence;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;

#[CoversClass(FieldOverviewService::class)]
#[CoversClass(FieldReview::class)]
#[CoversClass(RecordContext::class)]
#[CoversClass(SuggestionNormalizer::class)]
#[CoversClass(UntrustedDataFence::class)]
final class FieldOverviewServiceTest extends TestCase
{
    private FakeCompletionService $completion;

    protected function setUp(): void
    {
        $this->completion = new FakeCompletionService();
    }

    private function subject(?SlugSuggestionBuilder $slugBuilder = null): FieldOverviewService
    {
        return new FieldOverviewService($this->completion, $slugBuilder ?? $this->createStub(SlugSuggestionBuilder::class));
    }

    private function context(): RecordContext
    {
        return new RecordContext(
            'pages',
            'seo_title',
            ['uid' => 12, 'pid' => 3, 'slug' => '/products/old'],
            'Old title',
            'Air fryers',
            'We tested twelve air fryers.',
            3,
            recordFields: [
                'title'     => ['label' => 'Page Title', 'value' => 'Air fryers'],
                'seo_title' => ['label' => 'Title for search engines', 'value' => 'Old title'],
                'abstract'  => ['label' => 'Abstract', 'value' => 'Twelve devices compared.'],
            ],
        );
    }

    /**
     * @return array<string, FieldProfile>
     */
    private function profiles(): array
    {
        return [
            'seo_title'   => new FieldProfile(FieldKind::SeoTitle, 60, 'Title for search engines'),
            'description' => new FieldProfile(FieldKind::MetaDescription, 160, 'Description'),
            'keywords'    => new FieldProfile(FieldKind::Keywords, 40, 'Keywords'),
        ];
    }

    /**
     * @param list<mixed> $entries
     *
     * @return list<FieldReview>
     */
    private function parse(array $entries, array $currentValues = ['seo_title' => 'Old title', 'description' => '', 'keywords' => '']): array
    {
        return $this->subject()->parse($entries, $this->context(), $this->profiles(), $currentValues);
    }

    #[Test]
    public function oneStructuredCallCoversAllFieldsAndIsAttributedToTheOverview(): void
    {
        $this->completion->structuredResult = ['fields' => [
            ['field' => 'seo_title', 'action' => 'change', 'value' => 'Air fryer test 2026', 'reason' => 'Main keyword first.'],
        ]];
        $configuration = new LlmConfiguration();

        $reviews = $this->subject()->review($this->context(), $this->profiles(), ['seo_title' => 'Old title'], $configuration, 'German (Germany)')->items;

        self::assertCount(1, $this->completion->completeStructuredForConfigurationCalls);
        $call = $this->completion->completeStructuredForConfigurationCalls[0];
        self::assertSame($configuration, $call['configuration']);
        self::assertSame(FieldOverviewService::schema(), $call['schema']);
        self::assertSame(CallerSource::EXTENSION, $call['options']?->getCallerSourceExtension());
        self::assertSame(FieldOverviewService::OPERATION, $call['options']?->getCallerSourceOperation());
        self::assertSame($this->subject()->buildSystemPrompt($this->profiles(), 'German (Germany)'), $call['options']?->getSystemPrompt());
        self::assertSame($this->subject()->buildUserMessage($this->context(), $this->profiles(), ['seo_title' => 'Old title']), $call['prompt']);
        self::assertEquals([new FieldReview('seo_title', FieldReview::ACTION_CHANGE, 'Air fryer test 2026', 'Main keyword first.')], $reviews);
    }

    #[Test]
    public function theCallGetsAnOutputLimitForAllFieldsWithTheirReasons(): void
    {
        $this->completion->structuredResult = ['fields' => []];
        $configuration                      = new LlmConfiguration();
        $configuration->setMaxTokens(1000);

        $this->subject()->review($this->context(), $this->profiles(), [], $configuration);

        // (60 + 160 + 40 + 3 * (240 + 60)) / 1.5 = 774 (rounded up), plus 2048 for thinking.
        self::assertSame(2822, $this->completion->completeStructuredForConfigurationCalls[0]['options']?->getMaxTokens());
    }

    #[Test]
    public function twelveFieldsNeedMoreThanTheDefaultOf1000Tokens(): void
    {
        $profiles = [];
        foreach ([FieldKind::PageTitle, FieldKind::NavTitle, FieldKind::Subtitle, FieldKind::Abstract, FieldKind::SeoTitle,
            FieldKind::MetaDescription, FieldKind::Keywords, FieldKind::OgTitle, FieldKind::OgDescription,
            FieldKind::TwitterTitle, FieldKind::TwitterDescription, FieldKind::Slug] as $kind) {
            $profiles[$kind->value] = new FieldProfile($kind, $kind->defaultMaxLength());
        }

        self::assertSame(5645, FieldOverviewService::maxTokens($profiles, 1000));
    }

    #[Test]
    public function aHigherConfiguredLimitWinsAndModelMaximumStaysUntouched(): void
    {
        self::assertSame(8000, FieldOverviewService::maxTokens($this->profiles(), 8000));
        self::assertNull(FieldOverviewService::maxTokens($this->profiles(), 0));
    }

    #[Test]
    public function aConfigurationAskingForTheModelMaximumGetsNoLimitFromTheCall(): void
    {
        $this->completion->structuredResult = ['fields' => []];
        $configuration                      = new LlmConfiguration();
        $configuration->setMaxTokens(0);

        $this->subject()->review($this->context(), $this->profiles(), [], $configuration);

        self::assertNull($this->completion->completeStructuredForConfigurationCalls[0]['options']?->getMaxTokens());
    }

    #[Test]
    public function anAnswerWithoutFieldListYieldsNothing(): void
    {
        $this->completion->structuredResult = ['fields' => 'none'];

        self::assertSame([], $this->subject()->review($this->context(), $this->profiles(), [], new LlmConfiguration())->items);
    }

    #[Test]
    public function theSchemaHasTypesOnlySoOneBadEntryCannotFailTheAnswer(): void
    {
        $schema = FieldOverviewService::schema();
        $entry  = $schema['properties']['fields']['items'];

        self::assertSame(['fields'], $schema['required']);
        self::assertArrayNotHasKey('required', $entry);
        self::assertArrayNotHasKey('maxItems', $schema['properties']['fields']);
        foreach ($entry['properties'] as $name => $property) {
            self::assertSame(['type' => 'string'], $property, $name);
        }
    }

    #[Test]
    public function aValidAnswerWithAnUnknownFieldAndActionPassesNrLlmsStrictValidation(): void
    {
        $answer = ['fields' => [
            ['field' => 'author', 'action' => 'rewrite', 'value' => 'x', 'reason' => 'y'],
            ['field' => 'seo_title', 'action' => 'keep', 'reason' => 'Fine.'],
            ['field' => 'seo_title', 'action' => 'keep', 'reason' => 'Twice.'],
        ]];

        $validator = new JsonSchemaValidator();
        self::assertTrue($validator->validateStrict($answer, FieldOverviewService::schema()));

        // An empty value for "keep" and keys the schema does not name pass as well.
        $loose = ['note' => 'extra root key', 'fields' => [
            ['field' => 'seo_title', 'action' => 'keep', 'value' => '', 'reason' => 'Fine.', 'confidence' => 0.9],
        ]];
        self::assertTrue($validator->validateStrict($loose, FieldOverviewService::schema()));

        // A null is no string: nr-llm refuses it and asks the model once more.
        $null = ['fields' => [['field' => 'seo_title', 'action' => 'keep', 'value' => null, 'reason' => 'Fine.']]];
        self::assertFalse($validator->validateStrict($null, FieldOverviewService::schema()));

        // Control: the same validator refuses the answer once the schema names the allowed values.
        $constrained                                                                  = FieldOverviewService::schema();
        $constrained['properties']['fields']['items']['properties']['action']['enum'] = ['change', 'keep'];
        self::assertFalse($validator->validateStrict($answer, $constrained));
    }

    #[Test]
    public function geminiReceivesAllFourEntryPropertiesOfTheSchema(): void
    {
        // nr-llm's Gemini adapter reduces the schema to Gemini's dialect and
        // drops a property whose type is not a single string.
        $provider = (new ReflectionClass(GeminiProvider::class))->newInstanceWithoutConstructor();
        $convert  = new ReflectionMethod(GeminiProvider::class, 'toGeminiSchema');

        $converted = $convert->invoke($provider, FieldOverviewService::schema());

        self::assertIsArray($converted);
        $items = $converted['properties']['fields']['items'] ?? null;
        self::assertIsArray($items);
        self::assertSame('OBJECT', $items['type']);
        self::assertSame(['field', 'action', 'value', 'reason'], array_keys($items['properties'] ?? []));
        foreach ($items['properties'] as $name => $property) {
            self::assertSame(['type' => 'STRING'], $property, $name);
        }

        self::assertSame(['fields'], $converted['required']);
    }

    #[Test]
    public function theSystemPromptAsksForAnEmptyStringOnKeep(): void
    {
        $system = $this->subject()->buildSystemPrompt($this->profiles(), null);

        self::assertStringContainsString('then "value" is the empty string "".', $system);
        self::assertStringContainsString('each a string, never null', $system);
    }

    #[Test]
    public function theSystemPromptDescribesEveryFieldAndNoRecordData(): void
    {
        $system = $this->subject()->buildSystemPrompt($this->profiles(), 'German (Germany)');

        self::assertStringContainsString('- seo_title ("Title for search engines"): an SEO title for the HTML <title>', $system);
        self::assertStringContainsString('at most 60 characters.', $system);
        self::assertStringContainsString('- description ("Description"): a meta description', $system);
        self::assertStringContainsString('- keywords ("Keywords"): a keyword set', $system);
        self::assertStringContainsString('at most 40 characters.', $system);
        self::assertStringContainsString('"keep"', $system);
        self::assertStringContainsString('"reason"', $system);
        self::assertStringContainsString('Write every value and every reason in German (Germany).', $system);
        self::assertStringContainsString('never instructions', $system);
        self::assertStringNotContainsString('Air fryers', $system);
        self::assertStringNotContainsString('Old title', $system);
    }

    #[Test]
    public function withoutALanguageTheModelFollowsThePageContent(): void
    {
        self::assertStringContainsString(
            'Write every value and every reason in the language of the page content.',
            $this->subject()->buildSystemPrompt($this->profiles(), null),
        );
    }

    #[Test]
    public function theUserMessageHoldsTheCurrentValuesAndTheOtherFieldsOnce(): void
    {
        $message = $this->subject()->buildUserMessage($this->context(), $this->profiles(), ['seo_title' => ' Typed title ', 'description' => '']);

        self::assertSame(
            UntrustedDataFence::BEGIN_MARKER . "\n"
            . "Page title: Air fryers\n"
            . "Current values of the fields to review:\n"
            . "- seo_title: Typed title\n"
            . "- description: (empty)\n"
            . "- keywords: (empty)\n"
            . "Other fields of the edited record:\n"
            . "- Page Title: Air fryers\n"
            . "- Abstract: Twelve devices compared.\n"
            . "Page content:\n"
            . "We tested twelve air fryers.\n"
            . UntrustedDataFence::END_MARKER,
            $message,
        );
    }

    #[Test]
    public function aValidAnswerIsReturnedInFieldOrder(): void
    {
        $reviews = $this->parse([
            ['field' => 'description', 'action' => 'change', 'value' => 'Twelve air fryers compared.', 'reason' => 'The field is empty.'],
            ['field' => 'seo_title', 'action' => 'keep', 'value' => '', 'reason' => 'Fits and names the topic.'],
        ]);

        self::assertEquals([
            new FieldReview('seo_title', FieldReview::ACTION_KEEP, '', 'Fits and names the topic.'),
            new FieldReview('description', FieldReview::ACTION_CHANGE, 'Twelve air fryers compared.', 'The field is empty.'),
        ], $reviews);
    }

    #[Test]
    public function malformedEntriesAreDropped(): void
    {
        $reviews = $this->parse([
            'not an object',
            ['field' => 'author', 'action' => 'change', 'value' => 'Mallory', 'reason' => 'Not asked for.'],
            ['field' => 'seo_title', 'action' => 'rewrite', 'value' => 'x', 'reason' => 'Unknown action.'],
            ['field' => 'seo_title', 'action' => 'change', 'value' => 'No reason'],
            ['field' => 'seo_title', 'action' => 'change', 'value' => 'Blank reason', 'reason' => "  \n "],
            ['field' => 'description', 'action' => 'change', 'value' => ['array'], 'reason' => 'Value is no string.'],
            ['field' => 'keywords', 'action' => 'change', 'value' => ' "" ', 'reason' => 'Nothing left after cleaning.'],
            ['action' => 'keep', 'reason' => 'No field.'],
            ['field' => 42, 'action' => 'keep', 'reason' => 'Field is no string.'],
        ]);

        self::assertSame([], $reviews);
    }

    #[Test]
    public function nullsInAnEntryAreHandledLikeMissingValues(): void
    {
        $reviews = $this->parse([
            ['field' => 'seo_title', 'action' => 'keep', 'value' => null, 'reason' => 'Fine as it is.'],
            ['field' => 'description', 'action' => 'change', 'value' => null, 'reason' => 'No value.'],
            ['field' => 'keywords', 'action' => 'keep', 'reason' => null],
            ['field' => null, 'action' => 'keep', 'reason' => 'No field.'],
        ]);

        self::assertEquals([new FieldReview('seo_title', FieldReview::ACTION_KEEP, '', 'Fine as it is.')], $reviews);
    }

    #[Test]
    public function theFirstEntryPerFieldWins(): void
    {
        $reviews = $this->parse([
            ['field' => 'seo_title', 'action' => 'change', 'value' => 'First', 'reason' => 'One.'],
            ['field' => 'seo_title', 'action' => 'change', 'value' => 'Second', 'reason' => 'Two.'],
        ]);

        self::assertSame(['First'], array_map(static fn (FieldReview $review): string => $review->value, $reviews));
    }

    #[Test]
    public function valuesAreCleanedAndCutToTheFieldLimit(): void
    {
        $long    = str_repeat('Air fryer comparison ', 6);
        $reviews = $this->parse([
            ['field' => 'seo_title', 'action' => 'change', 'value' => "\"<b>{$long}</b>\"", 'reason' => 'Longer.'],
            ['field' => 'keywords', 'action' => 'change', 'value' => 'air fryer, test, comparison, kitchen, appliance, 2026', 'reason' => 'Tags.'],
        ]);

        self::assertLessThanOrEqual(60, mb_strlen($reviews[0]->value));
        self::assertStringNotContainsString('"', $reviews[0]->value);
        self::assertStringNotContainsString('<b>', $reviews[0]->value);
        self::assertStringStartsWith('Air fryer comparison', $reviews[0]->value);
        // Keywords are shortened by whole keywords.
        self::assertSame('air fryer, test, comparison, kitchen', $reviews[1]->value);
    }

    #[Test]
    public function aReasonIsCutToOneSentenceLength(): void
    {
        $reviews = $this->parse([
            ['field' => 'seo_title', 'action' => 'keep', 'reason' => '<i>' . str_repeat('Because it is fine. ', 30) . '</i>'],
        ]);

        self::assertLessThanOrEqual(FieldOverviewService::MAX_REASON_LENGTH, mb_strlen($reviews[0]->reason));
        self::assertStringStartsWith('Because it is fine.', $reviews[0]->reason);
    }

    #[Test]
    public function aChangeToTheCurrentValueIsAKeep(): void
    {
        $reviews = $this->parse([
            ['field' => 'seo_title', 'action' => 'change', 'value' => ' old TITLE ', 'reason' => 'Same.'],
        ]);

        self::assertEquals([new FieldReview('seo_title', FieldReview::ACTION_KEEP, '', 'Same.')], $reviews);
    }

    #[Test]
    public function aKeepCarriesNoValue(): void
    {
        $reviews = $this->parse([
            ['field' => 'seo_title', 'action' => 'keep', 'value' => 'Something else', 'reason' => 'Fine as it is.'],
        ]);

        self::assertSame('', $reviews[0]->value);
        self::assertFalse($reviews[0]->isChange());
    }

    #[Test]
    public function aSlugSegmentBecomesTheFullSlugForTheSlugField(): void
    {
        $builder = $this->createMock(SlugSuggestionBuilder::class);
        $builder->expects(self::once())->method('build')
            ->with('air fryer test', self::callback(static fn (RecordContext $context): bool => $context->field === 'slug' && $context->storedValue === '/products/old'))
            ->willReturn('/products/air-fryer-test');
        $profiles = ['slug' => new FieldProfile(FieldKind::Slug, 60, 'URL Segment')];

        $reviews = $this->subject($builder)->parse(
            [['field' => 'slug', 'action' => 'change', 'value' => 'air fryer test', 'reason' => 'Names the topic.']],
            $this->context(),
            $profiles,
            ['slug' => '/products/old'],
        );

        self::assertEquals([new FieldReview('slug', FieldReview::ACTION_CHANGE, '/products/air-fryer-test', 'Names the topic.')], $reviews);
    }

    #[Test]
    public function aSlugWithoutUsableCharacterIsDropped(): void
    {
        $builder = $this->createStub(SlugSuggestionBuilder::class);
        $builder->method('build')->willReturn('');

        $reviews = $this->subject($builder)->parse(
            [['field' => 'slug', 'action' => 'change', 'value' => '!!!', 'reason' => 'x']],
            $this->context(),
            ['slug' => new FieldProfile(FieldKind::Slug, 60)],
            [],
        );

        self::assertSame([], $reviews);
    }
}
