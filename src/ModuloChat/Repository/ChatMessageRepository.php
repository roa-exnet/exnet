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
     * @return ChatMessage[] Returns an array of ChatMessage objects for a specific chat
     */
    public function findByChatId(int $chatId, int $limit = 50, int $offset = 0): array
    {
        return $this->createQueryBuilder('m')
            ->andWhere('m.chat = :chatId')
            ->setParameter('chatId', $chatId)
            ->orderBy('m.sentAt', 'DESC')
            ->setMaxResults($limit)
            ->setFirstResult($offset)
            ->getQuery()
            ->getResult();
    }

    /**
     * @return int Returns the number of unread messages for a participant in a chat
     */
    public function countUnreadMessages(int $chatId, string $participantIdentifier): int
    {
        return $this->createQueryBuilder('m')
            ->select('COUNT(m.id)')
            ->andWhere('m.chat = :chatId')
            ->andWhere('m.senderIdentifier != :sender')
            ->andWhere('m.readAt IS NULL')
            ->setParameter('chatId', $chatId)
            ->setParameter('sender', $participantIdentifier)
            ->getQuery()
            ->getSingleScalarResult();
    }

    /**
     * Mark all messages in a chat as read for a specific participant
     */
    public function markAllAsRead(int $chatId, string $participantIdentifier): int
    {
        $now = new \DateTimeImmutable();
        
        return $this->createQueryBuilder('m')
            ->update()
            ->set('m.readAt', ':now')
            ->where('m.chat = :chatId')
            ->andWhere('m.senderIdentifier != :sender')
            ->andWhere('m.readAt IS NULL')
            ->setParameter('now', $now)
            ->setParameter('chatId', $chatId)
            ->setParameter('sender', $participantIdentifier)
            ->getQuery()
            ->execute();
    }
}