<?php

declare(strict_types=1);

namespace Eekes\Sulu\AiContentBundle\Application\Content;

/**
 * Lets the assistant pick images that are already in the media library. It never uploads or
 * generates media.
 */
interface MediaFinderInterface
{
    /**
     * Media whose title contains the query.
     *
     * @return list<array{id: int, title: string, description: null|string, mimeType: null|string}>
     */
    public function search(string $query, string $locale, int $limit = 10): array;

    /**
     * The ids of the given list that exist.
     *
     * @param list<int> $ids
     *
     * @return list<int>
     */
    public function existing(array $ids): array;
}
