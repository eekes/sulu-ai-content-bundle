<?php

declare(strict_types=1);

namespace Eekes\Sulu\AiContentBundle\Application\Schema;

use Eekes\Sulu\AiContentBundle\Application\Catalog\Catalog;
use Eekes\Sulu\AiContentBundle\Application\Catalog\FieldDescription;
use Eekes\Sulu\AiContentBundle\Application\Catalog\Guide;
use Eekes\Sulu\AiContentBundle\Application\Catalog\TypeDescription;

/**
 * Turns a template into the JSON schema of the content the model may write: only the properties
 * a field schema supports and the catalog does not exclude, with blocks as a list of typed
 * objects.
 *
 * Every block item may carry "_ref", the position of an existing block it is a new version of.
 * The admin then starts from that block, so settings and properties the assistant cannot see are
 * kept.
 */
final class ContentSchemaBuilder
{
    public const string BLOCK_REF = '_ref';

    /**
     * @param iterable<FieldSchemaInterface> $fieldSchemas
     */
    public function __construct(
        private readonly iterable $fieldSchemas,
    ) {
    }

    /**
     * @return array{type: 'object', properties: array<string, array<string, mixed>>, additionalProperties: false}
     */
    public function build(string $formKey, TypeDescription $template, Catalog $catalog, SchemaContext $context): array
    {
        return $this->objectSchema($template->fields, $catalog, $context, null, $catalog->template($formKey, $template->key));
    }

    public function findFieldSchema(FieldDescription $field): ?FieldSchemaInterface
    {
        foreach ($this->fieldSchemas as $fieldSchema) {
            if ($fieldSchema->supports($field)) {
                return $fieldSchema;
            }
        }

        return null;
    }

    /**
     * Whether the assistant may write this property at all.
     */
    public function isWritable(FieldDescription $field, Catalog $catalog, ?string $blockKey, ?Guide $templateGuide): bool
    {
        if ($catalog->isExcluded($field->name, $blockKey, $templateGuide)) {
            return false;
        }

        return $field->isBlock() || $this->findFieldSchema($field) !== null;
    }

    /**
     * @param list<FieldDescription> $fields
     *
     * @return array{type: 'object', properties: array<string, array<string, mixed>>, additionalProperties: false}
     */
    private function objectSchema(array $fields, Catalog $catalog, SchemaContext $context, ?string $blockKey, ?Guide $templateGuide): array
    {
        $properties = [];

        foreach ($fields as $field) {
            if (!$this->isWritable($field, $catalog, $blockKey, $templateGuide)) {
                continue;
            }

            $schema = $field->isBlock() ? $this->blockSchema($field, $catalog, $context) : $this->findFieldSchema($field)?->schema($field, $context);

            if ($schema === null) {
                continue;
            }

            $label = $field->label.($field->required ? ' (required)' : '');
            $schema['description'] = isset($schema['description']) && \is_string($schema['description']) ? $label.'. '.$schema['description'] : $label;
            $properties[$field->name] = $schema;
        }

        return ['type' => 'object', 'properties' => $properties, 'additionalProperties' => false];
    }

    /**
     * @return array<string, mixed>
     */
    private function blockSchema(FieldDescription $field, Catalog $catalog, SchemaContext $context): array
    {
        $variants = [];

        foreach ($field->types as $type) {
            $object = $this->objectSchema($type->fields, $catalog, $context, $type->key, null);
            $required = ['type'];

            foreach ($type->fields as $typeField) {
                if ($typeField->required && isset($object['properties'][$typeField->name])) {
                    $required[] = $typeField->name;
                }
            }

            $variants[] = [
                'type' => 'object',
                'title' => $type->title,
                'properties' => [
                    'type' => ['const' => $type->key],
                    self::BLOCK_REF => ['type' => 'integer', 'description' => 'Position of the existing block this replaces; leave out for a new block.'],
                ] + $object['properties'],
                'required' => $required,
                'additionalProperties' => false,
            ];
        }

        $schema = ['type' => 'array', 'items' => ['oneOf' => $variants]];

        if ($field->minOccurs !== null) {
            $schema['minItems'] = $field->minOccurs;
        }

        if ($field->maxOccurs !== null) {
            $schema['maxItems'] = $field->maxOccurs;
        }

        return $schema;
    }
}
