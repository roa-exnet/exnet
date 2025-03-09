<?php

namespace App\ModuloChat\Repository;

use App\ModuloChat\Entity\ChatMessage;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<ChatMessage>
 */
class ChatMessageRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ChatMessage::class);
    }

    /**
     * Encuentra los mensajes no leídos de un chat para un usuario específico
     */
    public function findUnreadMessagesForUser(string $chatId, string $userId): array
    {
        return $this->createQueryBuilder('m')
            ->where('m.chat = :chatId')
            ->andWhere('m.senderIdentifier != :userId')
            ->andWhere('m.readAt IS NULL')
            ->setParameter('chatId', $chatId)
            ->setParameter('userId', $userId)
            ->orderBy('m.sentAt', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Cuenta los mensajes no leídos de un chat para un usuario específico
     */
    public function countUnreadMessagesForUser(string $chatId, string $userId): int
    {
        return $this->createQueryBuilder('m')
            ->select('COUNT(m.id)')
            ->where('m.chat = :chatId')
            ->andWhere('m.senderIdentifier != :userId')
            ->andWhere('m.readAt IS NULL')
            ->setParameter('chatId', $chatId)
            ->setParameter('userId', $userId)
            ->getQuery()
            ->getSingleScalarResult();
    }

    /**
     * Encuentra los últimos mensajes de cada chat para un usuario específico
     */
    public function findLatestMessagesByChats(array $chatIds): array
    {
        $qb = $this->createQueryBuilder('m');
        
        $subQuery = $this->_em->createQueryBuilder()
            ->select('MAX(m2.id)')
            ->from(ChatMessage::class, 'm2')
            ->where('m2.chat = m.chat')
            ->groupBy('m2.chat');
        
        return $qb->where('m.id IN (' . $subQuery->getDQL() . ')')
            ->andWhere($qb->expr()->in('m.chat', ':chatIds'))
            ->setParameter('chatIds', $chatIds)
            ->getQuery()
            ->getResult();
    }

    /**
     * Marca todos los mensajes de un chat como leídos para un usuario específico
     */
    public function markAllAsRead(string $chatId, string $userId): int
    {
        return $this->createQueryBuilder('m')
            ->update()
            ->set('m.readAt', ':now')
            ->where('m.chat = :chatId')
            ->andWhere('m.senderIdentifier != :userId')
            ->andWhere('m.readAt IS NULL')
            ->setParameter('chatId', $chatId)
            ->setParameter('userId', $userId)
            ->setParameter('now', new \DateTimeImmutable())
            ->getQuery()
            ->execute();
    }

    /**
     * Encuentra los mensajes de un chat con paginación
     */
    public function findMessagesWithPagination(string $chatId, int $page = 1, int $limit = 20): array
    {
        return $this->createQueryBuilder('m')
            ->where('m.chat = :chatId')
            ->setParameter('chatId', $chatId)
            ->orderBy('m.sentAt', 'DESC')
            ->setFirstResult(($page - 1) * $limit)
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }
}