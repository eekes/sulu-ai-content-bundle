<?php

declare(strict_types=1);

namespace Eekes\Sulu\AiContentBundle\Application\Model;

/**
 * One model editors can pick, as configured under eekes_sulu_ai_content.models.
 */
final readonly class ModelChoice
{
    /**
     * @param string $model the model name the platform knows it by
     * @param array<string, mixed> $options passed on every call (temperature, max_tokens, ...)
     */
    public function __construct(
        public string $key,
        public string $model,
        public string $label,
        public array $options = [],
    ) {
    }
}
