<?php

namespace App\ModuloChat\Service;

use App\ModuloChat\Entity\Chat;
use App\ModuloChat\Entity\ChatMessage;
use App\ModuloChat\Entity\ChatParticipant;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpClient\HttpClient;
use Symfony\Contracts\HttpClient\HttpClientInterface;

class ChatService
{
    private EntityManagerInterface $entityManager;
    private HttpClientInterface $httpClient;
    private string $websocketUrl;

    public function __construct(EntityManagerInterface $entityManager)
    {
        $this->entityManager = $entityManager;
        // Valor por defecto para evitar errores
        $this->websocketUrl = 'http://144.91.89.13:3033';
        $this->httpClient = HttpClient::create();
    }
    
    /**
     * Configura la URL del WebSocket
     */
    public function setWebsocketUrl(string $url): void
    {
        $this->websocketUrl = $url;
    }

    /**
     * Obtiene todas las salas de chat disponibles
     * Sincroniza los datos entre el servidor WebSocket y la base de datos
     */
    public function getRooms(): array
    {
        try {
            // Obtener salas desde la API WebSocket
            $response = $this->httpClient->request('GET', $this->websocketUrl . '/rooms');
            $apiRooms = json_decode($response->getContent(), true);
            
            // Sincronizar con la base de datos
            $this->syncRoomsWithDatabase($apiRooms);
            
            // Recuperar salas de la base de datos con información completa
            $chatRepository = $this->entityManager->getRepository(Chat::class);
            $rooms = $chatRepository->findBy(['isActive' => true], ['createdAt' => 'DESC']);
            
            return $rooms;
        } catch (\Exception $e) {
            // En caso de fallo, retornar salas de la base de datos local
            $chatRepository = $this->entityManager->getRepository(Chat::class);
            return $chatRepository->findBy(['isActive' => true], ['createdAt' => 'DESC']);
        }
    }

    /**
     * Obtiene los detalles de una sala y sus mensajes
     */
    public function getRoom(string $roomId): ?array
    {
        try {
            // Obtener mensajes desde la API WebSocket
            $response = $this->httpClient->request('GET', $this->websocketUrl . '/rooms/' . $roomId . '/messages');
            $apiMessages = json_decode($response->getContent(), true);
            
            // Sincronizar mensajes con la base de datos
            $this->syncMessagesWithDatabase($roomId, $apiMessages);
            
            // Obtener chat y mensajes de la base de datos
            $chatRepository = $this->entityManager->getRepository(Chat::class);
            $chat = $chatRepository->find($roomId);
            
            if (!$chat) {
                // Si no existe en la base de datos, crear nuevo registro
                $chat = $this->createChatFromApiData($roomId);
                if (!$chat) {
                    return null;
                }
            }
            
            $messageRepository = $this->entityManager->getRepository(ChatMessage::class);
            $messages = $messageRepository->findBy(['chat' => $chat], ['sentAt' => 'DESC']);
            
            return [
                'chat' => $chat,
                'messages' => $messages
            ];
        } catch (\Exception $e) {
            // En caso de fallo, retornar datos locales
            $chatRepository = $this->entityManager->getRepository(Chat::class);
            $chat = $chatRepository->find($roomId);
            
            if (!$chat) {
                return null;
            }
            
            $messageRepository = $this->entityManager->getRepository(ChatMessage::class);
            $messages = $messageRepository->findBy(['chat' => $chat], ['sentAt' => 'DESC']);
            
            return [
                'chat' => $chat,
                'messages' => $messages
            ];
        }
    }

    /**
     * Crea una nueva sala de chat
     */
    public function createRoom(string $name, string $creatorId, string $creatorName, array $participantIds = []): ?Chat
    {
        try {
            // Crear sala en la API WebSocket
            $response = $this->httpClient->request('POST', $this->websocketUrl . '/rooms', [
                'json' => [
                    'name' => $name,
                    'creatorId' => $creatorId,
                    'creatorName' => $creatorName,
                    'participantIds' => $participantIds
                ]
            ]);
            
            $data = json_decode($response->getContent(), true);
            
            if (!isset($data['roomId'])) {
                return null;
            }
            
            // Crear en la base de datos
            $chat = new Chat();
            $chat->setId($data['roomId']);
            $chat->setName($name);
            $chat->setType('private');
            $chat->setIsActive(true);
            $chat->setCreatedAt(new DateTimeImmutable());
            
            $this->entityManager->persist($chat);
            
            // Agregar participantes
            $this->addParticipantToChat($chat, $creatorId, $creatorName, 'creator');
            
            foreach ($participantIds as $participantId) {
                if ($participantId != $creatorId) {
                    // En un caso real, buscarías el nombre del usuario en la base de datos
                    $this->addParticipantToChat($chat, $participantId, 'Usuario ' . $participantId, 'member');
                }
            }
            
            $this->entityManager->flush();
            
            return $chat;
        } catch (\Exception $e) {
            return null;
        }
    }

    /**
     * Envía un mensaje a una sala de chat
     */
    public function sendMessage(string $roomId, string $senderId, string $senderName, string $content, string $messageType = 'text'): ?ChatMessage
    {
        try {
            // Enviar mensaje a través de la API WebSocket
            $response = $this->httpClient->request('POST', $this->websocketUrl . '/rooms/' . $roomId . '/messages', [
                'json' => [
                    'senderId' => $senderId,
                    'content' => $content,
                    'type' => $messageType
                ]
            ]);
            
            $data = json_decode($response->getContent(), true);
            
            if (!isset($data['id'])) {
                // Si la API falla, aún podemos guardar el mensaje localmente
                return $this->createMessageLocally($roomId, $senderId, $senderName, $content, $messageType);
            }
            
            // Guardar mensaje en la base de datos
            $chatRepository = $this->entityManager->getRepository(Chat::class);
            $chat = $chatRepository->find($roomId);
            
            if (!$chat) {
                $chat = $this->createChatFromApiData($roomId);
                if (!$chat) {
                    return null;
                }
            }
            
            $message = new ChatMessage();
            $message->setChat($chat);
            $message->setSenderIdentifier($senderId);
            $message->setSenderName($senderName);
            $message->setContent($content);
            $message->setMessageType($messageType);
            $message->setSentAt(new DateTimeImmutable());
            
            $this->entityManager->persist($message);
            $this->entityManager->flush();
            
            return $message;
        } catch (\Exception $e) {
            return $this->createMessageLocally($roomId, $senderId, $senderName, $content, $messageType);
        }
    }

    /**
     * Registra un mensaje localmente cuando la API falla
     */
    private function createMessageLocally(string $roomId, string $senderId, string $senderName, string $content, string $messageType): ?ChatMessage
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
        }
        
        $message = new ChatMessage();
        $message->setChat($chat);
        $message->setSenderIdentifier($senderId);
        $message->setSenderName($senderName);
        $message->setContent($content);
        $message->setMessageType($messageType);
        $message->setSentAt(new DateTimeImmutable());
        
        $this->entityManager->persist($message);
        $this->entityManager->flush();
        
        return $message;
    }

    /**
     * Sincroniza las salas del WebSocket con la base de datos
     */
    private function syncRoomsWithDatabase(array $apiRooms): void
    {
        $chatRepository = $this->entityManager->getRepository(Chat::class);
        
        foreach ($apiRooms as $apiRoom) {
            $roomId = $apiRoom['id'];
            $chat = $chatRepository->find($roomId);
            
            if (!$chat) {
                // Crear nueva sala en la base de datos
                $chat = new Chat();
                $chat->setId($roomId);
                $chat->setName($apiRoom['name']);
                $chat->setType('private'); // Asumimos que son privadas
                $chat->setIsActive(true);
                $chat->setCreatedAt(new DateTimeImmutable());
                
                $this->entityManager->persist($chat);
            }
        }
        
        $this->entityManager->flush();
    }

    /**
     * Sincroniza los mensajes del WebSocket con la base de datos
     */
    private function syncMessagesWithDatabase(string $roomId, array $apiMessages): void
    {
        $chatRepository = $this->entityManager->getRepository(Chat::class);
        $messageRepository = $this->entityManager->getRepository(ChatMessage::class);
        
        $chat = $chatRepository->find($roomId);
        
        if (!$chat) {
            $chat = $this->createChatFromApiData($roomId);
            if (!$chat) {
                return;
            }
        }
        
        foreach ($apiMessages as $apiMessage) {
            // Verificar si el mensaje ya existe en la base de datos
            $existingMessage = $messageRepository->findOneBy([
                'chat' => $chat,
                'senderIdentifier' => $apiMessage['senderId'],
                'content' => $apiMessage['content'],
                'sentAt' => new DateTimeImmutable($apiMessage['timestamp'])
            ]);
            
            if (!$existingMessage) {
                // Crear nuevo mensaje en la base de datos
                $message = new ChatMessage();
                $message->setChat($chat);
                $message->setSenderIdentifier($apiMessage['senderId']);
                $message->setSenderName($apiMessage['senderName']);
                $message->setContent($apiMessage['content']);
                $message->setMessageType($apiMessage['type']);
                $message->setSentAt(new DateTimeImmutable($apiMessage['timestamp']));
                
                $this->entityManager->persist($message);
            }
        }
        
        $this->entityManager->flush();
    }

    /**
     * Crea un nuevo chat desde los datos de la API
     */
    private function createChatFromApiData(string $roomId): ?Chat
    {
        try {
            // Obtener detalles de la sala desde la API
            $response = $this->httpClient->request('GET', $this->websocketUrl . '/rooms/' . $roomId);
            $roomData = json_decode($response->getContent(), true);
            
            if (!isset($roomData['name'])) {
                $roomData = ['name' => 'Chat ' . $roomId];
            }
            
            $chat = new Chat();
            $chat->setId($roomId);
            $chat->setName($roomData['name']);
            $chat->setType('private'); // Asumimos que son privadas
            $chat->setIsActive(true);
            $chat->setCreatedAt(new DateTimeImmutable());
            
            $this->entityManager->persist($chat);
            $this->entityManager->flush();
            
            return $chat;
        } catch (\Exception $e) {
            // Si no podemos obtener datos, crear con información mínima
            $chat = new Chat();
            $chat->setId($roomId);
            $chat->setName('Chat ' . $roomId);
            $chat->setType('private');
            $chat->setIsActive(true);
            $chat->setCreatedAt(new DateTimeImmutable());
            
            $this->entityManager->persist($chat);
            $this->entityManager->flush();
            
            return $chat;
        }
    }

    /**
     * Agrega un participante a un chat
     */
    private function addParticipantToChat(Chat $chat, string $participantId, string $participantName, string $role = 'member'): void
    {
        $participantRepository = $this->entityManager->getRepository(ChatParticipant::class);
        
        // Verificar si el participante ya existe
        $existingParticipant = $participantRepository->findOneBy([
            'chat' => $chat,
            'participantIdentifier' => $participantId
        ]);
        
        if ($existingParticipant) {
            // Actualizar si ya existe
            $existingParticipant->setIsActive(true);
            $existingParticipant->setRole($role);
        } else {
            // Crear nuevo participante
            $participant = new ChatParticipant();
            $participant->setChat($chat);
            $participant->setParticipantIdentifier($participantId);
            $participant->setParticipantName($participantName);
            $participant->setRole($role);
            $participant->setIsActive(true);
            
            $this->entityManager->persist($participant);
        }
    }

    /**
     * Obtiene todos los chats en los que participa un usuario
     */
    public function getUserChats(string $userId): array
    {
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
    }
    
    /**
     * Marca un chat como cerrado
     */
    public function closeChat(string $roomId): bool
    {
        $chatRepository = $this->entityManager->getRepository(Chat::class);
        $chat = $chatRepository->find($roomId);
        
        if (!$chat) {
            return false;
        }
        
        $chat->close();
        $this->entityManager->flush();
        
        return true;
    }
    
    /**
     * Marca un participante como inactivo (dejó el chat)
     */
    public function removeParticipant(string $roomId, string $participantId): bool
    {
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
    }
    
    /**
     * Marca un mensaje como leído
     */
    public function markMessageAsRead(string $messageId): bool
    {
        $messageRepository = $this->entityManager->getRepository(ChatMessage::class);
        $message = $messageRepository->find($messageId);
        
        if (!$message) {
            return false;
        }
        
        $message->markAsRead();
        $this->entityManager->flush();
        
        return true;
    }
    
    /**
     * Obtiene todos los mensajes no leídos para un usuario
     */
    public function getUnreadMessages(string $userId): array
    {
        $participantRepository = $this->entityManager->getRepository(ChatParticipant::class);
        $messageRepository = $this->entityManager->getRepository(ChatMessage::class);
        
        // Obtener todas las salas en las que participa el usuario
        $participants = $participantRepository->findBy([
            'participantIdentifier' => $userId,
            'isActive' => true
        ]);
        
        $unreadMessages = [];
        foreach ($participants as $participant) {
            $chat = $participant->getChat();
            
            // Obtener mensajes que no son del usuario y no han sido leídos
            $messages = $messageRepository->createQueryBuilder('m')
                ->where('m.chat = :chat')
                ->andWhere('m.senderIdentifier != :userId')
                ->andWhere('m.readAt IS NULL')
                ->setParameter('chat', $chat)
                ->setParameter('userId', $userId)
                ->orderBy('m.sentAt', 'DESC')
                ->getQuery()
                ->getResult();
            
            if (!empty($messages)) {
                $unreadMessages[$chat->getId()] = [
                    'chat' => $chat,
                    'messages' => $messages,
                    'count' => count($messages)
                ];
            }
        }
        
        return $unreadMessages;
    }
    
    /**
     * Marca todos los mensajes de un chat como leídos para un usuario
     */
    public function markAllMessagesAsRead(string $roomId, string $userId): int
    {
        $messageRepository = $this->entityManager->getRepository(ChatMessage::class);
        
        // Contar mensajes sin leer para actualizar
        $unreadMessages = $messageRepository->createQueryBuilder('m')
            ->where('m.chat = :roomId')
            ->andWhere('m.senderIdentifier != :userId')
            ->andWhere('m.readAt IS NULL')
            ->setParameter('roomId', $roomId)
            ->setParameter('userId', $userId)
            ->getQuery()
            ->getResult();
        
        // Marcar todos como leídos
        $now = new DateTimeImmutable();
        foreach ($unreadMessages as $message) {
            $message->setReadAt($now);
        }
        
        $this->entityManager->flush();
        
        return count($unreadMessages);
    }
    
    /**
     * Verifica si un usuario es participante de un chat
     */
    public function isParticipant(string $roomId, string $userId): bool
    {
        $participantRepository = $this->entityManager->getRepository(ChatParticipant::class);
        
        $participant = $participantRepository->findOneBy([
            'chat' => $roomId,
            'participantIdentifier' => $userId,
            'isActive' => true
        ]);
        
        return $participant !== null;
    }
}