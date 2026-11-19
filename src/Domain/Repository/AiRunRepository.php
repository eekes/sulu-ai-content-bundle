<?php

declare(strict_types=1);

namespace Eekes\Sulu\AiContentBundle\Domain\Repository;

use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use Eekes\Sulu\AiContentBundle\Domain\Entity\AiRun;

/**
 * @extends ServiceEntityRepository<AiRun>
 */
class AiRunRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, AiRun::class);
    }

    public function findById(string $id): ?AiRun
    {
        return $this->find($id);
    }

    /**
     * @param list<string> $ids
     *
     * @return array<string, AiRun> by id
     */
    public function findByIds(array $ids): array
    {
        $runs = [];

        foreach ($ids === [] ? [] : $this->findBy(['id' => $ids]) as $run) {
            $runs[$run->id] = $run;
        }

        return $runs;
    }

    /**
     * The estimated cost of every run since the given moment, in millionths of the currency.
     * Runs without a known price count as 0.
     */
    public function sumCostMicrosSince(\DateTimeImmutable $since, ?string $webspaceKey = null): int
    {
        $queryBuilder = $this->createQueryBuilder('run')
            ->select('COALESCE(SUM(run.cost), 0)')
            ->where('run.createdAt >= :since')
            ->setParameter('since', $since);

        if ($webspaceKey !== null) {
            $queryBuilder->andWhere('run.webspaceKey = :webspaceKey')->setParameter('webspaceKey', $webspaceKey);
        }

        $sum = $queryBuilder->getQuery()->getSingleScalarResult();

        return is_numeric($sum) ? (int) round((float) $sum * 1_000_000) : 0;
    }

    /**
     * Totals per action since the given moment, for the usage summary.
     *
     * @return list<array{action: string, runs: int, totalTokens: int, costMicros: int}>
     */
    public function totalsByActionSince(\DateTimeImmutable $since): array
    {
        /** @var list<array{action: string, runs: int|string, totalTokens: null|int|string, cost: null|float|string}> $rows */
        $rows = $this->createQueryBuilder('run')
            ->select('run.action AS action', 'COUNT(run.id) AS runs', 'SUM(run.totalTokens) AS totalTokens', 'SUM(run.cost) AS cost')
            ->where('run.createdAt >= :since')
            ->setParameter('since', $since)
            ->groupBy('run.action')
            ->orderBy('action')
            ->getQuery()
            ->getArrayResult();

        return array_map(static fn (array $row): array => [
            'action' => $row['action'],
            'runs' => (int) $row['runs'],
            'totalTokens' => (int) $row['totalTokens'],
            'costMicros' => (int) round((float) $row['cost'] * 1_000_000),
        ], $rows);
    }

    public function save(AiRun $run): void
    {
        $this->getEntityManager()->persist($run);
        $this->getEntityManager()->flush();
    }
}
