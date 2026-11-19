<?php

declare(strict_types=1);

namespace Eekes\Sulu\AiContentBundle\Tests\Unit\Usage;

use Eekes\Sulu\AiContentBundle\Application\Usage\CostCalculator;
use Eekes\Sulu\AiContentBundle\Domain\Model\TokenTotals;
use PHPUnit\Framework\TestCase;

final class CostCalculatorTest extends TestCase
{
    private CostCalculator $calculator;

    protected function setUp(): void
    {
        $this->calculator = new CostCalculator([
            'claude-sonnet-4-5' => ['input' => 3.0, 'output' => 15.0, 'cache_read' => 0.3, 'cache_write' => 3.75],
            'claude-sonnet-4-5-20250929' => ['input' => 2.0, 'output' => 10.0],
            'gpt-4o' => ['input' => 2.5, 'output' => 10.0],
        ], 'USD');
    }

    public function testInputAndOutputAtTheirPrice(): void
    {
        // 1000 x 3.00 + 500 x 15.00 per million
        self::assertSame(10500, $this->calculator->costMicros('claude-sonnet-4-5', new TokenTotals(input: 1000, output: 500)));
    }

    public function testCachedTokensAtTheCachePrice(): void
    {
        // uncached 1000 x 3.00 + read 8000 x 0.30 + write 1000 x 3.75 + output 100 x 15.00
        $tokens = new TokenTotals(input: 10000, output: 100, cacheRead: 8000, cacheWrite: 1000);

        self::assertSame(3000 + 2400 + 3750 + 1500, $this->calculator->costMicros('claude-sonnet-4-5', $tokens));
    }

    public function testWithoutACachePriceCachedTokensCostTheInputPrice(): void
    {
        self::assertSame(2500, $this->calculator->costMicros('gpt-4o', new TokenTotals(input: 1000, cacheRead: 600)));
    }

    public function testTheLongestMatchingPrefixWins(): void
    {
        self::assertSame(2000, $this->calculator->costMicros('claude-sonnet-4-5-20250929', new TokenTotals(input: 1000)));
        self::assertSame(2500, $this->calculator->costMicros('gpt-4o-2024-08-06', new TokenTotals(input: 1000)));
    }

    public function testAModelWithoutAPriceHasNoCost(): void
    {
        self::assertNull($this->calculator->costMicros('mistral-large', new TokenTotals(input: 1000)));
        self::assertFalse($this->calculator->hasPrice('mistral-large'));
        self::assertSame('USD', $this->calculator->getCurrency());
    }
}
