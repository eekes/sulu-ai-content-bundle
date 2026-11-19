<?php

declare(strict_types=1);

namespace Eekes\Sulu\AiContentBundle\Application\Catalog;

/**
 * One property of a template or a block, as Sulu's form metadata describes it: plain data, so
 * the schema builder, the catalog and the tests never touch Sulu's metadata classes.
 */
final readonly class FieldDescription
{
    /**
     * @param array<string, mixed> $params the <param>s of the property, by name
     * @param list<array{value: string, label: string}> $choices the values of a select
     * @param list<TypeDescription> $types the block types of a block property
     * @param list<string> $tags the names of the <tag>s on the property
     */
    public function __construct(
        public string $name,
        public string $type,
        public string $label,
        public ?string $description = null,
        public bool $required = false,
        public array $params = [],
        public array $choices = [],
        public array $types = [],
        public ?int $minOccurs = null,
        public ?int $maxOccurs = null,
        public array $tags = [],
    ) {
    }

    public function isBlock(): bool
    {
        return $this->types !== [];
    }

    public function findType(string $key): ?TypeDescription
    {
        foreach ($this->types as $type) {
            if ($type->key === $key) {
                return $type;
            }
        }

        return null;
    }

    /**
     * A structural fingerprint input: what the field is, not how it is labelled.
     *
     * @return array<string, mixed>
     */
    public function signature(): array
    {
        return [
            'name' => $this->name,
            'type' => $this->type,
            'required' => $this->required,
            'choices' => array_column($this->choices, 'value'),
            'types' => array_map(static fn (TypeDescription $type) => $type->signature(), $this->types),
            'minOccurs' => $this->minOccurs,
            'maxOccurs' => $this->maxOccurs,
        ];
    }
}
