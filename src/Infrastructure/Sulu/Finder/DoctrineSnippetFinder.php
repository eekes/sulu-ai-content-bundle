<?php

declare(strict_types=1);

namespace Eekes\Sulu\AiContentBundle\Infrastructure\Sulu\Finder;

use Doctrine\ORM\QueryBuilder;
use Doctrine\Persistence\ManagerRegistry;
use Eekes\Sulu\AiContentBundle\Application\Content\SnippetFinderInterface;
use Sulu\Content\Domain\Model\DimensionContentInterface;
use Sulu\Snippet\Domain\Model\SnippetDimensionContentInterface;

/**
 * Reads snippets from Sulu 3's content tables: the draft in the requested locale, so snippets
 * that are not published yet can be reused too.
 */
final class DoctrineSnippetFinder implements SnippetFinderInterface
{
    /**
     * Long snippets are cut off: the result goes into every following request of the run.
     */
    private const int MAX_TEXT = 300;

    public function __construct(
        private readonly ManagerRegistry $registry,
    ) {
    }

    public function search(string $query, string $locale, array $templateKeys = [], int $limit = 15): array
    {
        $queryBuilder = $this->queryBuilder()
            ->select('snippet.uuid AS uuid', 'content.title AS title', 'content.templateKey AS template', 'content.templateData AS data')
            ->where('content.locale = :locale')
            ->andWhere('content.stage = :stage')
            ->setParameter('locale', $locale)
            ->setParameter('stage', DimensionContentInterface::STAGE_DRAFT)
            ->orderBy('content.id', 'DESC')
            ->setMaxResults($limit);

        if (trim($query) !== '') {
            $queryBuilder->andWhere('LOWER(content.title) LIKE :query')->setParameter('query', '%'.mb_strtolower(trim($query)).'%');
        }

        if ($templateKeys !== []) {
            $queryBuilder->andWhere('content.templateKey IN (:templateKeys)')->setParameter('templateKeys', $templateKeys);
        }

        /** @var list<array{uuid: string, title: null|string, template: null|string, data: null|array<mixed>}> $rows */
        $rows = $queryBuilder->getQuery()->getArrayResult();

        return array_map(static fn (array $row) => [
            'uuid' => $row['uuid'],
            'title' => $row['title'] ?? '',
            'template' => $row['template'],
            'content' => self::shorten($row['data'] ?? []),
        ], $rows);
    }

    public function templates(array $uuids): array
    {
        if ($uuids === []) {
            return [];
        }

        /** @var list<array{uuid: string, template: null|string}> $rows */
        $rows = $this->queryBuilder()
            ->select('snippet.uuid AS uuid', 'content.templateKey AS template')
            ->where('snippet.uuid IN (:uuids)')
            ->andWhere('content.templateKey IS NOT NULL')
            ->setParameter('uuids', array_values(array_unique($uuids)))
            ->getQuery()
            ->getArrayResult();

        return array_column($rows, 'template', 'uuid');
    }

    private function queryBuilder(): QueryBuilder
    {
        return $this->registry->getRepository(SnippetDimensionContentInterface::class)->createQueryBuilder('content')
            ->innerJoin('content.snippet', 'snippet');
    }

    /**
     * @param array<mixed> $data
     *
     * @return array<string, mixed>
     */
    private static function shorten(array $data): array
    {
        $short = [];

        foreach ($data as $key => $value) {
            if (\is_string($value)) {
                $text = trim(strip_tags($value));
                $value = mb_strlen($text) > self::MAX_TEXT ? mb_substr($text, 0, self::MAX_TEXT).'...' : $text;
            }

            $short[(string) $key] = $value;
        }

        return $short;
    }
}
