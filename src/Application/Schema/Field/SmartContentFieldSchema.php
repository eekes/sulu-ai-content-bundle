<?php

declare(strict_types=1);

namespace Eekes\Sulu\AiContentBundle\Application\Schema\Field;

use Eekes\Sulu\AiContentBundle\Application\Catalog\FieldDescription;
use Eekes\Sulu\AiContentBundle\Application\Content\PageFinderInterface;
use Eekes\Sulu\AiContentBundle\Application\Content\SmartContentDescription;
use Eekes\Sulu\AiContentBundle\Application\Content\SmartContentProvidersInterface;
use Eekes\Sulu\AiContentBundle\Application\Content\TaxonomyFinderInterface;
use Eekes\Sulu\AiContentBundle\Application\Schema\FieldSchemaInterface;
use Eekes\Sulu\AiContentBundle\Application\Schema\InvalidValueException;
use Eekes\Sulu\AiContentBundle\Application\Schema\SchemaContext;

/**
 * Smart content: a list that fills itself on the website - the latest articles, the pages below a
 * page - from filters. The assistant sets the filters the provider offers (data source, categories,
 * tags, types, sorting, limit, presentation); it never picks the items themselves.
 *
 * Categories and tags are ids from search_categories and search_tags, a data source is a page uuid;
 * every id is checked before it reaches the form.
 */
final class SmartContentFieldSchema implements FieldSchemaInterface
{
    public const int MAX_LIMIT = 100;

    public function __construct(
        private readonly SmartContentProvidersInterface $providers,
        private readonly TaxonomyFinderInterface $taxonomyFinder,
        private readonly PageFinderInterface $pageFinder,
    ) {
    }

    /**
     * The provider a smart content property lists, "pages" when it does not say.
     */
    public static function provider(FieldDescription $field): string
    {
        $provider = $field->params['provider'] ?? null;

        return \is_string($provider) && $provider !== '' ? $provider : 'pages';
    }

    /**
     * The ways a smart content property can be shown, by key, from its "present_as" param.
     *
     * @return array<string, string>
     */
    public static function presentations(FieldDescription $field): array
    {
        $presentations = [];
        $values = $field->params['present_as'] ?? null;

        foreach (\is_array($values) ? $values : [] as $key => $label) {
            if (\is_string($key) && $key !== '') {
                $presentations[$key] = \is_string($label) && $label !== '' ? $label : $key;
            }
        }

        return $presentations;
    }

    public function supports(FieldDescription $field): bool
    {
        return $field->type === 'smart_content' && $this->providers->describe(self::provider($field)) !== null;
    }

    public function schema(FieldDescription $field, SchemaContext $context): array
    {
        $description = $this->describe($field);
        $properties = [];

        if ($description->hasPageDataSource()) {
            $properties['dataSource'] = ['type' => 'string', 'description' => 'The uuid of the page whose child pages (or articles) are listed, from the site structure or search_pages. Leave it out to list from the whole website.'];
            $properties['includeSubFolders'] = ['type' => 'boolean', 'description' => 'Also list everything deeper below the data source page.'];
        }

        if ($description->categories) {
            $properties['categories'] = ['type' => 'array', 'items' => ['type' => 'integer'], 'description' => 'Category ids from search_categories.'];
            $properties['categoryOperator'] = ['type' => 'string', 'enum' => ['or', 'and'], 'description' => '"or": any of the categories, "and": all of them.'];
        }

        if ($description->tags) {
            $properties['tags'] = ['type' => 'array', 'items' => ['type' => 'integer'], 'description' => 'Tag ids from search_tags.'];
            $properties['tagOperator'] = ['type' => 'string', 'enum' => ['or', 'and'], 'description' => '"or": any of the tags, "and": all of them.'];
        }

        if ($description->types !== []) {
            $properties['types'] = ['type' => 'array', 'items' => ['type' => 'string', 'enum' => array_column($description->types, 'value')], 'description' => 'Only these types: '.self::labels($description->types).'.'];
        }

        if ($description->sorting !== []) {
            $properties['sortBy'] = ['type' => 'string', 'enum' => array_column($description->sorting, 'value'), 'description' => 'Sort by: '.self::labels($description->sorting).'.'];
            $properties['sortMethod'] = ['type' => 'string', 'enum' => ['asc', 'desc'], 'description' => '"desc" for the newest first.'];
        }

        if ($description->limit) {
            $properties['limitResult'] = ['type' => 'integer', 'minimum' => 1, 'maximum' => self::MAX_LIMIT, 'description' => 'How many items at most.'];
        }

        $presentations = self::presentations($field);

        if ($presentations !== []) {
            $properties['presentAs'] = ['type' => 'string', 'enum' => array_keys($presentations), 'description' => 'How the list is shown: '.self::labels(array_map(static fn (string $value, string $label) => ['value' => $value, 'label' => $label], array_keys($presentations), $presentations)).'.'];
        }

        return [
            'type' => 'object',
            'properties' => $properties,
            'description' => \sprintf('A list of %s that fills itself on the website from these filters; you never pick the items. Set only the filters the block needs and describe in the outline what the list will show.', $description->provider),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function sanitize(FieldDescription $field, mixed $value, SchemaContext $context): array
    {
        if (!\is_array($value) || ($value !== [] && array_is_list($value))) {
            throw new InvalidValueException('Expected an object with the filters of the list.');
        }

        $description = $this->describe($field);
        $presentations = self::presentations($field);
        $filters = [];

        foreach ($value as $key => $item) {
            $filters += match ($key) {
                'dataSource' => $description->hasPageDataSource() ? ['dataSource' => $this->dataSource($item)] : self::unsupported($key, $description),
                'includeSubFolders' => $description->hasPageDataSource() ? ['includeSubFolders' => self::bool($key, $item)] : self::unsupported($key, $description),
                'categories' => $description->categories ? ['categories' => $this->categories($item)] : self::unsupported($key, $description),
                'categoryOperator' => $description->categories ? ['categoryOperator' => self::operator($key, $item)] : self::unsupported($key, $description),
                'tags' => $description->tags ? ['tags' => $this->tags($item)] : self::unsupported($key, $description),
                'tagOperator' => $description->tags ? ['tagOperator' => self::operator($key, $item)] : self::unsupported($key, $description),
                'types' => $description->types !== [] ? ['types' => self::types($item, $description)] : self::unsupported($key, $description),
                'sortBy' => $description->sorting !== [] ? ['sortBy' => self::oneOf($key, $item, array_column($description->sorting, 'value'))] : self::unsupported($key, $description),
                'sortMethod' => $description->sorting !== [] ? ['sortMethod' => self::oneOf($key, strtolower(\is_string($item) ? $item : ''), ['asc', 'desc'])] : self::unsupported($key, $description),
                'limitResult' => $description->limit ? ['limitResult' => self::limit($item)] : self::unsupported($key, $description),
                'presentAs' => $presentations !== [] ? ['presentAs' => self::oneOf($key, $item, array_keys($presentations))] : self::unsupported($key, $description),
                default => self::unsupported((string) $key, $description),
            };
        }

        return $filters;
    }

    private function describe(FieldDescription $field): SmartContentDescription
    {
        return $this->providers->describe(self::provider($field)) ?? throw new InvalidValueException(\sprintf('The smart content provider "%s" does not exist.', self::provider($field)));
    }

    private function dataSource(mixed $value): string
    {
        if (!\is_string($value) || trim($value) === '') {
            throw new InvalidValueException('"dataSource" must be a page uuid.');
        }

        if ($this->pageFinder->existing([trim($value)]) === []) {
            throw new InvalidValueException(\sprintf('Unknown page "%s" as data source. Use a page of the site structure or search_pages.', $value));
        }

        return trim($value);
    }

    /**
     * @return list<int>
     */
    private function categories(mixed $value): array
    {
        $ids = self::ids('categories', $value);
        $missing = array_diff($ids, $this->taxonomyFinder->existingCategories($ids));

        if ($missing !== []) {
            throw new InvalidValueException(\sprintf('Unknown category ids: %s. Use search_categories to find real ones.', implode(', ', $missing)));
        }

        return $ids;
    }

    /**
     * @return list<int>
     */
    private function tags(mixed $value): array
    {
        $ids = self::ids('tags', $value);
        $missing = array_diff($ids, $this->taxonomyFinder->existingTags($ids));

        if ($missing !== []) {
            throw new InvalidValueException(\sprintf('Unknown tag ids: %s. Use search_tags to find real ones.', implode(', ', $missing)));
        }

        return $ids;
    }

    /**
     * @return list<int>
     */
    private static function ids(string $key, mixed $value): array
    {
        if (!\is_array($value)) {
            throw new InvalidValueException(\sprintf('"%s" must be a list of ids.', $key));
        }

        $ids = [];

        foreach ($value as $id) {
            if (\is_int($id) || (\is_string($id) && ctype_digit($id))) {
                $ids[] = (int) $id;

                continue;
            }

            throw new InvalidValueException(\sprintf('"%s" must be a list of ids, not names.', $key));
        }

        return array_values(array_unique($ids));
    }

    /**
     * @return list<string>
     */
    private static function types(mixed $value, SmartContentDescription $description): array
    {
        if (!\is_array($value)) {
            throw new InvalidValueException('"types" must be a list.');
        }

        $allowed = array_column($description->types, 'value');

        return array_values(array_map(static fn (mixed $type) => self::oneOf('types', $type, $allowed), $value));
    }

    private static function limit(mixed $value): int
    {
        if (!\is_int($value) || $value < 1 || $value > self::MAX_LIMIT) {
            throw new InvalidValueException(\sprintf('"limitResult" must be a number from 1 to %d.', self::MAX_LIMIT));
        }

        return $value;
    }

    private static function bool(string $key, mixed $value): bool
    {
        return \is_bool($value) ? $value : throw new InvalidValueException(\sprintf('"%s" must be true or false.', $key));
    }

    private static function operator(string $key, mixed $value): string
    {
        return self::oneOf($key, strtolower(\is_string($value) ? $value : ''), ['or', 'and']);
    }

    /**
     * @param list<string> $allowed
     */
    private static function oneOf(string $key, mixed $value, array $allowed): string
    {
        if (!\is_string($value) || !\in_array($value, $allowed, true)) {
            throw new InvalidValueException(\sprintf('"%s" must be one of: %s.', $key, implode(', ', $allowed)));
        }

        return $value;
    }

    private static function unsupported(string $key, SmartContentDescription $description): never
    {
        throw new InvalidValueException(\sprintf('The %s list has no "%s" filter.', $description->provider, $key));
    }

    /**
     * @param list<array{value: string, label: string}> $options
     */
    private static function labels(array $options): string
    {
        return implode(', ', array_map(static fn (array $option) => $option['value'] === $option['label'] ? $option['value'] : \sprintf('"%s" (%s)', $option['value'], $option['label']), $options));
    }
}
