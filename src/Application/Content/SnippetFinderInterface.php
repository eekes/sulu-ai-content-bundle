<?php

declare(strict_types=1);

namespace Eekes\Sulu\AiContentBundle\Application\Content;

/**
 * Lets the assistant reuse snippets - FAQ items, testimonials, team members - before it proposes
 * new ones.
 */
interface SnippetFinderInterface
{
    /**
     * Snippets whose title contains the query, limited to the given templates when there are any.
     *
     * @param list<string> $templateKeys
     *
     * @return list<array{uuid: string, title: string, template: null|string, content: array<string, mixed>}>
     */
    public function search(string $query, string $locale, array $templateKeys = [], int $limit = 15): array;

    /**
     * The template of every snippet of the given list that exists, by uuid.
     *
     * @param list<string> $uuids
     *
     * @return array<string, null|string>
     */
    public function templates(array $uuids): array;
}
