<?php

declare(strict_types=1);

namespace Eekes\Sulu\AiContentBundle\Application\Usage;

use Eekes\Sulu\AiContentBundle\Domain\Model\TokenTotals;
use Symfony\AI\Platform\Result\ResultInterface;
use Symfony\AI\Platform\TokenUsage\TokenUsageAggregation;
use Symfony\AI\Platform\TokenUsage\TokenUsageInterface;

/**
 * Reads the token usage Symfony AI attaches to a result, summed over every request of a run.
 *
 * Providers disagree on what "input tokens" means: Anthropic reports cached tokens next to the
 * input tokens, OpenAI inside them. TokenTotals::$input is always every input token, cached or not,
 * so the cost calculator can treat both the same.
 */
final class TokenUsageReader
{
    /**
     * @return array{tokens: TokenTotals, requests: int}
     */
    public static function fromResult(ResultInterface $result): array
    {
        $usage = $result->getMetadata()->get('token_usage');

        if (!$usage instanceof TokenUsageInterface) {
            return ['tokens' => new TokenTotals(), 'requests' => 1];
        }

        $requests = $usage instanceof TokenUsageAggregation ? max(1, $usage->count()) : 1;

        return ['tokens' => self::toTotals($usage), 'requests' => $requests];
    }

    public static function toTotals(TokenUsageInterface $usage): TokenTotals
    {
        $prompt = $usage->getPromptTokens() ?? 0;
        $cacheRead = $usage->getCacheReadTokens();
        $cacheWrite = $usage->getCacheCreationTokens();
        $reportedSeparately = $cacheRead !== null || $cacheWrite !== null;

        $cacheRead ??= $usage->getCachedTokens() ?? 0;
        $cacheWrite ??= 0;
        $input = $reportedSeparately ? $prompt + $cacheRead + $cacheWrite : $prompt;
        $output = $usage->getCompletionTokens() ?? 0;
        $thinking = $usage->getThinkingTokens() ?? 0;

        return new TokenTotals(
            input: $input,
            output: $output,
            cacheRead: $cacheRead,
            cacheWrite: $cacheWrite,
            thinking: $thinking,
            total: $usage->getTotalTokens() ?? $input + $output,
        );
    }
}
