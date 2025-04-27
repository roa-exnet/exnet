<?php

namespace App\ModuloChat\Controller;

use App\ModuloChat\Service\ChatService;
use App\ModuloCore\Service\IpAuthService;
use App\ModuloCore\Entity\User;
use App\ModuloChat\Entity\Chat;
use App\ModuloChat\Entity\ChatParticipant;
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
    private IpAuthService $ipAuthService;
    
    public function __construct(
        ChatService $chatService, 
        EntityManagerInterface $entityManager,
        IpAuthService $ipAuthService
    ) {
        $this->chatService = $chatService;
        $this->entityManager = $entityManager;
        $this->ipAuthService = $ipAuthService;
        $this->websocketUrl = 'https://websockettest.exnet.cloud';
    }
    
    #[Route('/', name: 'index')]
    public function index(Request $request): Response
    {
        if (!$this->ipAuthService->isIpRegistered()) {
            return $this->redirectToRoute('app_register_ip', [
                'redirect' => $request->getUri()
            ]);
        }
        
        $user = $this->ipAuthService->getCurrentUser();
        
        if (!$user instanceof User) {
            $securityUser = $this->getUser();
            if ($securityUser instanceof User) {
                $user = $securityUser;
                $this->ipAuthService->registerUserIp($user);
            } else {
                return $this->redirectToRoute('app_register_ip', [
                    'redirect' => $request->getUri()
                ]);
            }
        }
        
        $userToken = $this->ipAuthService->generateUserToken($user);
        
        $userId = (string) $user->getId();
        $userName = $user->getNombre() . ' ' . $user->getApellidos();
        
        return $this->render('@ModuloChat/chat.html.twig', [
            'userId' => $userId,
            'userName' => $userName,
            'websocketUrl' => $this->websocketUrl,
            'userToken' => $userToken
        ]);
    }
    
    #[Route('/rooms', name: 'rooms', methods: ['GET'])]
    public function getRooms(Request $request): JsonResponse
    {
        try {
            $user = $this->ipAuthService->getCurrentUser();
            
            if (!$user) {
                return $this->json([
                    'success' => false,
                    'error' => 'IP no registrada',
                    'needsRegistration' => true
                ], 401);
            }
            
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
    public function getRoomDetail(string $roomId, Request $request): JsonResponse
    {
        try {
            $user = $this->ipAuthService->getCurrentUser();
            
            if (!$user) {
                $token = $request->query->get('token');
                $userId = $request->query->get('userId');
                
                if ($token && $userId) {
                    $user = $this->entityManager->getRepository(User::class)->find($userId);
                    if ($user && !$this->ipAuthService->validateUserToken($user, $token)) {
                        $user = null;
                    }
                }
                
                if (!$user) {
                    throw new AccessDeniedException('Usuario no autenticado');
                }
            }
            
            if (!$this->chatService->isParticipant($roomId, (string)$user->getId())) {
                $userId = (string)$user->getId();
                $userName = $user->getNombre() . ' ' . $user->getApellidos();
                
                if ($this->chatService->addParticipantIfRoomExists($roomId, $userId, $userName)) {
                    error_log("Usuario {$userId} ({$userName}) agregado automáticamente a sala {$roomId}");
                } else {
                    throw new AccessDeniedException('No tienes permiso para acceder a esta sala');
                }
            }
            
            $result = $this->chatService->getRoom($roomId);
            
            if (!$result) {
                return $this->json([
                    'success' => false,
                    'error' => 'La sala de chat no existe o no se pudo cargar'
                ], 404);
            }
            
            $chat = $result['chat'];
            $messages = $result['messages'];
            
            error_log("Obtenidos " . count($messages) . " mensajes para sala {$roomId} en el controlador");
            
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
                
                if (count($formattedMessages) === 0) {
                    error_log("Primer mensaje: ID={$messageData['id']}, sender={$messageData['senderName']}, time={$messageData['sentAt']}");
                }
                
                $formattedMessages[] = $messageData;
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
                'error' => 'Acceso denegado: ' . $e->getMessage(),
                'needsRegistration' => !$this->ipAuthService->isIpRegistered()
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
        }
    }
    
    #[Route('/rooms', name: 'create_room', methods: ['POST'])]
    public function createRoom(Request $request): JsonResponse
    {
        try {
            $user = $this->ipAuthService->getCurrentUser();
            
            if (!$user) {
                $data = json_decode($request->getContent(), true);
                if (isset($data['token']) && isset($data['userId'])) {
                    $user = $this->entityManager->getRepository(User::class)->find($data['userId']);
                    if ($user && !$this->ipAuthService->validateUserToken($user, $data['token'])) {
                        $user = null;
                    }
                }
                
                if (!$user) {
                    throw new AccessDeniedException('Usuario no autenticado');
                }
            }
            
            $data = json_decode($request->getContent(), true);
    
            if (!$data || !isset($data['name'])) {
                return $this->json(['success' => false, 'error' => 'Datos inválidos'], 400);
            }
    
            $name = $data['name'];
            $creatorId = (string) $user->getId();
            $creatorName = $user->getNombre() . ' ' . $user->getApellidos();
            $participantIds = $data['participantIds'] ?? [];
    
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
                'error' => 'Acceso denegado: ' . $e->getMessage(),
                'needsRegistration' => !$this->ipAuthService->isIpRegistered()
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
            
            $user = null;
            if (isset($data['token']) && isset($data['senderId'])) {
                $user = $this->entityManager->getRepository(User::class)->find($data['senderId']);
                if ($user && !$this->ipAuthService->validateUserToken($user, $data['token'])) {
                    $user = null;
                }
            }
            
            if (!$user) {
                $user = $this->ipAuthService->getCurrentUser();
            }
            
            $senderId = $data['senderId'] ?? ($user ? (string)$user->getId() : $request->query->get('userId', '1'));
            $senderName = $data['senderName'] ?? ($user ? $user->getNombre() . ' ' . $user->getApellidos() : $request->query->get('userName', 'Usuario' . $senderId));
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
        try {
            $user = $this->ipAuthService->getCurrentUser();
            
            if (!$user) {
                $data = json_decode($request->getContent(), true);
                if (isset($data['token']) && isset($data['userId'])) {
                    $user = $this->entityManager->getRepository(User::class)->find($data['userId']);
                    if ($user && !$this->ipAuthService->validateUserToken($user, $data['token'])) {
                        $user = null;
                    }
                }
                
                if (!$user) {
                    throw new AccessDeniedException('Usuario no autenticado');
                }
            }
            
            return $this->json([
                'success' => false,
                'error' => 'Método no implementado'
            ], 501);
        } catch (AccessDeniedException $e) {
            return $this->json([
                'success' => false,
                'error' => 'Acceso denegado: ' . $e->getMessage(),
                'needsRegistration' => !$this->ipAuthService->isIpRegistered()
            ], 403);
        }
    }
    
    #[Route('/rooms/{roomId}/participants/{participantId}', name: 'remove_participant', methods: ['DELETE'])]
    public function removeParticipant(string $roomId, string $participantId, Request $request): JsonResponse
    {
        try {
            $user = $this->ipAuthService->getCurrentUser();
            
            if (!$user) {
                $token = $request->query->get('token');
                $userId = $request->query->get('userId');
                
                if ($token && $userId) {
                    $user = $this->entityManager->getRepository(User::class)->find($userId);
                    if ($user && !$this->ipAuthService->validateUserToken($user, $token)) {
                        $user = null;
                    }
                }
                
                if (!$user) {
                    throw new AccessDeniedException('Usuario no autenticado');
                }
            }
            
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
                'error' => 'Acceso denegado: ' . $e->getMessage(),
                'needsRegistration' => !$this->ipAuthService->isIpRegistered()
            ], 403);
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
            $user = $this->ipAuthService->getCurrentUser();
            
            if (!$user) {
                $token = $request->query->get('token');
                $userId = $request->query->get('userId');
                
                if ($token && $userId) {
                    $user = $this->entityManager->getRepository(User::class)->find($userId);
                    if ($user && !$this->ipAuthService->validateUserToken($user, $token)) {
                        $user = null;
                    }
                }
                
                if (!$user) {
                    throw new AccessDeniedException('Usuario no autenticado');
                }
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
                'error' => 'Acceso denegado: ' . $e->getMessage(),
                'needsRegistration' => !$this->ipAuthService->isIpRegistered()
            ], 403);
        } catch (\Exception $e) {
            return $this->json([
                'success' => false,
                'error' => 'Error al obtener las salas de chat: ' . $e->getMessage()
            ], 500);
        }
    }
    
    #[Route('/rooms/{roomId}/messages/{messageId}/read', name: 'mark_message_read', methods: ['POST'])]
    public function markMessageAsRead(string $roomId, string $messageId, Request $request): JsonResponse
    {
        try {
            $user = $this->ipAuthService->getCurrentUser();
            
            if (!$user) {
                $data = json_decode($request->getContent(), true);
                if (isset($data['token']) && isset($data['userId'])) {
                    $user = $this->entityManager->getRepository(User::class)->find($data['userId']);
                    if ($user && !$this->ipAuthService->validateUserToken($user, $data['token'])) {
                        $user = null;
                    }
                }
                
                if (!$user) {
                    throw new AccessDeniedException('Usuario no autenticado');
                }
            }
            
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
                'error' => 'Acceso denegado: ' . $e->getMessage(),
                'needsRegistration' => !$this->ipAuthService->isIpRegistered()
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
            $user = $this->ipAuthService->getCurrentUser();
            
            if (!$user) {
                $token = $request->query->get('token');
                $userId = $request->query->get('userId');
                
                if ($token && $userId) {
                    $user = $this->entityManager->getRepository(User::class)->find($userId);
                    if ($user && !$this->ipAuthService->validateUserToken($user, $token)) {
                        $user = null;
                    }
                }
                
                if (!$user) {
                    throw new AccessDeniedException('Usuario no autenticado');
                }
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
                'error' => 'Acceso denegado: ' . $e->getMessage(),
                'needsRegistration' => !$this->ipAuthService->isIpRegistered()
            ], 403);
        } catch (\Exception $e) {
            return $this->json([
                'success' => false,
                'error' => 'Error al buscar usuarios: ' . $e->getMessage()
            ], 500);
        }
    }
}