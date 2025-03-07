<?php

namespace App\ModuloChat\Service;

use App\ModuloChat\Entity\Chat;
use App\ModuloChat\Entity\ChatParticipant;
use App\ModuloChat\Repository\ChatParticipantRepository;
use App\ModuloChat\Repository\ChatRepository;
use Doctrine\ORM\EntityManagerInterface;

class ChatService
{
    private EntityManagerInterface $entityManager;
    private ChatRepository $chatRepository;
    private ChatParticipantRepository $participantRepository;

    public function __construct(
        EntityManagerInterface $entityManager,
        ChatRepository $chatRepository,
        ChatParticipantRepository $participantRepository
    ) {
        $this->entityManager = $entityManager;
        $this->chatRepository = $chatRepository;
        $this->participantRepository = $participantRepository;
    }

    public function getOrCreatePrivateChat(
        string $participant1Id, 
        string $participant1Name, 
        string $participant2Id, 
        string $participant2Name
    ): Chat {
        $chat = $this->chatRepository->findOrCreatePrivateChat($participant1Id, $participant2Id);
        
        if (count($chat->getParticipants()) === 0) {
            $participant1 = new ChatParticipant();
            $participant1->setChat($chat)
                ->setParticipantIdentifier($participant1Id)
                ->setParticipantName($participant1Name);
            
            $participant2 = new ChatParticipant();
            $participant2->setChat($chat)
                ->setParticipantIdentifier($participant2Id)
                ->setParticipantName($participant2Name);
            
            $this->entityManager->persist($participant1);
            $this->entityManager->persist($participant2);
            $this->entityManager->flush();
        }
        
        return $chat;
    }

    public function addParticipant(Chat $chat, string $participantId, string $participantName, string $role = 'member'): ChatParticipant
    {
        $existingParticipant = $this->participantRepository->findParticipant($chat->getId(), $participantId);
        
        if ($existingParticipant) {
            if (!$existingParticipant->isIsActive()) {
                $existingParticipant->setIsActive(true)
                    ->setLeftAt(null)
                    ->setJoinedAt(new \DateTimeImmutable());
                
                $this->entityManager->flush();
                return $existingParticipant;
            }
            
            return $existingParticipant;
        }
        
        $participant = new ChatParticipant();
        $participant->setChat($chat)
            ->setParticipantIdentifier($participantId)
            ->setParticipantName($participantName)
            ->setRole($role);
        
        $this->entityManager->persist($participant);
        $this->entityManager->flush();
        
        return $participant;
    }

    public function removeParticipant(Chat $chat, string $participantId): bool
    {
        $participant = $this->participantRepository->findParticipant($chat->getId(), $participantId);
        
        if (!$participant) {
            return false;
        }
        
        $participant->leave();
        $this->entityManager->flush();
        
        $activeParticipants = $this->participantRepository->findActiveByChatId($chat->getId());
        
        if (count($activeParticipants) === 0) {
            $chat->close();
            $this->entityManager->flush();
        }
        
        return true;
    }

    public function getActiveChatsForParticipant(string $participantId): array
    {
        return $this->chatRepository->findActiveChatsForParticipant($participantId);
    }

    public function closeChat(Chat $chat): void
    {
        $chat->close();
        
        foreach ($chat->getParticipants() as $participant) {
            if ($participant->isIsActive()) {
                $participant->leave();
            }
        }
        
        $this->entityManager->flush();
    }
}
