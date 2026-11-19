<?php

declare(strict_types=1);

namespace Eekes\Sulu\AiContentBundle\Application\Assistant\Tool;

use Eekes\Sulu\AiContentBundle\Application\Assistant\AssistantScope;
use Eekes\Sulu\AiContentBundle\Application\Content\MediaFinderInterface;
use Symfony\AI\Agent\Toolbox\Attribute\AsTool;

#[AsTool('search_media', 'Finds images and files in the media library whose title contains the given words. Returns the id, title, description and type of at most 10 items.')]
final class SearchMediaTool
{
    public function __construct(
        private readonly MediaFinderInterface $mediaFinder,
        private readonly AssistantScope $scope,
    ) {
    }

    /**
     * @param string $query One or more words that describe the image
     *
     * @return list<array{id: int, title: string, description: null|string, mimeType: null|string}>
     */
    public function __invoke(string $query): array
    {
        return $this->mediaFinder->search($query, $this->scope->getLocale());
    }
}
