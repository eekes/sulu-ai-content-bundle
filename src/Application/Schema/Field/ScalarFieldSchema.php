<?php

declare(strict_types=1);

namespace Eekes\Sulu\AiContentBundle\Application\Schema\Field;

use Eekes\Sulu\AiContentBundle\Application\Catalog\FieldDescription;
use Eekes\Sulu\AiContentBundle\Application\Schema\FieldSchemaInterface;
use Eekes\Sulu\AiContentBundle\Application\Schema\InvalidValueException;
use Eekes\Sulu\AiContentBundle\Application\Schema\SchemaContext;

/**
 * Checkboxes and numbers.
 */
final class ScalarFieldSchema implements FieldSchemaInterface
{
    public function supports(FieldDescription $field): bool
    {
        return $field->type === 'checkbox' || $field->type === 'number';
    }

    public function schema(FieldDescription $field, SchemaContext $context): array
    {
        if ($field->type === 'checkbox') {
            return ['type' => 'boolean'];
        }

        $schema = ['type' => 'number'];

        foreach (['min' => 'minimum', 'max' => 'maximum'] as $param => $keyword) {
            if (is_numeric($field->params[$param] ?? null)) {
                $schema[$keyword] = (float) $field->params[$param];
            }
        }

        return $schema;
    }

    public function sanitize(FieldDescription $field, mixed $value, SchemaContext $context): bool|int|float
    {
        if ($field->type === 'checkbox') {
            if (!\is_bool($value)) {
                throw new InvalidValueException('Expected true or false.');
            }

            return $value;
        }

        if (!is_numeric($value)) {
            throw new InvalidValueException('Expected a number.');
        }

        $number = $value + 0;

        foreach (['min' => static fn ($limit) => $number < $limit, 'max' => static fn ($limit) => $number > $limit] as $param => $outside) {
            $limit = $field->params[$param] ?? null;

            if (is_numeric($limit) && $outside((float) $limit)) {
                throw new InvalidValueException(\sprintf('%s is outside the allowed range.', $number));
            }
        }

        return $number;
    }
}
