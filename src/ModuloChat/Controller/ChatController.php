<?php

namespace App\ModuloChat\Controller;

use App\ModuloChat\Service\ChatService;
use App\ModuloCore\Entity\User;
use App\ModuloChat\Entity\Chat;
use App\ModuloChat\Entity\ChatParticipant;
use App\ModuloCore\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;

/**
 * Controlador para la funcionalidad de chat
 * Requiere que el usuario esté autenticado para acceder a cualquier endpoint
 */
#[Route('/chat', name: 'chat_')]
#[IsGranted('IS_AUTHENTICATED_REMEMBERED')]
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
        /** @var User $user */
        $user = $this->getUser();
        
        // Verificación adicional de seguridad
        if (!$user instanceof User) {
            throw new AccessDeniedException('Debes iniciar sesión para acceder al chat.');
        }
        
        $userId = (string) $user->getId();
        $userName = $user->getNombre() . ' ' . $user->getApellidos();
        
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
            // Verificar que el usuario está autorizado
            $this->denyAccessUnlessGranted('IS_AUTHENTICATED_FULLY');
            
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
        } catch (AccessDeniedException $e) {
            return $this->json([
                'success' => false,
                'error' => 'Acceso denegado: ' . $e->getMessage()
            ], 403);
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
            // Verificar que el usuario está autorizado
            /** @var User $user */
            $user = $this->getUser();
            if (!$user instanceof User) {
                throw new AccessDeniedException('Usuario no autenticado');
            }
            
            // Verificar que el usuario tiene acceso a esta sala
            // Nota: Si es la primera vez, podemos agregar automáticamente al usuario
            if (!$this->chatService->isParticipant($roomId, (string)$user->getId())) {
                // Opción: Agregar al usuario automáticamente si la sala existe
                $userId = (string)$user->getId();
                $userName = $user->getNombre() . ' ' . $user->getApellidos();
                
                if ($this->chatService->addParticipantIfRoomExists($roomId, $userId, $userName)) {
                    // Log para seguimiento
                    error_log("Usuario {$userId} ({$userName}) agregado automáticamente a sala {$roomId}");
                } else {
                    throw new AccessDeniedException('No tienes permiso para acceder a esta sala');
                }
            }
            
            // Obtener la sala y sus mensajes
            $result = $this->chatService->getRoom($roomId);
            
            if (!$result) {
                return $this->json([
                    'success' => false,
                    'error' => 'La sala de chat no existe o no se pudo cargar'
                ], 404);
            }
            
            $chat = $result['chat'];
            $messages = $result['messages'];
            
            // Registro para diagnóstico
            error_log("Obtenidos " . count($messages) . " mensajes para sala {$roomId} en el controlador");
            
            // Formatear los mensajes para la respuesta JSON
            $formattedMessages = [];
            foreach ($messages as $message) {
                $messageData = [
                    'id' => $message->getId(),
                    'senderId' => $message->getSenderIdentifier(),
                    'senderName' => $message->getSenderName(),
                    'content' => $message->getContent(),
                    'type' => $message->getMessageType(),
                    'sentAt' => $message->getSentAt()->format('Y-m-d H:i:s'),
                    'read' => $message->getReadAt() !== null
                ];
                
                // Para depuración: mostrar algunos detalles del primer mensaje
                if (count($formattedMessages) === 0) {
                    error_log("Primer mensaje: ID={$messageData['id']}, sender={$messageData['senderName']}, time={$messageData['sentAt']}");
                }
                
                $formattedMessages[] = $messageData;
            }
            
            // Obtener participantes activos
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
            
            // Registrar en el log de actividad
            $this->logActivity('room_access', [
                'userId' => $user->getId(),
                'roomId' => $roomId,
                'messagesCount' => count($messages),
                'participantsCount' => count($participants)
            ]);
            
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
        } catch (AccessDeniedException $e) {
            return $this->json([
                'success' => false,
                'error' => 'Acceso denegado: ' . $e->getMessage()
            ], 403);
        } catch (\Exception $e) {
            error_log('Error detallado al obtener detalles de sala: ' . $e->getMessage());
            error_log('Traza: ' . $e->getTraceAsString());
            
            return $this->json([
                'success' => false,
                'error' => 'Error al obtener los detalles de la sala: ' . $e->getMessage()
            ], 500);
        }
    }

    private function logActivity(string $action, array $data): void
{
    try {
        error_log(json_encode([
            'timestamp' => (new \DateTime())->format('Y-m-d H:i:s'),
            'action' => $action,
            'data' => $data
        ]));
    } catch (\Exception $e) {
        // Silenciar errores en el logging
    }
}
    
    #[Route('/rooms', name: 'create_room', methods: ['POST'])]
    public function createRoom(Request $request): JsonResponse
    {
        try {
            // Verificar que el usuario está autorizado
            $user = $this->getUser();
            if (!$user instanceof User) {
                throw new AccessDeniedException('Usuario no autenticado');
            }
            
            $data = json_decode($request->getContent(), true);
    
            if (!$data || !isset($data['name'])) {
                return $this->json(['success' => false, 'error' => 'Datos inválidos'], 400);
            }
    
            $name = $data['name'];
            $creatorId = (string) $user->getId();
            $creatorName = $user->getNombre() . ' ' . $user->getApellidos();
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
                    $participantUser = $this->entityManager->getRepository(User::class)->find($participantId);
                    $participantName = $participantUser ? $participantUser->getNombre() . ' ' . $participantUser->getApellidos() : 'Usuario ' . $participantId;
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
        } catch (AccessDeniedException $e) {
            return $this->json([
                'success' => false,
                'error' => 'Acceso denegado: ' . $e->getMessage()
            ], 403);
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
            
            if (!isset($data['content'])) {
                return $this->json([
                    'success' => false,
                    'error' => 'Falta el contenido del mensaje'
                ], 400);
            }
            
            // Usar los valores proporcionados en el cuerpo de la solicitud
            $senderId = $data['senderId'] ?? $request->query->get('userId', '1');
            $senderName = $data['senderName'] ?? $request->query->get('userName', 'Usuario' . $senderId);
            $content = $data['content'];
            $messageType = $data['type'] ?? 'text';
            
            // Llamar al servicio para enviar el mensaje
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
        try {
            // Verificar que el usuario está autorizado
            /** @var User $user */
            $user = $this->getUser();
            if (!$user instanceof User) {
                throw new AccessDeniedException('Usuario no autenticado');
            }
            
            // Verificar que el usuario es administrador o creador de la sala
            // Esta lógica debería implementarse en el servicio
            
            return $this->json([
                'success' => false,
                'error' => 'Método no implementado'
            ], 501);
        } catch (AccessDeniedException $e) {
            return $this->json([
                'success' => false,
                'error' => 'Acceso denegado: ' . $e->getMessage()
            ], 403);
        }
    }
    
    #[Route('/rooms/{roomId}/participants/{participantId}', name: 'remove_participant', methods: ['DELETE'])]
    public function removeParticipant(string $roomId, string $participantId): JsonResponse
    {
        try {
            // Verificar que el usuario está autorizado
            /** @var User $user */
            $user = $this->getUser();
            if (!$user instanceof User) {
                throw new AccessDeniedException('Usuario no autenticado');
            }
            
            // Solo permitir que el usuario elimine su propia participación o sea admin
            $isAdmin = $this->chatService->isAdmin($roomId, (string)$user->getId());
            $isSelf = $participantId === (string)$user->getId();
            
            if (!$isAdmin && !$isSelf) {
                throw new AccessDeniedException('No tienes permiso para eliminar a este participante');
            }
            
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
        } catch (AccessDeniedException $e) {
            return $this->json([
                'success' => false,
                'error' => 'Acceso denegado: ' . $e->getMessage()
            ], 403);
        } catch (\Exception $e) {
            return $this->json([
                'success' => false,
                'error' => 'Error al eliminar el participante: ' . $e->getMessage()
            ], 500);
        }
    }
    
    #[Route('/user/rooms', name: 'user_rooms', methods: ['GET'])]
    public function getUserRooms(): JsonResponse
    {
        try {
            // Verificar que el usuario está autorizado
            /** @var User $user */
            $user = $this->getUser();
            if (!$user instanceof User) {
                throw new AccessDeniedException('Usuario no autenticado');
            }
            
            $userId = (string) $user->getId();
            
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
        } catch (AccessDeniedException $e) {
            return $this->json([
                'success' => false,
                'error' => 'Acceso denegado: ' . $e->getMessage()
            ], 403);
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
            // Verificar que el usuario está autorizado
            /** @var User $user */
            $user = $this->getUser();
            if (!$user instanceof User) {
                throw new AccessDeniedException('Usuario no autenticado');
            }
            
            // Verificar que el usuario tiene acceso a esta sala
            if (!$this->chatService->isParticipant($roomId, (string)$user->getId())) {
                throw new AccessDeniedException('No tienes permiso para acceder a esta sala');
            }
            
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
        } catch (AccessDeniedException $e) {
            return $this->json([
                'success' => false,
                'error' => 'Acceso denegado: ' . $e->getMessage()
            ], 403);
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
        try {
            // Verificar que el usuario está autorizado
            /** @var User $user */
            $user = $this->getUser();
            if (!$user instanceof User) {
                throw new AccessDeniedException('Usuario no autenticado');
            }
            
            $query = $request->query->get('q', '');
            
            if (strlen($query) < 2) {
                return $this->json([
                    'success' => true,
                    'users' => []
                ]);
            }
            
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
            foreach ($results as $searchUser) {
                // No incluir al usuario actual en los resultados
                if ($searchUser->getId() !== $user->getId()) {
                    $users[] = [
                        'id' => $searchUser->getId(),
                        'nombre' => $searchUser->getNombre() . ' ' . $searchUser->getApellidos(),
                        'email' => $searchUser->getEmail()
                    ];
                }
            }
            
            return $this->json([
                'success' => true,
                'users' => $users
            ]);
        } catch (AccessDeniedException $e) {
            return $this->json([
                'success' => false,
                'error' => 'Acceso denegado: ' . $e->getMessage()
            ], 403);
        } catch (\Exception $e) {
            return $this->json([
                'success' => false,
                'error' => 'Error al buscar usuarios: ' . $e->getMessage()
            ], 500);
        }
    }
}