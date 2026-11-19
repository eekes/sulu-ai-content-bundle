<?php

declare(strict_types=1);

namespace Eekes\Sulu\AiContentBundle\Domain\Model;

/**
 * The tokens one run used, summed over every model request it made (a run with tool calls makes
 * several). Providers report different subsets; what a provider leaves out stays 0.
 */
final readonly class TokenTotals
{
    public function __construct(
        public int $input = 0,
        public int $output = 0,
        public int $cacheRead = 0,
        public int $cacheWrite = 0,
        public int $thinking = 0,
        public int $total = 0,
    ) {
    }

    public function add(self $other): self
    {
        return new self(
            $this->input + $other->input,
            $this->output + $other->output,
            $this->cacheRead + $other->cacheRead,
            $this->cacheWrite + $other->cacheWrite,
            $this->thinking + $other->thinking,
            $this->total + $other->total,
        );
    }

    /**
     * @return array{input: int, output: int, cacheRead: int, cacheWrite: int, thinking: int, total: int}
     */
    public function toArray(): array
    {
        return [
            'input' => $this->input,
            'output' => $this->output,
            'cacheRead' => $this->cacheRead,
            'cacheWrite' => $this->cacheWrite,
            'thinking' => $this->thinking,
            'total' => $this->total,
        ];
    }
}
