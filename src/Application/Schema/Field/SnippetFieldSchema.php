<?php

declare(strict_types=1);

namespace Eekes\Sulu\AiContentBundle\Application\Schema\Field;

use Eekes\Sulu\AiContentBundle\Application\Catalog\FieldDescription;
use Eekes\Sulu\AiContentBundle\Application\Content\SnippetFinderInterface;
use Eekes\Sulu\AiContentBundle\Application\Schema\FieldSchemaInterface;
use Eekes\Sulu\AiContentBundle\Application\Schema\InvalidValueException;
use Eekes\Sulu\AiContentBundle\Application\Schema\SchemaContext;

/**
 * Snippet selections: existing snippets by uuid, found with search_snippets, and new snippets the
 * reply proposes, referred to as "new:<key>". New snippets are only created when the editor
 * applies the reply.
 */
final class SnippetFieldSchema implements FieldSchemaInterface
{
    public const string NEW_PREFIX = 'new:';

    public function __construct(
        private readonly SnippetFinderInterface $snippetFinder,
    ) {
    }

    /**
     * The snippet templates a selection allows; empty means every template.
     *
     * @return list<string>
     */
    public static function templateKeys(FieldDescription $field): array
    {
        $value = $field->params['templateKeys'] ?? null;

        if (\is_array($value)) {
            return array_values(array_filter(array_map(static fn (mixed $item) => \is_scalar($item) ? trim((string) $item) : '', $value)));
        }

        return \is_string($value) ? array_values(array_filter(array_map('trim', explode(',', $value)))) : [];
    }

    public function supports(FieldDescription $field): bool
    {
        return $field->type === 'snippet_selection' || $field->type === 'single_snippet_selection';
    }

    public function schema(FieldDescription $field, SchemaContext $context): array
    {
        $templates = self::templateKeys($field);
        $hint = \sprintf(
            'Snippets of the template %s: the uuid of an existing one from search_snippets, or "new:<key>" for a new snippet you define under "content.snippets".',
            $templates === [] ? 'of your choice' : implode(' or ', array_map(static fn (string $key) => '"'.$key.'"', $templates)),
        );

        if ($field->type === 'single_snippet_selection') {
            return ['type' => 'string', 'description' => $hint];
        }

        return ['type' => 'array', 'items' => ['type' => 'string'], 'description' => $hint];
    }

    /**
     * @return list<string>|string
     */
    public function sanitize(FieldDescription $field, mixed $value, SchemaContext $context): string|array
    {
        if ($field->type === 'single_snippet_selection') {
            return $this->references($field, [$value], $context)[0];
        }

        if (!\is_array($value)) {
            throw new InvalidValueException('Expected a list of snippet uuids or "new:<key>" references.');
        }

        return $this->references($field, array_values($value), $context);
    }

    /**
     * @param list<mixed> $values
     *
     * @return list<string>
     */
    private function references(FieldDescription $field, array $values, SchemaContext $context): array
    {
        $allowed = self::templateKeys($field);
        $references = [];
        $uuids = [];

        foreach ($values as $value) {
            if (!\is_string($value) || trim($value) === '') {
                throw new InvalidValueException('Expected a snippet uuid or a "new:<key>" reference.');
            }

            $references[] = trim($value);

            if (!str_starts_with(trim($value), self::NEW_PREFIX)) {
                $uuids[] = trim($value);
            }
        }

        $existing = $this->snippetFinder->templates($uuids);

        foreach ($references as $reference) {
            $template = str_starts_with($reference, self::NEW_PREFIX)
                ? $context->newSnippets[substr($reference, \strlen(self::NEW_PREFIX))] ?? null
                : $existing[$reference] ?? null;

            if ($template === null) {
                throw new InvalidValueException(str_starts_with($reference, self::NEW_PREFIX) ? \sprintf('"%s" is not defined under "content.snippets".', $reference) : \sprintf('Unknown snippet "%s". Use search_snippets to find real ones.', $reference));
            }

            if ($allowed !== [] && !\in_array($template, $allowed, true)) {
                throw new InvalidValueException(\sprintf('"%s" is a "%s" snippet; this field takes %s.', $reference, $template, implode(', ', $allowed)));
            }
        }

        return $references;
    }
}
