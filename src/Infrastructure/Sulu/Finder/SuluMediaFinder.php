<?php

declare(strict_types=1);

namespace Eekes\Sulu\AiContentBundle\Infrastructure\Sulu\Finder;

use Eekes\Sulu\AiContentBundle\Application\Content\MediaFinderInterface;
use Sulu\Bundle\MediaBundle\Entity\MediaRepositoryInterface;
use Sulu\Bundle\MediaBundle\Media\Manager\MediaManagerInterface;

/**
 * Searches the media library through Sulu's media manager, the same search the media overlay of
 * the admin uses.
 */
final class SuluMediaFinder implements MediaFinderInterface
{
    public function __construct(
        private readonly MediaManagerInterface $mediaManager,
        private readonly MediaRepositoryInterface $mediaRepository,
    ) {
    }

    public function search(string $query, string $locale, int $limit = 10): array
    {
        $filter = trim($query) === '' ? [] : ['search' => trim($query)];
        $results = [];

        foreach ($this->mediaManager->get($locale, $filter, $limit) as $media) {
            $id = $media->getId();
            $title = $media->getTitle();
            $description = $media->getDescription();
            $mimeType = $media->getMimeType();

            $results[] = [
                'id' => $id,
                'title' => \is_string($title) ? $title : '',
                'description' => \is_string($description) && $description !== '' ? $description : null,
                'mimeType' => \is_string($mimeType) ? $mimeType : null,
            ];
        }

        return $results;
    }

    public function existing(array $ids): array
    {
        if ($ids === []) {
            return [];
        }

        $existing = [];

        foreach ($this->mediaRepository->findBy(['id' => array_values(array_unique($ids))]) as $media) {
            $existing[] = $media->getId();
        }

        return array_values(array_filter($existing, 'is_int'));
    }
}
