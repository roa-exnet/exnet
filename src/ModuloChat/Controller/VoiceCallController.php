<?php

namespace App\ModuloChat\Controller;

use App\ModuloChat\Service\ChatService;
use App\ModuloCore\Entity\User;
use App\ModuloCore\Service\IpAuthService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;

#[Route('/chat/voice', name: 'chat_voice_')]
class VoiceCallController extends AbstractController
{
   private ChatService $chatService;
   private IpAuthService $ipAuthService;

   public function __construct(
       ChatService $chatService,
       IpAuthService $ipAuthService
   ) {
       $this->chatService = $chatService;
       $this->ipAuthService = $ipAuthService;
   }

   #[Route('/call/{roomId}', name: 'call', methods: ['POST'])]
   public function initiateCall(string $roomId, Request $request): JsonResponse
   {
       $user = $this->ipAuthService->getCurrentUser();
       
       if (!$user) {
           $data = json_decode($request->getContent(), true);
           if (isset($data['token']) && isset($data['userId'])) {
               $userRepository = $this->getDoctrine()->getRepository(User::class);
               $user = $userRepository->find($data['userId']);
               if ($user && !$this->ipAuthService->validateUserToken($user, $data['token'])) {
                   $user = null;
               }
           }
           
           if (!$user) {
               return $this->json([
                   'success' => false,
                   'error' => 'Usuario no autenticado',
                   'needsRegistration' => !$this->ipAuthService->isIpRegistered()
               ], 401);
           }
       }
       
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
       $user = $this->ipAuthService->getCurrentUser();
       
       if (!$user) {
           $data = json_decode($request->getContent(), true);
           if (isset($data['token']) && isset($data['userId'])) {
               $userRepository = $this->getDoctrine()->getRepository(User::class);
               $user = $userRepository->find($data['userId']);
               if ($user && !$this->ipAuthService->validateUserToken($user, $data['token'])) {
                   $user = null;
               }
           }
           
           if (!$user) {
               return $this->json([
                   'success' => false,
                   'error' => 'Usuario no autenticado',
                   'needsRegistration' => !$this->ipAuthService->isIpRegistered()
               ], 401);
           }
       }
       
       $userId = (string) $user->getId();
       $userName = $user->getNombre() . ' ' . $user->getApellidos();

       if (!$this->chatService->isParticipant($roomId, $userId)) {
           return $this->json([
               'success' => false,
               'error' => 'No tienes permiso para esta acción'
           ], 403);
       }
       
       return $this->json([
           'success' => true,
           'message' => 'Llamada finalizada'
       ]);
   }
}