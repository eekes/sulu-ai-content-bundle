<?php

declare(strict_types=1);

namespace Eekes\Sulu\AiContentBundle\Application\Assistant\Tool;

use Eekes\Sulu\AiContentBundle\Application\Assistant\AssistantScope;
use Eekes\Sulu\AiContentBundle\Application\Content\TaxonomyFinderInterface;
use Symfony\AI\Agent\Toolbox\Attribute\AsTool;

#[AsTool('search_categories', 'Finds the categories of the website, for the category filter of a smart content list. Returns the id, name, key and parent of at most 30 categories.')]
final class SearchCategoriesTool
{
    public function __construct(
        private readonly TaxonomyFinderInterface $taxonomyFinder,
        private readonly AssistantScope $scope,
    ) {
    }

    /**
     * @param string $query Words from the name or the key; an empty string lists the categories in tree order
     *
     * @return list<array{id: int, name: string, key: null|string, parent: null|string}>
     */
    public function __invoke(string $query = ''): array
    {
        return $this->taxonomyFinder->searchCategories($query, $this->scope->getLocale());
    }
}
