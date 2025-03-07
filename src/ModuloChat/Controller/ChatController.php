<?php

namespace App\ModuloChat\Controller;

use App\ModuloChat\Entity\Chat;
use App\ModuloChat\Service\ChatService;
use App\ModuloChat\Service\MessageService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Mercure\PublisherInterface;
use Symfony\Component\Mercure\Update;
use Symfony\Component\Routing\Annotation\Route;

#[Route('/chat', name: 'chat_')]
class ChatController extends AbstractController
{
    private ChatService $chatService;
    private MessageService $messageService;
    private PublisherInterface $publisher;  // Inyectamos el servicio PublisherInterface

    public function __construct(ChatService $chatService, MessageService $messageService, PublisherInterface $publisher)
    {
        $this->chatService = $chatService;
        $this->messageService = $messageService;
        $this->publisher = $publisher;  // Iniciamos el publisher
    }

    #[Route('/', name: 'index')]
    public function index(): Response
    {
        $userId = $this->getUserIdentifier();
        
        $chats = $this->chatService->getActiveChatsForParticipant($userId);
        
        return $this->render('chat.html.twig', [
            'chats' => $chats,
            'userId' => $userId,
            'userName' => $this->getUserName()
        ]);
    }

    #[Route('/{id}', name: 'view', requirements: ['id' => '\d+'])]
    public function viewChat(Chat $chat): Response
    {
        $userId = $this->getUserIdentifier();
        
        $this->messageService->markMessagesAsRead($chat, $userId);
        
        $messages = $this->messageService->getChatMessages($chat, 50);
        
        return $this->render('chat_box.html.twig', [
            'chat' => $chat,
            'messages' => $messages,
            'userId' => $userId,
            'userName' => $this->getUserName()
        ]);
    }

    #[Route('/messages/{id}', name: 'messages', requirements: ['id' => '\d+'])]
    public function getMessages(Chat $chat, Request $request): Response
    {
        $limit = $request->query->getInt('limit', 50);
        $offset = $request->query->getInt('offset', 0);
        
        $messages = $this->messageService->getChatMessages($chat, $limit, $offset);
        
        // Renderizar solo los mensajes para cargas AJAX
        return $this->render('chat_messages.html.twig', [
            'messages' => $messages,
            'userId' => $this->getUserIdentifier()
        ]);
    }

    #[Route('/send/{id}', name: 'send_message', methods: ['POST'])]
    public function sendMessage(Chat $chat, Request $request): JsonResponse
    {
        $content = $request->request->get('content');
        $userId = $this->getUserIdentifier();
        $userName = $this->getUserName();
        
        if (empty($content)) {
            return $this->json(['success' => false, 'error' => 'El mensaje no puede estar vacío'], 400);
        }
        
        $message = $this->messageService->sendMessage(
            $chat,
            $userId,
            $userName,
            $content
        );
        
        if (!$message) {
            return $this->json(['success' => false, 'error' => 'No tienes permiso para enviar mensajes en este chat'], 403);
        }

        // Publicar el mensaje a través de Mercure
        $this->publisher->__invoke(
            new Update(
                'chat_' . $chat->getId(),  // Tema de Mercure
                json_encode([
                    'id' => $message->getId(),
                    'content' => $message->getContent(),
                    'sender' => $message->getSenderName(),
                    'senderId' => $message->getSenderIdentifier(),
                    'timestamp' => $message->getSentAt()->format('Y-m-d H:i:s')
                ]),
                true  // Esto permite que el mensaje se publique en tiempo real
            )
        );
        
        return $this->json([
            'success' => true,
            'message' => [
                'id' => $message->getId(),
                'content' => $message->getContent(),
                'sender' => $message->getSenderName(),
                'senderId' => $message->getSenderIdentifier(),
                'timestamp' => $message->getSentAt()->format('Y-m-d H:i:s')
            ]
        ]);
    }

    #[Route('/create/private', name: 'create_private', methods: ['POST'])]
    public function createPrivateChat(Request $request): JsonResponse
    {
        $recipientId = $request->request->get('recipientId');
        $recipientName = $request->request->get('recipientName');
        
        if (empty($recipientId)) {
            return $this->json(['success' => false, 'error' => 'Se requiere un destinatario'], 400);
        }
        
        $userId = $this->getUserIdentifier();
        $userName = $this->getUserName();
        
        $chat = $this->chatService->getOrCreatePrivateChat(
            $userId,
            $userName,
            $recipientId,
            $recipientName
        );
        
        if (count($chat->getMessages()) === 0) {
            $this->messageService->sendSystemMessage(
                $chat,
                'Chat iniciado'
            );
        }
        
        return $this->json([
            'success' => true,
            'chatId' => $chat->getId()
        ]);
    }

    private function getUserIdentifier(): string
    {
        return '123'; 
    }

    private function getUserName(): string
    {
        return 'Usuario de Prueba';
    }
}
