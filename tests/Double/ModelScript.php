<?php

declare(strict_types=1);

namespace Eekes\Sulu\AiContentBundle\Tests\Double;

use Symfony\AI\Platform\Model;
use Symfony\AI\Platform\PlatformInterface;
use Symfony\AI\Platform\Result\ResultInterface;
use Symfony\AI\Platform\Result\TextResult;
use Symfony\AI\Platform\Result\ToolCall;
use Symfony\AI\Platform\Result\ToolCallResult;
use Symfony\AI\Platform\Test\MockPlatformFactory;
use Symfony\AI\Platform\TokenUsage\TokenUsage;

/**
 * Plays the model: answers every request with the next scripted result, in order, and records
 * what it was asked. Symfony AI's own mock platform does the rest, so the bundle talks to it
 * exactly as it talks to a real provider.
 */
final class ModelScript
{
    /**
     * @var list<ResultInterface>
     */
    private array $results = [];

    /**
     * @var list<array{model: string, input: array<mixed>|object|string, options: array<string, mixed>}>
     */
    public array $calls = [];

    public static function createPlatform(self $script): PlatformInterface
    {
        return MockPlatformFactory::createPlatform($script->answer(...));
    }

    public function reply(ResultInterface ...$results): void
    {
        array_push($this->results, ...$results);
    }

    public function reset(): void
    {
        $this->results = [];
        $this->calls = [];
    }

    /**
     * @param array<mixed>|object|string $input
     * @param array<string, mixed> $options
     */
    public function answer(Model $model, array|string|object $input, array $options): ResultInterface
    {
        $this->calls[] = ['model' => $model->getName(), 'input' => $input, 'options' => $options];

        return array_shift($this->results) ?? throw new \RuntimeException('The model script has no answer left.');
    }

    /**
     * A text answer with the usage an Anthropic model reports.
     */
    public static function text(string $text, int $input = 1000, int $output = 200, int $cacheRead = 0, int $cacheWrite = 0): TextResult
    {
        $result = new TextResult($text);
        $result->getMetadata()->add('token_usage', new TokenUsage(
            promptTokens: $input,
            completionTokens: $output,
            cachedTokens: $cacheRead + $cacheWrite,
            cacheCreationTokens: $cacheWrite,
            cacheReadTokens: $cacheRead,
        ));

        return $result;
    }

    /**
     * @param array<string, mixed> $reply
     */
    public static function json(array $reply, int $input = 1000, int $output = 200): TextResult
    {
        return self::text(json_encode($reply, \JSON_THROW_ON_ERROR), $input, $output);
    }

    /**
     * @param array<string, mixed> $arguments
     */
    public static function toolCall(string $name, array $arguments = [], int $input = 500, int $output = 20): ToolCallResult
    {
        $result = new ToolCallResult([new ToolCall('call_'.$name.'_'.bin2hex(random_bytes(3)), $name, $arguments)]);
        $result->getMetadata()->add('token_usage', new TokenUsage(promptTokens: $input, completionTokens: $output));

        return $result;
    }

    /**
     * Every text the last request carried - system prompt, earlier turns, tool results - joined.
     */
    public function lastPrompt(): string
    {
        return $this->prompt(\count($this->calls) - 1);
    }

    /**
     * Every text the request at the given position carried, joined.
     */
    public function prompt(int $index): string
    {
        $last = $this->calls[$index] ?? false;

        if ($last === false) {
            throw new \LogicException('The model has not been asked anything.');
        }

        $texts = [];
        $input = \is_string($last['input']) ? [$last['input']] : $last['input'];
        array_walk_recursive($input, static function (mixed $value) use (&$texts): void {
            if (\is_string($value)) {
                $texts[] = $value;
            }
        });

        return implode("\n\n", $texts);
    }
}
