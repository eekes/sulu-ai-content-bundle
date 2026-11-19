<?php

declare(strict_types=1);

namespace Eekes\Sulu\AiContentBundle\Application\Model;

/**
 * The models editors can choose from. The provider and its API key are configured once, in
 * Symfony AI; this only says which of its models are offered, under which name.
 */
final class ModelRegistry
{
    /**
     * @var array<string, ModelChoice>
     */
    private array $models = [];

    /**
     * @param array<string, array{model: string, label?: null|string, options?: array<string, mixed>}> $models
     */
    public function __construct(
        array $models,
        private readonly ?string $defaultKey = null,
        private readonly ?string $catalogKey = null,
    ) {
        foreach ($models as $key => $model) {
            if ($model['model'] === '') {
                throw new \InvalidArgumentException(\sprintf('The model "%s" has no model name.', $key));
            }

            $this->models[$key] = new ModelChoice($key, $model['model'], $model['label'] ?? $key, $model['options'] ?? []);
        }
    }

    /**
     * @return list<ModelChoice>
     */
    public function all(): array
    {
        return array_values($this->models);
    }

    public function isConfigured(): bool
    {
        return $this->models !== [];
    }

    /**
     * The model of the given key, or the default one for null or a key that is not (or no longer)
     * configured.
     */
    public function get(?string $key = null): ModelChoice
    {
        if ($key !== null && isset($this->models[$key])) {
            return $this->models[$key];
        }

        if ($this->defaultKey !== null && isset($this->models[$this->defaultKey])) {
            return $this->models[$this->defaultKey];
        }

        $first = reset($this->models);

        if ($first === false) {
            throw new \LogicException('No AI model is configured. Add one under "eekes_sulu_ai_content.models".');
        }

        return $first;
    }

    /**
     * The model the analyze command describes blocks with.
     */
    public function getForCatalog(): ModelChoice
    {
        return $this->get($this->catalogKey);
    }
}
