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
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Security\Http\Authentication\AuthenticationUtils;

class SecurityController extends AbstractController
{
    private IpAuthService $ipAuthService;
    private UserPasswordHasherInterface $passwordHasher;
    private EntityManagerInterface $entityManager;
    private JwtAuthService $jwtAuthService;

    public function __construct(
        IpAuthService $ipAuthService,
        UserPasswordHasherInterface $passwordHasher,
        EntityManagerInterface $entityManager,
        JwtAuthService $jwtAuthService
    ) {
        $this->ipAuthService = $ipAuthService;
        $this->passwordHasher = $passwordHasher;
        $this->entityManager = $entityManager;
        $this->jwtAuthService = $jwtAuthService;
    }

    #[Route('/register-ip', name: 'app_register_ip')]
    public function registerIp(Request $request): Response
    {
        if ($this->ipAuthService->isIpRegistered()) {
            $user = $this->ipAuthService->getCurrentUser();
            
            $redirect = $request->query->get('redirect');
            $response = $redirect ? $this->redirect($redirect) : $this->redirectToRoute('landing');
            
            if ($user) {
                $this->jwtAuthService->addTokenCookie($response, $user);
            }
            
            return $response;
        }
        
        $currentIp = $this->ipAuthService->getCurrentIp();
        
        if ($request->isMethod('POST')) {
            $email = $request->request->get('email');
            $nombre = $request->request->get('nombre');
            $apellidos = $request->request->get('apellidos');
            
            $errors = [];
            if (empty($email) || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
                $errors[] = 'Email inválido';
            }
            if (empty($nombre)) {
                $errors[] = 'El nombre es requerido';
            }
            if (empty($apellidos)) {
                $errors[] = 'Los apellidos son requeridos';
            }
            
            if (empty($errors)) {
                $userRepository = $this->entityManager->getRepository(User::class);
                
                $existingUser = $userRepository->findOneBy(['email' => $email]);
                
                if ($existingUser) {
                    $errors[] = 'Este email ya está registrado. Si es su cuenta, por favor inicie sesión.';
                    
                    return $this->render('registration/register_ip.html.twig', [
                        'currentIp' => $currentIp,
                        'errors' => $errors,
                        'email' => $email,
                        'nombre' => $nombre,
                        'apellidos' => $apellidos
                    ]);
                } else {
                    $user = new User();
                    $user->setEmail($email);
                    $user->setNombre($nombre);
                    $user->setApellidos($apellidos);
                    $user->setRoles(['ROLE_USER']);
                    $user->setPassword($this->passwordHasher->hashPassword(
                        $user,
                        uniqid()
                    ));
                    $user->setCreatedAt(new \DateTimeImmutable());
                    $user->setIsActive(true);
                    
                    $user->setIpAddress($currentIp);
                    
                    $this->entityManager->persist($user);
                    $this->entityManager->flush();
                    
                    $redirectUrl = $request->query->get('redirect');
                    $response = $redirectUrl ? $this->redirect($redirectUrl) : $this->redirectToRoute('landing');
                    
                    $this->jwtAuthService->addTokenCookie($response, $user);
                    
                    return $response;
                }
            }
            
            return $this->render('registration/register_ip.html.twig', [
                'currentIp' => $currentIp,
                'errors' => $errors,
                'email' => $email,
                'nombre' => $nombre,
                'apellidos' => $apellidos
            ]);
        }
        
        return $this->render('registration/register_ip.html.twig', [
            'currentIp' => $currentIp
        ]);
    }

    #[Route('/login', name: 'app_login')]
    public function login(
        AuthenticationUtils $authenticationUtils, 
        Request $request
    ): Response
    {
        $user = $this->jwtAuthService->getAuthenticatedUser($request);
        
        if ($user) {
            $redirect = $request->query->get('redirect');
            if ($redirect) {
                return $this->redirect($redirect);
            }
            return $this->redirectToRoute('landing');
        }
        
        if ($this->ipAuthService->isIpRegistered()) {
            $ipUser = $this->ipAuthService->getCurrentUser();
            if ($ipUser) {
                $response = $this->redirectToRoute('landing');
                $this->jwtAuthService->addTokenCookie($response, $ipUser);
                return $response;
            }
        }
        
        $error = $authenticationUtils->getLastAuthenticationError();
        $lastUsername = $authenticationUtils->getLastUsername();

        return $this->render('registration/register_ip.html.twig', [
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
        $this->jwtAuthService->removeTokenCookie($response);
        
        return $response;
    }
    
    #[Route('/api/auth/verify-token', name: 'auth_verify_token', methods: ['POST'])]
    public function verifyToken(Request $request): Response
    {
        $data = json_decode($request->getContent(), true);
        
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