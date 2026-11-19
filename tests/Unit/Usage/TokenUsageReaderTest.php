<?php

declare(strict_types=1);

namespace Eekes\Sulu\AiContentBundle\Tests\Unit\Usage;

use Eekes\Sulu\AiContentBundle\Application\Usage\TokenUsageReader;
use PHPUnit\Framework\TestCase;
use Symfony\AI\Platform\Result\TextResult;
use Symfony\AI\Platform\TokenUsage\TokenUsage;
use Symfony\AI\Platform\TokenUsage\TokenUsageAggregation;

final class TokenUsageReaderTest extends TestCase
{
    public function testAnthropicReportsCachedTokensNextToTheInput(): void
    {
        $totals = TokenUsageReader::toTotals(new TokenUsage(promptTokens: 100, completionTokens: 50, cachedTokens: 900, cacheCreationTokens: 100, cacheReadTokens: 800));

        self::assertSame(1000, $totals->input);
        self::assertSame(800, $totals->cacheRead);
        self::assertSame(100, $totals->cacheWrite);
        self::assertSame(1050, $totals->total);
    }

    public function testOpenAiReportsCachedTokensInsideTheInput(): void
    {
        $totals = TokenUsageReader::toTotals(new TokenUsage(promptTokens: 1000, completionTokens: 50, thinkingTokens: 20, cachedTokens: 600, totalTokens: 1050));

        self::assertSame(1000, $totals->input);
        self::assertSame(600, $totals->cacheRead);
        self::assertSame(0, $totals->cacheWrite);
        self::assertSame(20, $totals->thinking);
        self::assertSame(1050, $totals->total);
    }

    public function testEveryRequestOfARunIsSummed(): void
    {
        $result = new TextResult('Hi');
        $result->getMetadata()->add('token_usage', new TokenUsageAggregation([
            new TokenUsage(promptTokens: 100, completionTokens: 10),
            new TokenUsage(promptTokens: 200, completionTokens: 20),
        ]));

        ['tokens' => $tokens, 'requests' => $requests] = TokenUsageReader::fromResult($result);

        self::assertSame(300, $tokens->input);
        self::assertSame(30, $tokens->output);
        self::assertSame(2, $requests);
    }

    public function testAProviderWithoutUsageCountsOneRequestAndNoTokens(): void
    {
        ['tokens' => $tokens, 'requests' => $requests] = TokenUsageReader::fromResult(new TextResult('Hi'));

        self::assertSame(0, $tokens->total);
        self::assertSame(1, $requests);
    }
}
