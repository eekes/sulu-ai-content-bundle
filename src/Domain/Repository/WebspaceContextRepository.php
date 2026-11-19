<?php

declare(strict_types=1);

namespace Eekes\Sulu\AiContentBundle\Domain\Repository;

use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use Eekes\Sulu\AiContentBundle\Domain\Entity\WebspaceContext;

/**
 * @extends ServiceEntityRepository<WebspaceContext>
 */
class WebspaceContextRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, WebspaceContext::class);
    }

    public function findOneByWebspace(string $webspaceKey, string $locale): ?WebspaceContext
    {
        return $this->findOneBy(['webspaceKey' => $webspaceKey, 'locale' => $locale]);
    }

    public function save(WebspaceContext $context): void
    {
        $this->getEntityManager()->persist($context);
        $this->getEntityManager()->flush();
    }
}
