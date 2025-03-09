<?php

namespace App\ModuloChat\Controller;

use App\ModuloChat\Service\ChatService;
use App\ModuloCore\Entity\User;
use App\ModuloChat\Entity\Chat;
use App\ModuloChat\Entity\ChatParticipant;
use App\ModuloCore\Repository\UserRepository;
use Monolog\DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;

#[Route('/chat', name: 'chat_')]
class ChatController extends AbstractController
{
    private ChatService $chatService;
    private string $websocketUrl;
    private EntityManagerInterface $entityManager;
    
    public function __construct(ChatService $chatService, EntityManagerInterface $entityManager)
    {
        $this->chatService = $chatService;
        $this->entityManager = $entityManager;
        $this->websocketUrl = 'http://144.91.89.13:3033';
    }
    
    #[Route('/', name: 'index')]
    public function index(): Response
    {
        $user = $this->getUser();
        
        if (!$user instanceof User) {
            if ($this->getParameter('kernel.environment') === 'dev') {
                $userId = '1';
                $userName = 'Usuario de Prueba';
            } else {
                throw new AccessDeniedException('Debes iniciar sesión para acceder al chat.');
            }
        } else {
            $userId = (string) $user->getId();
            $userName = $user->getNombre() . ' ' . $user->getApellidos();
        }
        
        return $this->render('@ModuloChat/chat.html.twig', [
            'userId' => $userId,
            'userName' => $userName,
            'websocketUrl' => $this->websocketUrl
        ]);
    }
    
    #[Route('/rooms', name: 'rooms', methods: ['GET'])]
    public function getRooms(): JsonResponse
    {
        try {
            $rooms = $this->chatService->getRooms();
            
            $formattedRooms = [];
            foreach ($rooms as $room) {
                $formattedRooms[] = [
                    'id' => $room->getId(),
                    'name' => $room->getName(),
                    'type' => $room->getType(),
                    'createdAt' => $room->getCreatedAt()->format('Y-m-d H:i:s'),
                    'participantsCount' => $room->countActiveParticipants()
                ];
            }
            
            return $this->json([
                'success' => true,
                'rooms' => $formattedRooms
            ]);
        } catch (\Exception $e) {
            return $this->json([
                'success' => false,
                'error' => 'Error al obtener las salas de chat: ' . $e->getMessage()
            ], 500);
        }
    }
    
    #[Route('/rooms/{roomId}', name: 'room_detail', methods: ['GET'])]
    public function getRoomDetail(string $roomId): JsonResponse
    {
        try {
            $result = $this->chatService->getRoom($roomId);
            
            if (!$result) {
                return $this->json([
                    'success' => false,
                    'error' => 'La sala de chat no existe'
                ], 404);
            }
            
            $chat = $result['chat'];
            $messages = $result['messages'];
            
            $formattedMessages = [];
            foreach ($messages as $message) {
                $formattedMessages[] = [
                    'id' => $message->getId(),
                    'senderId' => $message->getSenderIdentifier(),
                    'senderName' => $message->getSenderName(),
                    'content' => $message->getContent(),
                    'type' => $message->getMessageType(),
                    'sentAt' => $message->getSentAt()->format('Y-m-d H:i:s'),
                    'read' => $message->getReadAt() !== null
                ];
            }
            
            $participants = [];
            foreach ($chat->getParticipants() as $participant) {
                if ($participant->isIsActive()) {
                    $participants[] = [
                        'id' => $participant->getParticipantIdentifier(),
                        'name' => $participant->getParticipantName(),
                        'role' => $participant->getRole(),
                        'joinedAt' => $participant->getJoinedAt()->format('Y-m-d H:i:s')
                    ];
                }
            }
            
            return $this->json([
                'success' => true,
                'room' => [
                    'id' => $chat->getId(),
                    'name' => $chat->getName(),
                    'type' => $chat->getType(),
                    'createdAt' => $chat->getCreatedAt()->format('Y-m-d H:i:s'),
                    'participants' => $participants
                ],
                'messages' => $formattedMessages
            ]);
        } catch (\Exception $e) {
            return $this->json([
                'success' => false,
                'error' => 'Error al obtener los detalles de la sala: ' . $e->getMessage()
            ], 500);
        }
    }
    
    #[Route('/rooms', name: 'create_room', methods: ['POST'])]
    public function createRoom(Request $request): JsonResponse
    {
        try {
            $data = json_decode($request->getContent(), true);
    
            if (!$data || !isset($data['name'], $data['creatorId'], $data['creatorName'])) {
                return $this->json(['success' => false, 'error' => 'Datos inválidos'], 400);
            }
    
            $name = $data['name'];
            $creatorId = $data['creatorId'];
            $creatorName = $data['creatorName'];
            $participantIds = $data['participantIds'] ?? [];
    
            // Crear el objeto Chat
            $chat = new Chat();
            $chat->setId('room_' . uniqid());
            $chat->setName($name);
            $chat->setType('private');
            $chat->setIsActive(true);
            $chat->setCreatedAt(new \DateTimeImmutable('now'));
    
            $this->entityManager->persist($chat);
    
            $this->addParticipantToChat($chat, $creatorId, $creatorName, 'creator');
    
            foreach ($participantIds as $participantId) {
                if ($participantId != $creatorId) {
                    $user = $this->entityManager->getRepository(User::class)->find($participantId);
                    $participantName = $user ? $user->getNombre() . ' ' . $user->getApellidos() : 'Usuario ' . $participantId;
                    $this->addParticipantToChat($chat, $participantId, $participantName, 'member');
                }
            }
    
            $this->entityManager->flush();
    
            return $this->json([
                'success' => true,
                'room' => [
                    'id' => $chat->getId(),
                    'name' => $chat->getName(),
                    'type' => $chat->getType(),
                    'createdAt' => $chat->getCreatedAt()->format('Y-m-d H:i:s'),
                ]
            ]);
        } catch (\Exception $e) {
            return $this->json(['success' => false, 'error' => 'Error al crear la sala: ' . $e->getMessage()], 500);
        }
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
            $existingParticipant->setRole($role);
        } else {
            $participant = new ChatParticipant();
            $participant->setChat($chat);
            $participant->setParticipantIdentifier($participantId);
            $participant->setParticipantName($participantName);
            $participant->setRole($role);
            $participant->setIsActive(true);
            
            $this->entityManager->persist($participant);
        }
    }
    
    #[Route('/rooms/{roomId}/messages', name: 'send_message', methods: ['POST'])]
    public function sendMessage(Request $request, string $roomId): JsonResponse
    {
        try {
            $data = json_decode($request->getContent(), true);
            
            if (!isset($data['senderId']) || !isset($data['content'])) {
                return $this->json([
                    'success' => false,
                    'error' => 'Faltan parámetros obligatorios (senderId, content)'
                ], 400);
            }
            
            $senderId = $data['senderId'];
            $senderName = $data['senderName'] ?? 'Usuario ' . $senderId;
            $content = $data['content'];
            $messageType = $data['type'] ?? 'text';
            
            $message = $this->chatService->sendMessage($roomId, $senderId, $senderName, $content, $messageType);
            
            if (!$message) {
                return $this->json([
                    'success' => false,
                    'error' => 'Error al enviar el mensaje'
                ], 500);
            }
            
            return $this->json([
                'success' => true,
                'id' => $message->getId(),
                'message' => [
                    'id' => $message->getId(),
                    'senderId' => $message->getSenderIdentifier(),
                    'senderName' => $message->getSenderName(),
                    'content' => $message->getContent(),
                    'type' => $message->getMessageType(),
                    'sentAt' => $message->getSentAt()->format('Y-m-d H:i:s')
                ]
            ]);
        } catch (\Exception $e) {
            return $this->json([
                'success' => false,
                'error' => 'Error al enviar el mensaje: ' . $e->getMessage()
            ], 500);
        }
    }
    
    #[Route('/rooms/{roomId}/participants', name: 'add_participant', methods: ['POST'])]
    public function addParticipant(Request $request, string $roomId): JsonResponse
    {
        
        return $this->json([
            'success' => false,
            'error' => 'Método no implementado'
        ], 501);
    }
    
    #[Route('/rooms/{roomId}/participants/{participantId}', name: 'remove_participant', methods: ['DELETE'])]
    public function removeParticipant(string $roomId, string $participantId): JsonResponse
    {
        try {
            $success = $this->chatService->removeParticipant($roomId, $participantId);
            
            if (!$success) {
                return $this->json([
                    'success' => false,
                    'error' => 'Error al eliminar el participante'
                ], 400);
            }
            
            return $this->json([
                'success' => true
            ]);
        } catch (\Exception $e) {
            return $this->json([
                'success' => false,
                'error' => 'Error al eliminar el participante: ' . $e->getMessage()
            ], 500);
        }
    }
    
    #[Route('/user/rooms', name: 'user_rooms', methods: ['GET'])]
    public function getUserRooms(Request $request): JsonResponse
    {
        try {
            $user = $this->getUser();
            
            if (!$user instanceof User) {
                if ($this->getParameter('kernel.environment') === 'dev') {
                    $userId = $request->query->get('userId', '1');
                } else {
                    return $this->json([
                        'success' => false,
                        'error' => 'Usuario no autenticado'
                    ], 401);
                }
            } else {
                $userId = (string) $user->getId();
            }
            
            $rooms = $this->chatService->getUserChats($userId);
            
            $formattedRooms = [];
            foreach ($rooms as $room) {
                if ($room->isIsActive()) {
                    $formattedRooms[] = [
                        'id' => $room->getId(),
                        'name' => $room->getName(),
                        'type' => $room->getType(),
                        'createdAt' => $room->getCreatedAt()->format('Y-m-d H:i:s'),
                        'participants' => count($room->getActiveParticipants())
                    ];
                }
            }
            
            return $this->json([
                'success' => true,
                'rooms' => $formattedRooms
            ]);
        } catch (\Exception $e) {
            return $this->json([
                'success' => false,
                'error' => 'Error al obtener las salas de chat: ' . $e->getMessage()
            ], 500);
        }
    }
    
    #[Route('/rooms/{roomId}/messages/{messageId}/read', name: 'mark_message_read', methods: ['POST'])]
    public function markMessageAsRead(string $roomId, string $messageId): JsonResponse
    {
        try {
            $success = $this->chatService->markMessageAsRead($messageId);
            
            if (!$success) {
                return $this->json([
                    'success' => false,
                    'error' => 'Error al marcar el mensaje como leído'
                ], 400);
            }
            
            return $this->json([
                'success' => true
            ]);
        } catch (\Exception $e) {
            return $this->json([
                'success' => false,
                'error' => 'Error al marcar el mensaje como leído: ' . $e->getMessage()
            ], 500);
        }
    }

    #[Route('/api/users/search', name: 'search_users', methods: ['GET'])]
    public function searchUsers(Request $request): JsonResponse
    {
        $query = $request->query->get('q', '');
        
        if (strlen($query) < 2) {
            return $this->json([
                'success' => true,
                'users' => []
            ]);
        }
        
        try {
            $userRepository = $this->entityManager->getRepository(User::class);
            
            $qb = $userRepository->createQueryBuilder('u');
            $qb->where('u.nombre LIKE :query')
               ->orWhere('u.apellidos LIKE :query')
               ->orWhere('u.email LIKE :query')
               ->andWhere('u.is_active = :active')
               ->setParameter('query', '%' . $query . '%')
               ->setParameter('active', true)
               ->setMaxResults(10);
            
            $results = $qb->getQuery()->getResult();
            
            $users = [];
            foreach ($results as $user) {
                $users[] = [
                    'id' => $user->getId(),
                    'nombre' => $user->getNombre() . ' ' . $user->getApellidos(),
                    'email' => $user->getEmail()
                ];
            }
            
            return $this->json([
                'success' => true,
                'users' => $users
            ]);
        } catch (\Exception $e) {
            $mockUsers = $this->getMockUsers($query);
            $mockUsers = array_values($mockUsers);
            
            return $this->json([
                'success' => true,
                'users' => $mockUsers,
                'note' => 'Usando datos simulados debido a un error: ' . $e->getMessage()
            ]);
        }
    }

    private function getMockUsers(string $query): array
    {
        $mockUsers = [
            ['id' => 1, 'nombre' => 'Admin Usuario', 'email' => 'admin@example.com'],
        ];
        
        return array_filter($mockUsers, function($user) use ($query) {
            $query = strtolower($query);
            return (strpos(strtolower($user['nombre']), $query) !== false) || 
                  (strpos(strtolower($user['email']), $query) !== false);
        });
    }
}