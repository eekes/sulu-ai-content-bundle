<?php

declare(strict_types=1);

namespace Eekes\Sulu\AiContentBundle\Application\Content;

/**
 * Sulu's categories and tags, for the filters of smart content.
 */
interface TaxonomyFinderInterface
{
    /**
     * Categories whose name or key contains the query; an empty query lists them in tree order.
     *
     * @return list<array{id: int, name: string, key: null|string, parent: null|string}>
     */
    public function searchCategories(string $query, string $locale, int $limit = 30): array;

    /**
     * Tags whose name contains the query; an empty query lists them by name.
     *
     * @return list<array{id: int, name: string}>
     */
    public function searchTags(string $query, int $limit = 30): array;

    /**
     * @param list<int> $ids
     *
     * @return list<int> the ids of the list that exist
     */
    public function existingCategories(array $ids): array;

    /**
     * @param list<int> $ids
     *
     * @return list<int> the ids of the list that exist
     */
    public function existingTags(array $ids): array;
}
