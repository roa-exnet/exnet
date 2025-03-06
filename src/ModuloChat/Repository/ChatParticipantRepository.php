<?php

namespace App\ModuloChat\Repository;

use App\ModuloChat\Entity\ChatParticipant;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

class ChatParticipantRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ChatParticipant::class);
    }

    public function findActiveByChatId(int $chatId): array
    {
        return $this->createQueryBuilder('p')
            ->andWhere('p.chat = :chatId')
            ->andWhere('p.isActive = :active')
            ->setParameter('chatId', $chatId)
            ->setParameter('active', true)
            ->getQuery()
            ->getResult();
    }

    public function findParticipant(int $chatId, string $participantIdentifier): ?ChatParticipant
    {
        return $this->createQueryBuilder('p')
            ->andWhere('p.chat = :chatId')
            ->andWhere('p.participantIdentifier = :identifier')
            ->setParameter('chatId', $chatId)
            ->setParameter('identifier', $participantIdentifier)
            ->getQuery()
            ->getOneOrNullResult();
    }
}