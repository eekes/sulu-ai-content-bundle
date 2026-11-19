<?php

declare(strict_types=1);

namespace Eekes\Sulu\AiContentBundle\Application\Catalog;

/**
 * A template, or one type of a block property: a key, a title and the fields it holds.
 */
final readonly class TypeDescription
{
    /**
     * @param list<FieldDescription> $fields
     * @param null|string $globalBlock the key of the global block this type refers to
     */
    public function __construct(
        public string $key,
        public string $title,
        public array $fields,
        public ?string $globalBlock = null,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function signature(): array
    {
        return [
            'key' => $this->key,
            'fields' => array_map(static fn (FieldDescription $field) => $field->signature(), $this->fields),
        ];
    }

    /**
     * Changes when a field is added, removed or retyped - and only then. The catalog uses it to
     * see which descriptions are out of date.
     */
    public function hash(): string
    {
        return substr(hash('sha256', json_encode($this->signature(), \JSON_THROW_ON_ERROR)), 0, 12);
    }

    public function findField(string $name): ?FieldDescription
    {
        foreach ($this->fields as $field) {
            if ($field->name === $name) {
                return $field;
            }
        }

        return null;
    }
}
