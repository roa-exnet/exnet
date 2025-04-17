<?php

namespace App\ModuloChat\Service;

use App\ModuloChat\Entity\Chat;
use App\ModuloChat\Entity\ChatMessage;
use App\ModuloChat\Entity\ChatParticipant;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpClient\HttpClient;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Component\DependencyInjection\ParameterBag\ParameterBagInterface;

class ChatService
{
    private EntityManagerInterface $entityManager;
    private HttpClientInterface $httpClient;
    private string $websocketUrl;

    public function __construct(EntityManagerInterface $entityManager, ParameterBagInterface $parameterBag)
    {
        $this->entityManager = $entityManager;
        $this->websocketUrl = $parameterBag->get('websocket_url');;
        $this->httpClient = HttpClient::create(['timeout' => 10]);
    }

    public function sendMessage(string $roomId, string $senderId, string $senderName, string $content, string $messageType = 'text'): ?ChatMessage
    {
        try {
            $chat = $this->findOrCreateChat($roomId);
            
            $message = new ChatMessage();
            $message->setChat($chat);
            $message->setSenderIdentifier($senderId);
            $message->setSenderName($senderName);
            $message->setContent($content);
            $message->setMessageType($messageType);
            $message->setSentAt(new DateTimeImmutable());
            
            $this->entityManager->persist($message);
            $this->entityManager->flush();
            
            if (!$this->isParticipant($roomId, $senderId)) {
                $this->addParticipantToChat($chat, $senderId, $senderName);
            }
            
            return $message;
        } catch (\Exception $e) {
            error_log('Error al guardar mensaje: ' . $e->getMessage());
            return null;
        }
    }

    private function findOrCreateChat(string $roomId): Chat
    {
        $chatRepository = $this->entityManager->getRepository(Chat::class);
        $chat = $chatRepository->find($roomId);
        
        if (!$chat) {
            $chat = new Chat();
            $chat->setId($roomId);
            $chat->setName('Chat ' . $roomId);
            $chat->setType('private');
            $chat->setIsActive(true);
            $chat->setCreatedAt(new DateTimeImmutable());
            
            $this->entityManager->persist($chat);
            $this->entityManager->flush();
        }
        
        return $chat;
    }

    private function addParticipantToChat(Chat $chat, string $participantId, string $participantName, string $role = 'member'): void
    {
        $participantRepository = $this->entityManager->getRepository(ChatParticipant::class);
        
        $existingParticipant = $participantRepository->findOneBy([
            'chat' => $chat,
            'participantIdentifier' => $participantId
        ]);
        
        if ($existingParticipant) {
            $existingParticipant->setIsActive(true);
            if ($role === 'creator') {
                $existingParticipant->setRole($role);
            }
        } else {
            $participant = new ChatParticipant();
            $participant->setChat($chat);
            $participant->setParticipantIdentifier($participantId);
            $participant->setParticipantName($participantName);
            $participant->setRole($role);
            $participant->setIsActive(true);
            
            $this->entityManager->persist($participant);
        }
        
        $this->entityManager->flush();
    }

    public function isParticipant(string $roomId, string $userId): bool
    {
        try {
            $participantRepository = $this->entityManager->getRepository(ChatParticipant::class);
            
            $participant = $participantRepository->findOneBy([
                'chat' => $roomId,
                'participantIdentifier' => $userId
            ]);
            
            return $participant !== null;
        } catch (\Exception $e) {
            return false;
        }
    }

    public function isAdmin(string $roomId, string $userId): bool
    {
        try {
            $participantRepository = $this->entityManager->getRepository(ChatParticipant::class);
            
            $participant = $participantRepository->findOneBy([
                'chat' => $roomId,
                'participantIdentifier' => $userId
            ]);
            
            if (!$participant) {
                return false;
            }
            
            return in_array($participant->getRole(), ['admin', 'creator']);
        } catch (\Exception $e) {
            return false;
        }
    }

    public function getRoom(string $roomId): ?array
    {
        try {
            $chatRepository = $this->entityManager->getRepository(Chat::class);
            $chat = $chatRepository->find($roomId);
            
            if (!$chat) {
                $chat = $this->findOrCreateChat($roomId);
            }
            
            try {
                $response = $this->httpClient->request('GET', $this->websocketUrl . '/rooms/' . $roomId . '/messages');
                $apiMessages = json_decode($response->getContent(), true);
                
                $this->syncMessagesWithDatabase($roomId, $apiMessages);
            } catch (\Exception $e) {
                error_log('Error al obtener mensajes del WebSocket: ' . $e->getMessage());
            }
            
            $messageRepository = $this->entityManager->getRepository(ChatMessage::class);
            $messages = $messageRepository->findBy(['chat' => $chat], ['sentAt' => 'DESC']);
            
            return [
                'chat' => $chat,
                'messages' => $messages
            ];
        } catch (\Exception $e) {
            error_log('Error al obtener sala: ' . $e->getMessage());
            return null;
        }
    }

    private function syncMessagesWithDatabase(string $roomId, array $apiMessages): void
    {
        $chat = $this->findOrCreateChat($roomId);
        $messageRepository = $this->entityManager->getRepository(ChatMessage::class);
        
        foreach ($apiMessages as $apiMessage) {
            if (isset($apiMessage['senderId']) && $apiMessage['senderId'] !== 'system') {
                $existingMessage = $messageRepository->findOneBy([
                    'chat' => $chat,
                    'senderIdentifier' => $apiMessage['senderId'],
                    'content' => $apiMessage['content'],
                    'sentAt' => new DateTimeImmutable($apiMessage['timestamp'])
                ]);
                
                if (!$existingMessage) {
                    $message = new ChatMessage();
                    $message->setChat($chat);
                    $message->setSenderIdentifier($apiMessage['senderId']);
                    $message->setSenderName($apiMessage['senderName']);
                    $message->setContent($apiMessage['content']);
                    $message->setMessageType($apiMessage['type'] ?? 'text');
                    $message->setSentAt(new DateTimeImmutable($apiMessage['timestamp']));
                    
                    $this->entityManager->persist($message);
                }
            }
        }
        
        $this->entityManager->flush();
    }

    public function getUserChats(string $userId): array
    {
        try {
            $this->syncRoomsFromWebSocket();
            
            $participantRepository = $this->entityManager->getRepository(ChatParticipant::class);
            
            $participants = $participantRepository->findBy([
                'participantIdentifier' => $userId,
                'isActive' => true
            ]);
            
            $chats = [];
            foreach ($participants as $participant) {
                $chats[] = $participant->getChat();
            }
            
            return $chats;
        } catch (\Exception $e) {
            error_log('Error al obtener chats del usuario: ' . $e->getMessage());
            return [];
        }
    }

    private function syncRoomsFromWebSocket(): void
    {
        try {
            $response = $this->httpClient->request('GET', $this->websocketUrl . '/rooms');
            $apiRooms = json_decode($response->getContent(), true);
            
            foreach ($apiRooms as $apiRoom) {
                $this->findOrCreateChat($apiRoom['id']);
            }
        } catch (\Exception $e) {
            error_log('Error al sincronizar salas desde WebSocket: ' . $e->getMessage());
        }
    }

    public function getRooms(): array
    {
        try {
            $this->syncRoomsFromWebSocket();
            
            $chatRepository = $this->entityManager->getRepository(Chat::class);
            return $chatRepository->findBy(['isActive' => true], ['createdAt' => 'DESC']);
        } catch (\Exception $e) {
            error_log('Error al obtener salas: ' . $e->getMessage());
            return [];
        }
    }

    public function markMessageAsRead(string $messageId): bool
    {
        try {
            $messageRepository = $this->entityManager->getRepository(ChatMessage::class);
            $message = $messageRepository->find($messageId);
            
            if (!$message) {
                return false;
            }
            
            $message->markAsRead();
            $this->entityManager->flush();
            
            return true;
        } catch (\Exception $e) {
            return false;
        }
    }

    public function removeParticipant(string $roomId, string $participantId): bool
    {
        try {
            $participantRepository = $this->entityManager->getRepository(ChatParticipant::class);
            $chatRepository = $this->entityManager->getRepository(Chat::class);
            
            $chat = $chatRepository->find($roomId);
            if (!$chat) {
                return false;
            }
            
            $participant = $participantRepository->findOneBy([
                'chat' => $chat,
                'participantIdentifier' => $participantId,
                'isActive' => true
            ]);
            
            if (!$participant) {
                return false;
            }
            
            $participant->leave();
            $this->entityManager->flush();
            
            return true;
        } catch (\Exception $e) {
            return false;
        }
    }
}