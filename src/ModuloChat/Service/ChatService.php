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
        // Configuración directa del servidor WebSocket
        $this->websocketUrl = 'http://144.91.89.13:3033';
        $this->httpClient = HttpClient::create(['timeout' => 10]);
    }

    /**
     * Envía un mensaje a una sala y lo almacena en la base de datos
     */
    public function sendMessage(string $roomId, string $senderId, string $senderName, string $content, string $messageType = 'text'): ?ChatMessage
    {
        try {
            // Primero buscamos la sala en nuestra base de datos
            $chat = $this->findOrCreateChat($roomId);
            
            // Luego creamos el mensaje en la base de datos
            $message = new ChatMessage();
            $message->setChat($chat);
            $message->setSenderIdentifier($senderId);
            $message->setSenderName($senderName);
            $message->setContent($content);
            $message->setMessageType($messageType);
            $message->setSentAt(new DateTimeImmutable());
            
            $this->entityManager->persist($message);
            $this->entityManager->flush();
            
            // Si la sala no tiene participante, lo agregamos
            if (!$this->isParticipant($roomId, $senderId)) {
                $this->addParticipantToChat($chat, $senderId, $senderName);
            }
            
            return $message;
        } catch (\Exception $e) {
            // Log del error
            error_log('Error al guardar mensaje: ' . $e->getMessage());
            return null;
        }
    }

    /**
     * Busca una sala por ID o la crea si no existe
     */
    private function findOrCreateChat(string $roomId): Chat
    {
        $chatRepository = $this->entityManager->getRepository(Chat::class);
        $chat = $chatRepository->find($roomId);
        
        if (!$chat) {
            // Si no existe, creamos una nueva sala
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

    /**
     * Añade un participante a un chat si no existe
     */
    private function addParticipantToChat(Chat $chat, string $participantId, string $participantName, string $role = 'member'): void
    {
        $participantRepository = $this->entityManager->getRepository(ChatParticipant::class);
        
        $existingParticipant = $participantRepository->findOneBy([
            'chat' => $chat,
            'participantIdentifier' => $participantId
        ]);
        
        if ($existingParticipant) {
            // Si ya existe, sólo actualizamos estado y rol
            $existingParticipant->setIsActive(true);
            if ($role === 'creator') {
                $existingParticipant->setRole($role);
            }
        } else {
            // Si no existe, creamos nuevo participante
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

    /**
     * Verifica si un usuario es participante de un chat
     */
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

    /**
     * Verifica si un usuario es administrador de un chat
     */
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

    /**
     * Obtiene un chat por su ID y sus mensajes
     */
    public function getRoom(string $roomId): ?array
    {
        try {
            // Primero buscamos el chat en la base de datos
            $chatRepository = $this->entityManager->getRepository(Chat::class);
            $chat = $chatRepository->find($roomId);
            
            if (!$chat) {
                // Si no existe, creamos uno nuevo
                $chat = $this->findOrCreateChat($roomId);
            }
            
            // Obtenemos los mensajes desde el WebSocket
            try {
                $response = $this->httpClient->request('GET', $this->websocketUrl . '/rooms/' . $roomId . '/messages');
                $apiMessages = json_decode($response->getContent(), true);
                
                // Sincronizamos con la base de datos local
                $this->syncMessagesWithDatabase($roomId, $apiMessages);
            } catch (\Exception $e) {
                // Si hay error de conexión con WebSocket, usamos solo los mensajes locales
                error_log('Error al obtener mensajes del WebSocket: ' . $e->getMessage());
            }
            
            // Obtenemos los mensajes de la base de datos
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

    /**
     * Sincroniza los mensajes del WebSocket con la base de datos
     */
    private function syncMessagesWithDatabase(string $roomId, array $apiMessages): void
    {
        $chat = $this->findOrCreateChat($roomId);
        $messageRepository = $this->entityManager->getRepository(ChatMessage::class);
        
        foreach ($apiMessages as $apiMessage) {
            // Solo sincronizar mensajes que no sean del sistema
            if (isset($apiMessage['senderId']) && $apiMessage['senderId'] !== 'system') {
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
                    $message->setMessageType($apiMessage['type'] ?? 'text');
                    $message->setSentAt(new DateTimeImmutable($apiMessage['timestamp']));
                    
                    $this->entityManager->persist($message);
                }
            }
        }
        
        $this->entityManager->flush();
    }

    /**
     * Obtiene todas las salas de chat a las que pertenece un usuario
     */
    public function getUserChats(string $userId): array
    {
        try {
            // También intentamos sincronizar con WebSocket
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

    /**
     * Sincroniza las salas del WebSocket con la base de datos
     */
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

    /**
     * Obtiene todas las salas disponibles
     */
    public function getRooms(): array
    {
        try {
            // Sincronizar con WebSocket
            $this->syncRoomsFromWebSocket();
            
            // Obtener todas las salas activas
            $chatRepository = $this->entityManager->getRepository(Chat::class);
            return $chatRepository->findBy(['isActive' => true], ['createdAt' => 'DESC']);
        } catch (\Exception $e) {
            error_log('Error al obtener salas: ' . $e->getMessage());
            return [];
        }
    }

    /**
     * Marca un mensaje como leído
     */
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

    /**
     * Elimina un participante de una sala
     */
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