<?php

declare(strict_types=1);

namespace Eekes\Sulu\AiContentBundle\Application\Schema\Field;

use Eekes\Sulu\AiContentBundle\Application\Catalog\FieldDescription;
use Eekes\Sulu\AiContentBundle\Application\Content\MediaFinderInterface;
use Eekes\Sulu\AiContentBundle\Application\Schema\FieldSchemaInterface;
use Eekes\Sulu\AiContentBundle\Application\Schema\InvalidValueException;
use Eekes\Sulu\AiContentBundle\Application\Schema\SchemaContext;

/**
 * Media from the library, by id. An id that does not exist is an error the model hears about, so
 * an invented id never reaches the form.
 */
final class MediaFieldSchema implements FieldSchemaInterface
{
    public function __construct(
        private readonly MediaFinderInterface $mediaFinder,
    ) {
    }

    public function supports(FieldDescription $field): bool
    {
        return $field->type === 'single_media_selection' || $field->type === 'media_selection';
    }

    public function schema(FieldDescription $field, SchemaContext $context): array
    {
        $hint = 'Only ids returned by the search_media tool. Leave the field out when nothing fits.';

        if ($field->type === 'single_media_selection') {
            return ['type' => 'object', 'properties' => ['id' => ['type' => 'integer']], 'required' => ['id'], 'description' => $hint];
        }

        return ['type' => 'object', 'properties' => ['ids' => ['type' => 'array', 'items' => ['type' => 'integer']]], 'required' => ['ids'], 'description' => $hint];
    }

    /**
     * @return array{id: int}|array{ids: list<int>}
     */
    public function sanitize(FieldDescription $field, mixed $value, SchemaContext $context): array
    {
        if (!\is_array($value)) {
            throw new InvalidValueException('Expected an object.');
        }

        if ($field->type === 'single_media_selection') {
            $id = $value['id'] ?? null;

            if (!\is_int($id) || $this->mediaFinder->existing([$id]) === []) {
                throw new InvalidValueException('Unknown media id. Use an id from search_media or leave the field out.');
            }

            return ['id' => $id];
        }

        $ids = $value['ids'] ?? null;

        if (!\is_array($ids) || array_filter($ids, 'is_int') !== $ids) {
            throw new InvalidValueException('Expected {"ids": [integers]}.');
        }

        /** @var list<int> $ids */
        $ids = array_values($ids);
        $missing = array_diff($ids, $this->mediaFinder->existing($ids));

        if ($missing !== []) {
            throw new InvalidValueException(\sprintf('Unknown media ids: %s.', implode(', ', $missing)));
        }

        return ['ids' => $ids];
    }
}
