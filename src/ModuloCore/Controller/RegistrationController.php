<?php

namespace App\ModuloCore\Controller;

use App\ModuloCore\Entity\User;
use App\ModuloCore\Form\RegistrationFormType;
use App\ModuloCore\Service\IpAuthService;
use App\ModuloCore\Service\JwtAuthService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Routing\Annotation\Route;

class RegistrationController extends AbstractController
{
    private EntityManagerInterface $entityManager;
    private UserPasswordHasherInterface $passwordHasher;
    private IpAuthService $ipAuthService;
    private JwtAuthService $jwtAuthService;

    public function __construct(
        EntityManagerInterface $entityManager,
        UserPasswordHasherInterface $passwordHasher,
        IpAuthService $ipAuthService,
        JwtAuthService $jwtAuthService
    ) {
        $this->entityManager = $entityManager;
        $this->passwordHasher = $passwordHasher;
        $this->ipAuthService = $ipAuthService;
        $this->jwtAuthService = $jwtAuthService;
    }

    #[Route('/register', name: 'app_register')]
    public function register(Request $request): Response
    {
        $existingUser = $this->jwtAuthService->getAuthenticatedUser($request);
        if ($existingUser) {
            return $this->redirectToRoute('landing');
        }
        
        $user = new User();
        $form = $this->createForm(RegistrationFormType::class, $user);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $user->setPassword(
                $this->passwordHasher->hashPassword(
                    $user,
                    $form->get('plainPassword')->getData()
                )
            );

            $this->entityManager->persist($user);
            
            $this->ipAuthService->registerUserIp($user);
            
            $this->entityManager->flush();

            $this->addFlash('success', '¡Tu cuenta ha sido creada! Ahora puedes iniciar sesión.');

            $redirect = $request->query->get('redirect');
            if ($redirect) {
                $response = $this->redirect($redirect);
            } else {
                $response = $this->redirectToRoute('app_login');
            }
            
            $this->jwtAuthService->addTokenCookie($response, $user);
            
            return $response;
        }

        return $this->render('registration/register.html.twig', [
            'registrationForm' => $form,
            'currentIp' => $this->ipAuthService->getCurrentIp()
        ]);
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
                    $this->ipAuthService->registerUserIp($existingUser);
                    
                    $redirectUrl = $request->query->get('redirect');
                    $response = $redirectUrl ? $this->redirect($redirectUrl) : $this->redirectToRoute('landing');
                    
                    $this->jwtAuthService->addTokenCookie($response, $existingUser);
                    
                    return $response;
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
    
    #[Route('/api/auth/generate-token', name: 'app_generate_token', methods: ['POST'])]
    public function generateToken(Request $request): Response
    {
        $data = json_decode($request->getContent(), true);
        
        if (!isset($data['userId']) && !isset($data['email'])) {
            return $this->json([
                'success' => false,
                'error' => 'Se requiere userId o email'
            ], 400);
        }
        
        $userRepository = $this->entityManager->getRepository(User::class);
        $user = null;
        
        if (isset($data['userId'])) {
            $user = $userRepository->find($data['userId']);
        } else {
            $user = $userRepository->findOneByEmail($data['email']);
        }
        
        if (!$user) {
            return $this->json([
                'success' => false,
                'error' => 'Usuario no encontrado'
            ], 404);
        }
        
        $token = $this->jwtAuthService->generateToken($user);
        
        return $this->json([
            'success' => true,
            'token' => $token,
            'userId' => $user->getId(),
            'userName' => $user->getNombre() . ' ' . $user->getApellidos()
        ]);
    }
}