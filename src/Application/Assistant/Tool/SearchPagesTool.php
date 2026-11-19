<?php

declare(strict_types=1);

namespace Eekes\Sulu\AiContentBundle\Application\Assistant\Tool;

use Eekes\Sulu\AiContentBundle\Application\Assistant\AssistantScope;
use Eekes\Sulu\AiContentBundle\Application\Content\PageFinderInterface;
use Symfony\AI\Agent\Toolbox\Attribute\AsTool;

#[AsTool('search_pages', 'Finds pages of this website whose title contains the given words, to link to them. Returns the uuid, title and template of at most 10 pages.')]
final class SearchPagesTool
{
    public function __construct(
        private readonly PageFinderInterface $pageFinder,
        private readonly AssistantScope $scope,
    ) {
    }

    /**
     * @param string $query One or more words from the page title; an empty string lists recent pages
     *
     * @return list<array{uuid: string, title: string, template: null|string}>
     */
    public function __invoke(string $query): array
    {
        return $this->pageFinder->search($query, $this->scope->getLocale(), $this->scope->getWebspaceKey());
    }
}
