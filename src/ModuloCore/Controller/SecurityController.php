<?php

namespace App\ModuloCore\Controller;

use App\ModuloCore\Entity\User;
use App\ModuloCore\Service\IpAuthService;
use App\ModuloCore\Service\JwtAuthService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Security\Http\Authentication\AuthenticationUtils;

class SecurityController extends AbstractController
{
    private IpAuthService $ipAuthService;
    private EntityManagerInterface $entityManager;
    private JwtAuthService $jwtAuthService;

    public function __construct(
        IpAuthService $ipAuthService,
        EntityManagerInterface $entityManager,
        JwtAuthService $jwtAuthService
    ) {
        $this->ipAuthService = $ipAuthService;
        $this->entityManager = $entityManager;
        $this->jwtAuthService = $jwtAuthService;
    }

    #[Route('/login', name: 'app_login')]
    public function login(
        AuthenticationUtils $authenticationUtils, 
        Request $request
    ): Response
    {
        // Verificar si ya hay un usuario autenticado (por JWT o IP)
        $user = $this->jwtAuthService->getAuthenticatedUser($request);
        
        // Si ya hay un usuario autenticado, redirigir
        if ($user) {
            $redirect = $request->query->get('redirect');
            if ($redirect) {
                return $this->redirect($redirect);
            }
            return $this->redirectToRoute('landing');
        }
        
        // Verificar si la IP está registrada (login automático por IP)
        if ($this->ipAuthService->isIpRegistered()) {
            $ipUser = $this->ipAuthService->getCurrentUser();
            if ($ipUser) {
                $response = $this->redirectToRoute('landing');
                // Generar JWT para el usuario de IP
                $this->jwtAuthService->addTokenCookie($response, $ipUser);
                return $response;
            }
        }
        
        // Si no hay autenticación, continuar con el flujo normal de login
        $error = $authenticationUtils->getLastAuthenticationError();
        $lastUsername = $authenticationUtils->getLastUsername();

        return $this->render('security/login.html.twig', [
            'last_username' => $lastUsername,
            'error' => $error,
            'currentIp' => $this->ipAuthService->getCurrentIp()
        ]);
    }

    #[Route('/logout', name: 'app_logout')]
    public function logout(): void
    {
        throw new \LogicException('Este método nunca debe ser llamado directamente - configura el logout en security.yaml');
    }
    
    #[Route('/jwt-logout', name: 'app_jwt_logout')]
    public function jwtLogout(Request $request): Response
    {
        $response = $this->redirectToRoute('landing');
        // Eliminar la cookie del JWT
        $this->jwtAuthService->removeTokenCookie($response);
        
        return $response;
    }
    
    #[Route('/api/auth/verify-token', name: 'auth_verify_token', methods: ['POST'])]
    public function verifyToken(Request $request): Response
    {
        $data = json_decode($request->getContent(), true);
        
        // Si se proporciona un JWT en la solicitud, usamos eso
        $jwtToken = $data['jwt'] ?? null;
        if ($jwtToken) {
            $payload = $this->jwtAuthService->verifyToken($jwtToken);
            if ($payload) {
                $userRepository = $this->entityManager->getRepository(User::class);
                $user = $userRepository->find($payload['uid']);
                
                if ($user) {
                    return $this->json([
                        'success' => true,
                        'valid' => true,
                        'userId' => $user->getId(),
                        'userName' => $user->getNombre() . ' ' . $user->getApellidos(),
                        'authMethod' => 'jwt'
                    ]);
                }
            }
            
            return $this->json([
                'success' => false,
                'error' => 'Token JWT inválido'
            ], 401);
        }
        
        // Compatibilidad con el sistema antiguo
        if (!isset($data['token'])) {
            return $this->json([
                'success' => false,
                'error' => 'Token no proporcionado'
            ], 400);
        }
        
        $token = $data['token'];
        $userId = $data['userId'] ?? null;
        
        if ($userId) {
            $userRepository = $this->entityManager->getRepository(User::class);
            $user = $userRepository->find($userId);
            if (!$user) {
                return $this->json([
                    'success' => false,
                    'error' => 'Usuario no encontrado'
                ], 404);
            }
            
            $isValid = $this->ipAuthService->validateUserToken($user, $token);
            
            return $this->json([
                'success' => true,
                'valid' => $isValid,
                'userId' => $user->getId(),
                'userName' => $user->getNombre() . ' ' . $user->getApellidos(),
                'authMethod' => 'legacy'
            ]);
        }
        
        $user = $this->ipAuthService->getCurrentUser();
        if (!$user) {
            return $this->json([
                'success' => false,
                'error' => 'No se pudo determinar el usuario actual',
                'needsRegistration' => true
            ], 404);
        }
        
        $isValid = $this->ipAuthService->validateUserToken($user, $token);
        
        return $this->json([
            'success' => true,
            'valid' => $isValid,
            'userId' => $user->getId(),
            'userName' => $user->getNombre() . ' ' . $user->getApellidos(),
            'authMethod' => 'legacy'
        ]);
    }
}