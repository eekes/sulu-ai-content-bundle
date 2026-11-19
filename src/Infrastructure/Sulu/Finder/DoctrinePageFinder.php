<?php

declare(strict_types=1);

namespace Eekes\Sulu\AiContentBundle\Infrastructure\Sulu\Finder;

use Doctrine\ORM\Query\Expr\Join;
use Doctrine\ORM\QueryBuilder;
use Doctrine\Persistence\ManagerRegistry;
use Eekes\Sulu\AiContentBundle\Application\Assistant\AssistantScope;
use Eekes\Sulu\AiContentBundle\Application\Content\PageFinderInterface;
use Sulu\Content\Domain\Model\DimensionContentInterface;
use Sulu\Page\Domain\Model\PageDimensionContentInterface;
use Sulu\Page\Domain\Model\PageInterface;
use Sulu\Route\Domain\Model\Route;

/**
 * Reads pages from Sulu 3's content tables: the draft of each page in the requested locale, so
 * the assistant also knows pages that are not published yet.
 *
 * What it reads of a page - its title in a list, its content - is only what the editor of the run
 * may view in the admin. Whether a page exists and its URL are not: they check what the model
 * sends back, a link the editor could also pick.
 */
final class DoctrinePageFinder implements PageFinderInterface
{
    public function __construct(
        private readonly ManagerRegistry $registry,
        private readonly PageAccess $access,
        private readonly AssistantScope $scope,
    ) {
    }

    /**
     * @param class-string $class
     */
    private function queryBuilder(string $class, string $alias): QueryBuilder
    {
        return $this->registry->getRepository($class)->createQueryBuilder($alias);
    }

    public function search(string $query, string $locale, ?string $webspaceKey, int $limit = 10): array
    {
        $userId = $this->scope->getUserId();

        if ($webspaceKey !== null && !$this->access->canView($userId, $webspaceKey, $locale)) {
            return [];
        }

        $queryBuilder = $this->queryBuilder(PageDimensionContentInterface::class, 'content')
            ->select('page.uuid AS uuid', 'content.title AS title', 'content.templateKey AS template', 'page.webspaceKey AS webspaceKey')
            ->innerJoin('content.page', 'page')
            ->where('content.locale = :locale')
            ->andWhere('content.stage = :stage')
            ->setParameter('locale', $locale)
            ->setParameter('stage', DimensionContentInterface::STAGE_DRAFT)
            ->orderBy('content.id', 'DESC')
            ->setMaxResults($limit);

        if (trim($query) !== '') {
            $queryBuilder->andWhere('LOWER(content.title) LIKE :query')->setParameter('query', '%'.mb_strtolower(trim($query)).'%');
        }

        if ($webspaceKey !== null) {
            $queryBuilder->andWhere('page.webspaceKey = :webspaceKey')->setParameter('webspaceKey', $webspaceKey);
        }

        $this->access->restrict($queryBuilder, 'page', $userId);

        /** @var list<array{uuid: string, title: null|string, template: null|string, webspaceKey: string}> $rows */
        $rows = $queryBuilder->getQuery()->getArrayResult();

        if ($webspaceKey === null) {
            $rows = array_values(array_filter($rows, fn (array $row) => $this->access->canView($userId, $row['webspaceKey'], $locale)));
        }

        return array_map(static fn (array $row) => ['uuid' => $row['uuid'], 'title' => $row['title'] ?? '', 'template' => $row['template']], $rows);
    }

    public function tree(string $locale, string $webspaceKey, int $limit = 150): array
    {
        $userId = $this->scope->getUserId();

        if (!$this->access->canView($userId, $webspaceKey, $locale)) {
            return [];
        }

        $queryBuilder = $this->queryBuilder(PageDimensionContentInterface::class, 'content')
            ->select('page.uuid AS uuid', 'content.title AS title', 'content.templateKey AS template', 'route.slug AS url', 'page.depth AS depth')
            ->innerJoin('content.page', 'page')
            ->leftJoin(Route::class, 'route', Join::WITH, 'route.resourceKey = :resourceKey AND route.resourceId = page.uuid AND route.locale = content.locale')
            ->where('content.locale = :locale')
            ->andWhere('content.stage = :stage')
            ->andWhere('page.webspaceKey = :webspaceKey')
            ->setParameter('resourceKey', PageInterface::RESOURCE_KEY)
            ->setParameter('locale', $locale)
            ->setParameter('stage', DimensionContentInterface::STAGE_DRAFT)
            ->setParameter('webspaceKey', $webspaceKey)
            ->orderBy('page.lft', 'ASC')
            ->setMaxResults($limit);
        $this->access->restrict($queryBuilder, 'page', $userId);

        /** @var list<array{uuid: string, title: null|string, template: null|string, url: null|string, depth: int|string}> $rows */
        $rows = $queryBuilder->getQuery()->getArrayResult();

        $holds = $this->childResources($locale);
        $pages = [];

        foreach ($rows as $row) {
            // a page with more than one route in the locale is still one page
            $pages[$row['uuid']] ??= [
                'uuid' => $row['uuid'],
                'title' => $row['title'] ?? '',
                'template' => $row['template'],
                'url' => $row['url'],
                'depth' => (int) $row['depth'],
                'holds' => $holds[$row['uuid']] ?? [],
            ];
        }

        return array_values($pages);
    }

    public function url(string $uuid, string $locale): ?string
    {
        /** @var list<array{slug: string}> $rows */
        $rows = $this->queryBuilder(Route::class, 'route')
            ->select('route.slug AS slug')
            ->where('route.resourceKey = :resourceKey')
            ->andWhere('route.resourceId = :uuid')
            ->andWhere('route.locale = :locale')
            ->setParameter('resourceKey', PageInterface::RESOURCE_KEY)
            ->setParameter('uuid', $uuid)
            ->setParameter('locale', $locale)
            ->setMaxResults(1)
            ->getQuery()
            ->getArrayResult();

        return $rows[0]['slug'] ?? null;
    }

    /**
     * What has its URL below each page (a page_tree_route), counted by resource key.
     *
     * @return array<string, array<string, int>>
     */
    private function childResources(string $locale): array
    {
        /** @var list<array{uuid: string, resourceKey: string, children: int|string}> $rows */
        $rows = $this->queryBuilder(Route::class, 'child')
            ->select('parent.resourceId AS uuid', 'child.resourceKey AS resourceKey', 'COUNT(child.id) AS children')
            ->innerJoin('child.parentRoute', 'parent')
            ->where('parent.resourceKey = :resourceKey')
            ->andWhere('child.resourceKey <> :resourceKey')
            ->andWhere('child.locale = :locale')
            ->setParameter('resourceKey', PageInterface::RESOURCE_KEY)
            ->setParameter('locale', $locale)
            ->groupBy('parent.resourceId', 'child.resourceKey')
            ->getQuery()
            ->getArrayResult();

        $holds = [];

        foreach ($rows as $row) {
            $holds[$row['uuid']][$row['resourceKey']] = (int) $row['children'];
        }

        return $holds;
    }

    public function get(string $uuid, string $locale): ?array
    {
        $userId = $this->scope->getUserId();
        $queryBuilder = $this->queryBuilder(PageDimensionContentInterface::class, 'content')
            ->innerJoin('content.page', 'page')
            ->where('page.uuid = :uuid')
            ->andWhere('content.locale = :locale')
            ->andWhere('content.stage = :stage')
            ->setParameter('uuid', $uuid)
            ->setParameter('locale', $locale)
            ->setParameter('stage', DimensionContentInterface::STAGE_DRAFT)
            ->setMaxResults(1);
        $this->access->restrict($queryBuilder, 'page', $userId);
        $content = $queryBuilder->getQuery()->getOneOrNullResult();

        if (!$content instanceof PageDimensionContentInterface || !$this->access->canView($userId, $content->getResource()->getWebspaceKey(), $locale)) {
            return null;
        }

        $data = [];

        foreach ($content->getTemplateData() as $key => $value) {
            $data[(string) $key] = $value;
        }

        return [
            'uuid' => $uuid,
            'title' => $content->getTitle() ?? '',
            'template' => $content->getTemplateKey(),
            'content' => $data,
        ];
    }

    public function existing(array $uuids): array
    {
        if ($uuids === []) {
            return [];
        }

        /** @var list<array{uuid: string}> $rows */
        $rows = $this->queryBuilder(PageInterface::class, 'page')
            ->select('page.uuid AS uuid')
            ->where('page.uuid IN (:uuids)')
            ->setParameter('uuids', array_values(array_unique($uuids)))
            ->getQuery()
            ->getArrayResult();

        return array_column($rows, 'uuid');
    }
}
