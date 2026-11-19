<?php

declare(strict_types=1);

namespace Eekes\Sulu\AiContentBundle\Application\Usage;

use Eekes\Sulu\AiContentBundle\Domain\Model\TokenTotals;

/**
 * Estimates what a run cost from the prices in the configuration, per million tokens.
 *
 * Prices change and differ per contract, so they are configuration, not something this bundle
 * ships. A model without a price gets null: unknown, which the usage log shows as such.
 */
final class CostCalculator
{
    /**
     * @param array<string, array{input: float, output: float, cache_read?: null|float, cache_write?: null|float}> $prices by model name
     */
    public function __construct(
        private readonly array $prices,
        private readonly string $currency,
    ) {
    }

    public function getCurrency(): string
    {
        return $this->currency;
    }

    public function hasPrice(string $model): bool
    {
        return $this->price($model) !== null;
    }

    /**
     * In millionths of the currency.
     */
    public function costMicros(string $model, TokenTotals $tokens): ?int
    {
        $price = $this->price($model);

        if ($price === null) {
            return null;
        }

        // TokenTotals::$input holds every input token, cached or not, and the output tokens already
        // include reasoning ("thinking") tokens with every provider that reports them.
        $uncached = max(0, $tokens->input - $tokens->cacheRead - $tokens->cacheWrite);
        $cacheRead = $price['cache_read'] ?? $price['input'];
        $cacheWrite = $price['cache_write'] ?? $price['input'];

        // price per million tokens x tokens = millionths of the currency
        $micros = $uncached * $price['input']
            + $tokens->cacheRead * $cacheRead
            + $tokens->cacheWrite * $cacheWrite
            + $tokens->output * $price['output'];

        return (int) round($micros);
    }

    /**
     * The price of a model, matched on its exact name or, failing that, on the longest configured
     * name it starts with - so "claude-sonnet-4-5" covers "claude-sonnet-4-5-20250929".
     *
     * @return null|array{input: float, output: float, cache_read?: null|float, cache_write?: null|float}
     */
    private function price(string $model): ?array
    {
        if (isset($this->prices[$model])) {
            return $this->prices[$model];
        }

        $match = null;

        foreach (array_keys($this->prices) as $name) {
            if (str_starts_with($model, $name) && ($match === null || \strlen($name) > \strlen($match))) {
                $match = $name;
            }
        }

        return $match === null ? null : $this->prices[$match];
    }
}
