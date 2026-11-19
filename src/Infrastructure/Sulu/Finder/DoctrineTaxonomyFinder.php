<?php

declare(strict_types=1);

namespace Eekes\Sulu\AiContentBundle\Infrastructure\Sulu\Finder;

use Doctrine\ORM\EntityManagerInterface;
use Eekes\Sulu\AiContentBundle\Application\Content\TaxonomyFinderInterface;

/**
 * Categories and tags straight from Sulu's tables, through the entity classes the project
 * configured (sulu.model.category.class and friends).
 */
final class DoctrineTaxonomyFinder implements TaxonomyFinderInterface
{
    /**
     * @param class-string $categoryClass
     * @param class-string $categoryTranslationClass
     * @param class-string $tagClass
     */
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly string $categoryClass,
        private readonly string $categoryTranslationClass,
        private readonly string $tagClass,
    ) {
    }

    public function searchCategories(string $query, string $locale, int $limit = 30): array
    {
        $builder = $this->entityManager->createQueryBuilder()
            ->select('category.id AS id', 'category.key AS key', 'translation.translation AS name', 'fallback.translation AS fallbackName', 'parentTranslation.translation AS parent')
            ->from($this->categoryClass, 'category')
            ->leftJoin($this->categoryTranslationClass, 'translation', 'WITH', 'translation.category = category AND translation.locale = :locale')
            ->leftJoin($this->categoryTranslationClass, 'fallback', 'WITH', 'fallback.category = category AND fallback.locale = category.defaultLocale')
            ->leftJoin('category.parent', 'parentCategory')
            ->leftJoin($this->categoryTranslationClass, 'parentTranslation', 'WITH', 'parentTranslation.category = parentCategory AND parentTranslation.locale = :locale')
            ->setParameter('locale', $locale)
            ->orderBy('category.lft', 'ASC')
            ->setMaxResults($limit);

        if (trim($query) !== '') {
            $builder->andWhere('translation.translation LIKE :query OR fallback.translation LIKE :query OR category.key LIKE :query')
                ->setParameter('query', '%'.addcslashes(trim($query), '%_').'%');
        }

        $categories = [];

        /** @var array{id: int|string, key: null|string, name: null|string, fallbackName: null|string, parent: null|string} $row */
        foreach ($builder->getQuery()->getArrayResult() as $row) {
            $categories[] = [
                'id' => (int) $row['id'],
                'name' => $row['name'] ?? $row['fallbackName'] ?? $row['key'] ?? '',
                'key' => $row['key'],
                'parent' => $row['parent'],
            ];
        }

        return $categories;
    }

    public function searchTags(string $query, int $limit = 30): array
    {
        $builder = $this->entityManager->createQueryBuilder()
            ->select('tag.id AS id', 'tag.name AS name')
            ->from($this->tagClass, 'tag')
            ->orderBy('tag.name', 'ASC')
            ->setMaxResults($limit);

        if (trim($query) !== '') {
            $builder->andWhere('tag.name LIKE :query')->setParameter('query', '%'.addcslashes(trim($query), '%_').'%');
        }

        $tags = [];

        /** @var array{id: int|string, name: string} $row */
        foreach ($builder->getQuery()->getArrayResult() as $row) {
            $tags[] = ['id' => (int) $row['id'], 'name' => $row['name']];
        }

        return $tags;
    }

    public function existingCategories(array $ids): array
    {
        return $this->existing($this->categoryClass, $ids);
    }

    public function existingTags(array $ids): array
    {
        return $this->existing($this->tagClass, $ids);
    }

    /**
     * @param class-string $class
     * @param list<int> $ids
     *
     * @return list<int>
     */
    private function existing(string $class, array $ids): array
    {
        if ($ids === []) {
            return [];
        }

        $rows = $this->entityManager->createQueryBuilder()
            ->select('entity.id AS id')
            ->from($class, 'entity')
            ->where('entity.id IN (:ids)')
            ->setParameter('ids', $ids)
            ->getQuery()
            ->getSingleColumnResult();

        return array_values(array_map(static fn (mixed $id) => \is_numeric($id) ? (int) $id : 0, $rows));
    }
}
