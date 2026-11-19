<?php

declare(strict_types=1);

namespace Eekes\Sulu\AiContentBundle\Infrastructure\Sulu\Finder;

use Eekes\Sulu\AiContentBundle\Application\Assistant\AssistantScope;
use Eekes\Sulu\AiContentBundle\Application\Content\MediaFinderInterface;
use Sulu\Bundle\MediaBundle\Admin\MediaAdmin;
use Sulu\Bundle\MediaBundle\Api\Media;
use Sulu\Bundle\MediaBundle\Entity\MediaRepositoryInterface;

/**
 * Searches the media library the way the media overlay of the admin does, as the editor of the
 * run: only with the view permission on media, and not in a collection with permissions of its
 * own that none of the editor's roles may view. Whether a media id exists is not filtered: it
 * checks what the model sends back, an image the editor could also pick.
 */
final class SuluMediaFinder implements MediaFinderInterface
{
    /**
     * Sulu applies the limit before the collection permissions: ask for more, keep the first ones.
     */
    private const int OVERFETCH = 5;

    public function __construct(
        private readonly MediaRepositoryInterface $mediaRepository,
        private readonly SuluPermissions $permissions,
        private readonly AssistantScope $scope,
    ) {
    }

    public function search(string $query, string $locale, int $limit = 10): array
    {
        $userId = $this->scope->getUserId();

        if (!$this->permissions->canView($userId, MediaAdmin::SECURITY_CONTEXT)) {
            return [];
        }

        $this->permissions->useAdminSystem();
        $filter = trim($query) === '' ? [] : ['search' => trim($query)];
        $entities = $this->mediaRepository->findMedia($filter, $limit * self::OVERFETCH, null, $this->permissions->user($userId), $this->permissions->viewMask());
        $results = [];

        foreach (\array_slice(array_values($entities), 0, $limit) as $entity) {
            $media = new Media($entity, $locale);
            $title = $media->getTitle();
            $description = $media->getDescription();
            $mimeType = $media->getMimeType();

            $results[] = [
                'id' => (int) $media->getId(),
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
