<?php

declare(strict_types=1);

namespace Eekes\Sulu\AiContentBundle\Application\Schema\Field;

use Eekes\Sulu\AiContentBundle\Application\Catalog\FieldDescription;
use Eekes\Sulu\AiContentBundle\Application\Schema\FieldSchemaInterface;
use Eekes\Sulu\AiContentBundle\Application\Schema\InvalidValueException;
use Eekes\Sulu\AiContentBundle\Application\Schema\SchemaContext;

/**
 * Selects with a fixed list of values. A select whose values come from a provider at runtime
 * has no list here, so it is not offered to the model.
 */
final class ChoiceFieldSchema implements FieldSchemaInterface
{
    public function supports(FieldDescription $field): bool
    {
        return \in_array($field->type, ['single_select', 'select'], true) && $field->choices !== [];
    }

    public function schema(FieldDescription $field, SchemaContext $context): array
    {
        $values = array_column($field->choices, 'value');
        $labels = implode(', ', array_map(static fn (array $choice) => \sprintf('%s = %s', $choice['value'], $choice['label']), $field->choices));

        if ($field->type === 'select') {
            return ['type' => 'array', 'items' => ['type' => 'string', 'enum' => $values], 'description' => $labels];
        }

        return ['type' => 'string', 'enum' => $values, 'description' => $labels];
    }

    /**
     * @return list<string>|string
     */
    public function sanitize(FieldDescription $field, mixed $value, SchemaContext $context): string|array
    {
        $allowed = array_column($field->choices, 'value');

        if ($field->type === 'select') {
            if (!\is_array($value)) {
                throw new InvalidValueException('Expected a list of values.');
            }

            return array_values(array_map(fn (mixed $item) => $this->choice($item, $allowed), $value));
        }

        return $this->choice($value, $allowed);
    }

    /**
     * @param list<string> $allowed
     */
    private function choice(mixed $value, array $allowed): string
    {
        if (!\is_scalar($value) || !\in_array((string) $value, $allowed, true)) {
            throw new InvalidValueException(\sprintf('Expected one of: %s.', implode(', ', $allowed)));
        }

        return (string) $value;
    }
}
