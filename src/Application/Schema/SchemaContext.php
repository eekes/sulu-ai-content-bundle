<?php

declare(strict_types=1);

namespace Eekes\Sulu\AiContentBundle\Application\Schema;

final readonly class SchemaContext
{
    /**
     * @param array<string, string> $newSnippets the template of every snippet the reply proposes, by key
     */
    public function __construct(
        public string $locale,
        public ?string $webspaceKey = null,
        public array $newSnippets = [],
    ) {
    }

    /**
     * @param array<string, string> $newSnippets
     */
    public function withNewSnippets(array $newSnippets): self
    {
        return new self($this->locale, $this->webspaceKey, $newSnippets);
    }
}
