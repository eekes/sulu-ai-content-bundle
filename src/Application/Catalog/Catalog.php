<?php

declare(strict_types=1);

namespace Eekes\Sulu\AiContentBundle\Application\Catalog;

/**
 * The project's description of its own templates and blocks: generated once by the analyze
 * command, then fine-tuned by hand and kept in version control.
 */
final readonly class Catalog
{
    /**
     * @param list<string> $excludeFields properties no template or block lets the assistant fill in
     * @param array<string, array<string, Guide>> $templates by form key, then template key
     * @param array<string, Guide> $blocks by block type key
     */
    public function __construct(
        public array $excludeFields = [],
        public array $templates = [],
        public array $blocks = [],
    ) {
    }

    /**
     * @param array<mixed> $data
     */
    public static function fromArray(array $data): self
    {
        $templates = [];

        foreach (\is_array($data['templates'] ?? null) ? $data['templates'] : [] as $formKey => $forms) {
            foreach (\is_array($forms) ? $forms : [] as $templateKey => $guide) {
                $templates[(string) $formKey][(string) $templateKey] = Guide::fromArray(\is_array($guide) ? $guide : []);
            }
        }

        $blocks = [];

        foreach (\is_array($data['blocks'] ?? null) ? $data['blocks'] : [] as $key => $guide) {
            $blocks[(string) $key] = Guide::fromArray(\is_array($guide) ? $guide : []);
        }

        $excludeFields = \is_array($data['exclude_fields'] ?? null) ? array_values(array_filter($data['exclude_fields'], 'is_string')) : [];

        return new self($excludeFields, $templates, $blocks);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $templates = [];

        foreach ($this->templates as $formKey => $forms) {
            ksort($forms);
            $templates[$formKey] = array_map(static fn (Guide $guide) => $guide->toArray(), $forms);
        }

        ksort($templates);
        $blocks = $this->blocks;
        ksort($blocks);

        return [
            'exclude_fields' => $this->excludeFields,
            'templates' => $templates,
            'blocks' => array_map(static fn (Guide $guide) => $guide->toArray(), $blocks),
        ];
    }

    public function template(string $formKey, string $templateKey): ?Guide
    {
        return $this->templates[$formKey][$templateKey] ?? null;
    }

    public function block(string $key): ?Guide
    {
        return $this->blocks[$key] ?? null;
    }

    /**
     * @param null|string $blockKey the block type the field belongs to, null for a template property
     */
    public function isExcluded(string $field, ?string $blockKey = null, ?Guide $template = null): bool
    {
        if (\in_array($field, $this->excludeFields, true)) {
            return true;
        }

        $guide = $blockKey === null ? $template : $this->block($blockKey);

        return $guide !== null && \in_array($field, $guide->excludeFields, true);
    }

    public function withTemplate(string $formKey, string $templateKey, Guide $guide): self
    {
        $templates = $this->templates;
        $templates[$formKey][$templateKey] = $guide;

        return new self($this->excludeFields, $templates, $this->blocks);
    }

    public function withBlock(string $key, Guide $guide): self
    {
        $blocks = $this->blocks;
        $blocks[$key] = $guide;

        return new self($this->excludeFields, $this->templates, $blocks);
    }

    public function withoutBlock(string $key): self
    {
        $blocks = $this->blocks;
        unset($blocks[$key]);

        return new self($this->excludeFields, $this->templates, $blocks);
    }

    public function withoutTemplate(string $formKey, string $templateKey): self
    {
        $templates = $this->templates;
        unset($templates[$formKey][$templateKey]);

        return new self($this->excludeFields, $templates, $this->blocks);
    }
}
