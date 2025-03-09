<?php

namespace App\ModuloChat\Repository;

use App\ModuloChat\Entity\Chat;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Chat>
 */
class ChatRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Chat::class);
    }

    /**
     * Encuentra chats activos por usuario participante
     */
    public function findActiveByParticipant(string $userId): array
    {
        return $this->createQueryBuilder('c')
            ->innerJoin('c.participants', 'p')
            ->where('p.participantIdentifier = :userId')
            ->andWhere('p.isActive = :active')
            ->andWhere('c.isActive = :active')
            ->setParameter('userId', $userId)
            ->setParameter('active', true)
            ->orderBy('c.createdAt', 'DESC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Encuentra chats con mensajes no leídos para un usuario
     */
    public function findWithUnreadMessages(string $userId): array
    {
        return $this->createQueryBuilder('c')
            ->innerJoin('c.participants', 'p')
            ->innerJoin('c.messages', 'm')
            ->where('p.participantIdentifier = :userId')
            ->andWhere('p.isActive = :active')
            ->andWhere('c.isActive = :active')
            ->andWhere('m.senderIdentifier != :userId')
            ->andWhere('m.readAt IS NULL')
            ->setParameter('userId', $userId)
            ->setParameter('active', true)
            ->distinct()
            ->orderBy('c.createdAt', 'DESC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Encuentra los chats más recientes para un usuario
     */
    public function findRecentByParticipant(string $userId, int $limit = 5): array
    {
        return $this->createQueryBuilder('c')
            ->innerJoin('c.participants', 'p')
            ->leftJoin('c.messages', 'm')
            ->where('p.participantIdentifier = :userId')
            ->andWhere('p.isActive = :active')
            ->andWhere('c.isActive = :active')
            ->setParameter('userId', $userId)
            ->setParameter('active', true)
            ->orderBy('m.sentAt', 'DESC')
            ->groupBy('c.id')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }

    /**
     * Busca chats por nombre o participante
     */
    public function search(string $term, ?string $userId = null): array
    {
        $qb = $this->createQueryBuilder('c')
            ->where('c.name LIKE :term')
            ->setParameter('term', '%' . $term . '%')
            ->andWhere('c.isActive = :active')
            ->setParameter('active', true);

        if ($userId) {
            $qb->innerJoin('c.participants', 'p')
                ->andWhere('p.participantIdentifier = :userId')
                ->andWhere('p.isActive = :active')
                ->setParameter('userId', $userId);
        }

        return $qb->orderBy('c.createdAt', 'DESC')
            ->getQuery()
            ->getResult();
    }
}