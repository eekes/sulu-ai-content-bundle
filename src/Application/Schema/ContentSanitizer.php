<?php

declare(strict_types=1);

namespace Eekes\Sulu\AiContentBundle\Application\Schema;

use Eekes\Sulu\AiContentBundle\Application\Catalog\Catalog;
use Eekes\Sulu\AiContentBundle\Application\Catalog\FieldDescription;
use Eekes\Sulu\AiContentBundle\Application\Catalog\Guide;
use Eekes\Sulu\AiContentBundle\Application\Catalog\TypeDescription;

/**
 * Checks the content the model proposed against the template, property by property: unknown
 * and excluded properties are dropped, every value goes through its field schema, and blocks are
 * checked against their own type.
 *
 * It never throws. What is wrong ends up in the errors, worded for the model, and is sent back
 * once so the model can fix it; what is right is kept either way.
 */
final class ContentSanitizer
{
    public function __construct(
        private readonly ContentSchemaBuilder $schemaBuilder,
    ) {
    }

    /**
     * @param array<mixed> $content the properties the model sent
     * @param null|array<mixed> $base what the form holds now (or the source locale when translating), which
     *                                the "_ref"s of the reply point into; null for something new, such as a snippet
     */
    public function sanitize(string $formKey, TypeDescription $template, array $content, Catalog $catalog, SchemaContext $context, ?array $base = null): SanitizedContent
    {
        $errors = [];
        $manual = [];
        $data = $this->sanitizeObject($template->fields, $content, $catalog, $context, null, $catalog->template($formKey, $template->key), '', $base, true, $errors, $manual);

        return new SanitizedContent($data, $errors, $manual);
    }

    /**
     * @param list<FieldDescription> $fields
     * @param array<mixed> $values
     * @param null|array<mixed> $base the same object as it is now: what the reply does not send is kept from it
     * @param bool $partial whether this is the page itself, where the reply only sends what changes
     * @param list<string> $errors problems the model can fix
     * @param list<string> $manual required fields only the editor can fill
     *
     * @return array<string, mixed>
     */
    private function sanitizeObject(array $fields, array $values, Catalog $catalog, SchemaContext $context, ?string $blockKey, ?Guide $templateGuide, string $path, ?array $base, bool $partial, array &$errors, array &$manual): array
    {
        $byName = [];

        foreach ($fields as $field) {
            $byName[$field->name] = $field;
        }

        $data = [];

        foreach ($values as $name => $value) {
            $name = (string) $name;
            $field = $byName[$name] ?? null;

            if ($field === null || !$this->schemaBuilder->isWritable($field, $catalog, $blockKey, $templateGuide)) {
                if ($name !== 'type' && $name !== ContentSchemaBuilder::BLOCK_REF) {
                    $errors[] = \sprintf('%s%s: there is no such field to fill in.', $path, $name);
                }

                continue;
            }

            if ($value === null) {
                continue;
            }

            if ($field->isBlock()) {
                $baseBlocks = \is_array($base[$name] ?? null) ? array_values($base[$name]) : [];
                $data[$name] = $this->sanitizeBlocks($field, $value, $catalog, $context, $path.$name, $baseBlocks, $errors, $manual);

                continue;
            }

            $fieldSchema = $this->schemaBuilder->findFieldSchema($field);

            if ($fieldSchema === null) {
                continue;
            }

            try {
                $data[$name] = $fieldSchema->sanitize($field, $value, $context);
            } catch (InvalidValueException $exception) {
                $errors[] = \sprintf('%s%s: %s', $path, $name, $exception->getMessage());
            }
        }

        // the page itself without a known current state: nothing to compare against
        if (!$partial || $base !== null) {
            $this->checkRequired($fields, $values, $data, $catalog, $blockKey, $templateGuide, $path, $base, $errors, $manual);
        }

        return $data;
    }

    /**
     * Every required property must end up filled: sent by the model, or - for the page itself and
     * for a block that stays ("_ref") - already filled now. What the model cannot write is left to
     * the editor, and said so.
     *
     * @param list<FieldDescription> $fields
     * @param array<mixed> $values what the model sent
     * @param array<string, mixed> $data what survived the checks
     * @param null|array<mixed> $base
     * @param list<string> $errors
     * @param list<string> $manual
     */
    private function checkRequired(array $fields, array $values, array $data, Catalog $catalog, ?string $blockKey, ?Guide $templateGuide, string $path, ?array $base, array &$errors, array &$manual): void
    {
        foreach ($fields as $field) {
            // a block list with a minimum is required too
            $required = $field->required || ($field->isBlock() && ($field->minOccurs ?? 0) > 0);

            if (!$required || !self::isEmpty($data[$field->name] ?? null)) {
                continue;
            }

            // sent but invalid: that error is reported already
            if (!self::isEmpty($values[$field->name] ?? null) && !\array_key_exists($field->name, $data)) {
                continue;
            }

            // not sent: the page keeps its value, a kept block ("_ref") starts from the existing one
            $kept = !\array_key_exists($field->name, $data) && !self::isEmpty($base[$field->name] ?? null);

            if ($kept) {
                continue;
            }

            if (!$this->schemaBuilder->isWritable($field, $catalog, $blockKey, $templateGuide)) {
                // the route of a page is generated by Sulu from the title
                if ($field->type !== 'route') {
                    $manual[] = \sprintf('%s%s (%s) is required and has to be filled in by hand.', $path, $field->name, $field->label);
                }

                continue;
            }

            $errors[] = \sprintf(
                '%s%s: "%s" is required. Fill it in. If you cannot fill it in properly - for example there is no page to link to - ask the editor for it with a question, or choose another block.',
                $path,
                $field->name,
                $field->label,
            );
        }
    }

    private static function isEmpty(mixed $value): bool
    {
        if ($value === null || $value === '' || $value === []) {
            return true;
        }

        if (\is_string($value)) {
            return trim(strip_tags($value)) === '';
        }

        if (\is_array($value) && \array_key_exists('href', $value)) {
            return self::isEmpty($value['href']);
        }

        if (\is_array($value) && \array_key_exists('id', $value) && \count($value) <= 2) {
            return $value['id'] === null;
        }

        return false;
    }

    /**
     * @param list<mixed> $baseBlocks the block list as it is now, which "_ref" points into
     * @param list<string> $errors
     * @param list<string> $manual
     *
     * @return list<array<string, mixed>>
     */
    private function sanitizeBlocks(FieldDescription $field, mixed $value, Catalog $catalog, SchemaContext $context, string $path, array $baseBlocks, array &$errors, array &$manual): array
    {
        if (!\is_array($value)) {
            $errors[] = \sprintf('%s: expected a list of blocks.', $path);

            return [];
        }

        $blocks = [];

        foreach (array_values($value) as $index => $item) {
            $itemPath = \sprintf('%s[%d]', $path, $index);
            $typeKey = \is_array($item) && \is_string($item['type'] ?? null) ? $item['type'] : null;
            $type = $typeKey === null ? null : $field->findType($typeKey);

            if (!\is_array($item) || $type === null) {
                $errors[] = \sprintf('%s: unknown block type "%s". Allowed: %s.', $itemPath, $typeKey ?? '', implode(', ', array_map(static fn (TypeDescription $type) => $type->key, $field->types)));

                continue;
            }

            $block = ['type' => $type->key];
            $baseBlock = null;

            if (\is_int($item[ContentSchemaBuilder::BLOCK_REF] ?? null)) {
                $block[ContentSchemaBuilder::BLOCK_REF] = $item[ContentSchemaBuilder::BLOCK_REF];
                $original = $baseBlocks[$item[ContentSchemaBuilder::BLOCK_REF]] ?? null;

                // the admin starts from the referenced block only when it is of the same type
                if (\is_array($original) && ($original['type'] ?? null) === $type->key) {
                    $baseBlock = $original;
                }
            }

            $blocks[] = $block + $this->sanitizeObject($type->fields, $item, $catalog, $context, $type->key, null, $itemPath.'.', $baseBlock, false, $errors, $manual);
        }

        if ($field->maxOccurs !== null && \count($blocks) > $field->maxOccurs) {
            $errors[] = \sprintf('%s: at most %d blocks.', $path, $field->maxOccurs);
            $blocks = \array_slice($blocks, 0, $field->maxOccurs);
        }

        if ($field->minOccurs !== null && $field->minOccurs > 0 && \count($blocks) < $field->minOccurs) {
            $errors[] = \sprintf('%s: at least %d blocks.', $path, $field->minOccurs);
        }

        return $blocks;
    }
}
