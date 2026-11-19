<?php

declare(strict_types=1);

namespace Eekes\Sulu\AiContentBundle\Application\Assistant\Tool;

use Eekes\Sulu\AiContentBundle\Application\Content\TaxonomyFinderInterface;
use Symfony\AI\Agent\Toolbox\Attribute\AsTool;

#[AsTool('search_tags', 'Finds the tags of the website, for the tag filter of a smart content list. Returns the id and name of at most 30 tags.')]
final class SearchTagsTool
{
    public function __construct(
        private readonly TaxonomyFinderInterface $taxonomyFinder,
    ) {
    }

    /**
     * @param string $query Words from the tag name; an empty string lists the tags by name
     *
     * @return list<array{id: int, name: string}>
     */
    public function __invoke(string $query = ''): array
    {
        return $this->taxonomyFinder->searchTags($query);
    }
}
