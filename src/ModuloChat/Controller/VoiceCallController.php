<?php

namespace App\ModuloChat\Controller;

use App\ModuloChat\Service\ChatService;
use App\ModuloCore\Entity\User;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/chat/voice', name: 'chat_voice_')]
#[IsGranted('IS_AUTHENTICATED_REMEMBERED')]
class VoiceCallController extends AbstractController
{
    private ChatService $chatService;

    public function __construct(ChatService $chatService)
    {
        $this->chatService = $chatService;
    }

    #[Route('/call/{roomId}', name: 'call', methods: ['POST'])]
    public function initiateCall(string $roomId): JsonResponse
    {
        /** @var User $user */
        $user = $this->getUser();
        $userId = (string) $user->getId();
        $userName = $user->getNombre() . ' ' . $user->getApellidos();

        if (!$this->chatService->isParticipant($roomId, $userId)) {
            return $this->json([
                'success' => false,
                'error' => 'No tienes permiso para realizar llamadas en esta sala'
            ], 403);
        }

        $callId = 'call_' . uniqid();
        
        return $this->json([
            'success' => true,
            'callId' => $callId,
            'roomId' => $roomId,
            'userId' => $userId,
            'userName' => $userName
        ]);
    }

    #[Route('/call/{roomId}/end', name: 'end_call', methods: ['POST'])]
    public function endCall(string $roomId, Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $this->getUser();
        $userId = (string) $user->getId();

        if (!$this->chatService->isParticipant($roomId, $userId)) {
            return $this->json([
                'success' => false,
                'error' => 'No tienes permiso para esta acción'
            ], 403);
        }

        $userName = $user->getNombre() . ' ' . $user->getApellidos();
        
        return $this->json([
            'success' => true,
            'message' => 'Llamada finalizada'
        ]);
    }
}