<?php

namespace App\ModuloCore\Controller;

use App\ModuloCore\Entity\User;
use App\ModuloCore\Service\IpAuthService;
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

    public function __construct(
        IpAuthService $ipAuthService,
        EntityManagerInterface $entityManager
    ) {
        $this->ipAuthService = $ipAuthService;
        $this->entityManager = $entityManager;
    }

    #[Route('/login', name: 'app_login')]
    public function login(
        AuthenticationUtils $authenticationUtils, 
        Request $request
    ): Response
    {
        $userByIp = $this->ipAuthService->getCurrentUser();
        
        if ($userByIp) {
            $redirect = $request->query->get('redirect');
            if ($redirect) {
                return $this->redirect($redirect);
            }
            return $this->redirectToRoute('landing');
        }
        
        if ($this->getUser()) {
            $redirect = $request->query->get('redirect');
            if ($redirect) {
                return $this->redirect($redirect);
            }
            return $this->redirectToRoute('landing');
        }

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
    
    #[Route('/api/auth/verify-token', name: 'auth_verify_token', methods: ['POST'])]
    public function verifyToken(Request $request): Response
    {
        $data = json_decode($request->getContent(), true);
        
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
                'userName' => $user->getNombre() . ' ' . $user->getApellidos()
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
            'userName' => $user->getNombre() . ' ' . $user->getApellidos()
        ]);
    }
}