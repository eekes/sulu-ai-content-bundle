<?php

declare(strict_types=1);

namespace Eekes\Sulu\AiContentBundle\Application\Assistant\Tool;

use Eekes\Sulu\AiContentBundle\Application\Assistant\AssistantScope;
use Eekes\Sulu\AiContentBundle\Application\Content\SnippetFinderInterface;
use Symfony\AI\Agent\Toolbox\Attribute\AsTool;

#[AsTool('search_snippets', 'Finds existing snippets (reusable content such as FAQ items or testimonials) of the given snippet template, to reuse them before you propose new ones. Returns the uuid, title, template and a short version of the content of at most 15 snippets.')]
final class SearchSnippetsTool
{
    public function __construct(
        private readonly SnippetFinderInterface $snippetFinder,
        private readonly AssistantScope $scope,
    ) {
    }

    /**
     * @param string $template The snippet template key, as listed for the snippet field; empty for every template
     * @param string $query Words from the title; an empty string lists the most recent ones
     *
     * @return list<array{uuid: string, title: string, template: null|string, content: array<string, mixed>}>
     */
    public function __invoke(string $template = '', string $query = ''): array
    {
        return $this->snippetFinder->search($query, $this->scope->getLocale(), $template === '' ? [] : [$template]);
    }
}
