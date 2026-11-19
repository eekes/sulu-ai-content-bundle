<?php

declare(strict_types=1);

namespace Eekes\Sulu\AiContentBundle\Domain\Repository;

use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use Eekes\Sulu\AiContentBundle\Domain\Entity\Conversation;

/**
 * @extends ServiceEntityRepository<Conversation>
 */
class ConversationRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Conversation::class);
    }

    public function findById(string $id): ?Conversation
    {
        return $this->find($id);
    }

    /**
     * The conversations of one user on one page, article or snippet, newest first.
     *
     * @return list<Conversation>
     */
    public function findForResource(int $userId, string $formKey, string $resourceId, string $locale, int $limit = 20): array
    {
        return $this->findBy(
            ['userId' => $userId, 'formKey' => $formKey, 'resourceId' => $resourceId, 'locale' => $locale],
            ['updatedAt' => 'DESC'],
            $limit,
        );
    }

    public function save(Conversation $conversation): void
    {
        $this->getEntityManager()->persist($conversation);
        $this->getEntityManager()->flush();
    }
}
