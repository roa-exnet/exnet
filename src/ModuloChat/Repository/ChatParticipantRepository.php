<?php

namespace App\ModuloChat\Repository;

use App\ModuloChat\Entity\ChatParticipant;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<ChatParticipant>
 */
class ChatParticipantRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ChatParticipant::class);
    }

    /**
     * Encuentra todos los chats en los que participa un usuario
     */
    public function findChatsByUser(string $userId): array
    {
        return $this->createQueryBuilder('p')
            ->where('p.participantIdentifier = :userId')
            ->andWhere('p.isActive = :active')
            ->setParameter('userId', $userId)
            ->setParameter('active', true)
            ->getQuery()
            ->getResult();
    }

    /**
     * Encuentra los participantes activos de un chat
     */
    public function findActiveParticipants(string $chatId): array
    {
        return $this->createQueryBuilder('p')
            ->where('p.chat = :chatId')
            ->andWhere('p.isActive = :active')
            ->setParameter('chatId', $chatId)
            ->setParameter('active', true)
            ->getQuery()
            ->getResult();
    }

    /**
     * Verifica si un usuario es participante de un chat
     */
    public function isParticipant(string $chatId, string $userId): bool
    {
        $count = $this->createQueryBuilder('p')
            ->select('COUNT(p.id)')
            ->where('p.chat = :chatId')
            ->andWhere('p.participantIdentifier = :userId')
            ->andWhere('p.isActive = :active')
            ->setParameter('chatId', $chatId)
            ->setParameter('userId', $userId)
            ->setParameter('active', true)
            ->getQuery()
            ->getSingleScalarResult();
        
        return $count > 0;
    }

    /**
     * Encuentra el participante por chat y usuario
     */
    public function findParticipant(string $chatId, string $userId): ?ChatParticipant
    {
        return $this->createQueryBuilder('p')
            ->where('p.chat = :chatId')
            ->andWhere('p.participantIdentifier = :userId')
            ->setParameter('chatId', $chatId)
            ->setParameter('userId', $userId)
            ->getQuery()
            ->getOneOrNullResult();
    }

    /**
     * Verifica si un usuario es administrador de un chat
     */
    public function isAdmin(string $chatId, string $userId): bool
    {
        $count = $this->createQueryBuilder('p')
            ->select('COUNT(p.id)')
            ->where('p.chat = :chatId')
            ->andWhere('p.participantIdentifier = :userId')
            ->andWhere('p.isActive = :active')
            ->andWhere('p.role IN (:roles)')
            ->setParameter('chatId', $chatId)
            ->setParameter('userId', $userId)
            ->setParameter('active', true)
            ->setParameter('roles', ['admin', 'creator'])
            ->getQuery()
            ->getSingleScalarResult();
        
        return $count > 0;
    }
}