<?php

namespace App\ModuloChat\Repository;

use App\ModuloChat\Entity\Chat;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

class ChatRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Chat::class);
    }

    public function findActiveChatsForParticipant(string $participantIdentifier): array
    {
        return $this->createQueryBuilder('c')
            ->join('c.participants', 'p')
            ->where('c.isActive = :active')
            ->andWhere('p.participantIdentifier = :participantId')
            ->andWhere('p.isActive = :participantActive')
            ->setParameter('active', true)
            ->setParameter('participantId', $participantIdentifier)
            ->setParameter('participantActive', true)
            ->orderBy('c.createdAt', 'DESC')
            ->getQuery()
            ->getResult();
    }

    public function findOrCreatePrivateChat(string $participant1, string $participant2): Chat
    {
        $entityManager = $this->getEntityManager();
        
        $existingChat = $this->createQueryBuilder('c')
            ->join('c.participants', 'p1')
            ->join('c.participants', 'p2')
            ->where('c.type = :type')
            ->andWhere('p1.participantIdentifier = :id1')
            ->andWhere('p2.participantIdentifier = :id2')
            ->andWhere('c.isActive = :active')
            ->setParameter('type', 'private')
            ->setParameter('id1', $participant1)
            ->setParameter('id2', $participant2)
            ->setParameter('active', true)
            ->getQuery()
            ->getResult();
        
        if (!empty($existingChat)) {
            return $existingChat[0];
        }
        
        $chat = new Chat();
        $chat->setName('Private Chat')
            ->setType('private');
        
        $entityManager->persist($chat);
        $entityManager->flush();
        
        return $chat;
    }
}