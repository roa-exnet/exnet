<?php

namespace App\ModuloChat\Service;

use App\ModuloChat\Entity\Chat;
use App\ModuloChat\Entity\ChatMessage;
use App\ModuloChat\Repository\ChatMessageRepository;
use App\ModuloChat\Repository\ChatParticipantRepository;
use Doctrine\ORM\EntityManagerInterface;

class MessageService
{
    private EntityManagerInterface $entityManager;
    private ChatMessageRepository $messageRepository;
    private ChatParticipantRepository $participantRepository;

    public function __construct(
        EntityManagerInterface $entityManager,
        ChatMessageRepository $messageRepository,
        ChatParticipantRepository $participantRepository
    ) {
        $this->entityManager = $entityManager;
        $this->messageRepository = $messageRepository;
        $this->participantRepository = $participantRepository;
    }

    public function sendMessage(
        Chat $chat, 
        string $senderId, 
        string $senderName, 
        string $content, 
        string $messageType = 'text', 
        array $metadata = null
    ): ?ChatMessage {
        $participant = $this->participantRepository->findParticipant($chat->getId(), $senderId);
        
        if (!$participant || !$participant->isIsActive()) {
            return null;
        }
        
        $message = new ChatMessage();
        $message->setChat($chat)
            ->setSenderIdentifier($senderId)
            ->setSenderName($senderName)
            ->setContent($content)
            ->setMessageType($messageType)
            ->setMetadata($metadata);
        
        $this->entityManager->persist($message);
        $this->entityManager->flush();
        
        return $message;
    }

    public function getChatMessages(Chat $chat, int $limit = 50, int $offset = 0): array
    {
        return $this->messageRepository->findByChatId($chat->getId(), $limit, $offset);
    }

    public function markMessagesAsRead(Chat $chat, string $participantId): int
    {
        $participant = $this->participantRepository->findParticipant($chat->getId(), $participantId);
        
        if (!$participant || !$participant->isIsActive()) {
            return 0;
        }
        
        return $this->messageRepository->markAllAsRead($chat->getId(), $participantId);
    }

    public function countUnreadMessages(Chat $chat, string $participantId): int
    {
        $participant = $this->participantRepository->findParticipant($chat->getId(), $participantId);
        
        if (!$participant || !$participant->isIsActive()) {
            return 0;
        }
        
        return $this->messageRepository->countUnreadMessages($chat->getId(), $participantId);
    }

    public function sendSystemMessage(Chat $chat, string $content, array $metadata = null): ChatMessage
    {
        $message = new ChatMessage();
        $message->setChat($chat)
            ->setSenderIdentifier('system')
            ->setSenderName('System')
            ->setContent($content)
            ->setMessageType('system')
            ->setMetadata($metadata);
        
        $this->entityManager->persist($message);
        $this->entityManager->flush();
        
        return $message;
    }

    public function deleteMessage(ChatMessage $message, string $deletedBy): bool
    {
        $participant = $this->participantRepository->findParticipant($message->getChat()->getId(), $deletedBy);
        
        if (!$participant) {
            return false;
        }
        
        if ($message->getSenderIdentifier() === $deletedBy || $participant->getRole() === 'admin') {
            $metadata = $message->getMetadata() ?: [];
            $metadata['deleted'] = true;
            $metadata['deletedAt'] = (new \DateTimeImmutable())->format('c');
            $metadata['deletedBy'] = $deletedBy;
            
            $metadata['originalContent'] = $message->getContent();
            $message->setContent('[This message was deleted]')
                ->setMetadata($metadata);
            
            $this->entityManager->flush();
            return true;
        }
        
        return false;
    }
}