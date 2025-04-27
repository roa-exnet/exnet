<?php

namespace App\ModuloCore\Controller;

use App\ModuloCore\Entity\User;
use App\ModuloCore\Service\JwtAuthService;
use App\ModuloCore\Service\EncryptionService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Psr\Log\LoggerInterface;

#[Route('/admin')]
class UserController extends AbstractController
{
    use JwtAuthControllerTrait;

    private EntityManagerInterface $entityManager;
    private UserPasswordHasherInterface $passwordHasher;
    private JwtAuthService $jwtAuthService;
    private ?EncryptionService $encryptionService;
    private ?LoggerInterface $logger;

    public function __construct(
        EntityManagerInterface $entityManager,
        UserPasswordHasherInterface $passwordHasher,
        JwtAuthService $jwtAuthService,
        EncryptionService $encryptionService = null,
        LoggerInterface $logger = null
    ) {
        $this->entityManager = $entityManager;
        $this->passwordHasher = $passwordHasher;
        $this->jwtAuthService = $jwtAuthService;
        $this->encryptionService = $encryptionService;
        $this->logger = $logger;
    }

    #[Route('/usuarios', name: 'admin_users_index')]
    public function index(Request $request): Response
    {
        $user = $this->requireJwtRoles($request, ['ROLE_ADMIN']);
        if ($user instanceof Response) {
            $this->addFlash('error', 'No tienes permisos suficientes para acceder a esta sección.');
            return $this->redirectToRoute('landing');
        }

        $users = $this->entityManager->getRepository(User::class)->findAll();
        
        // Asegurar que cada usuario tenga el servicio de cifrado para descifrar datos
        if ($this->encryptionService) {
            foreach ($users as $user) {
                $user->setEncryptionService($this->encryptionService);
            }
            if ($this->logger) {
                $this->logger->debug('Servicio de cifrado inyectado a ' . count($users) . ' usuarios en la lista');
            }
        }

        return $this->render('admin/users/index.html.twig', [
            'users' => $users,
            'current_user' => $user,
            'encryption_service' => $this->encryptionService
        ]);
    }

    #[Route('/usuarios/{id}/editar', name: 'admin_users_edit', methods: ['GET', 'POST'])]
    public function edit(Request $request, int $id): Response
    {
        $user = $this->requireJwtRoles($request, ['ROLE_ADMIN']);
        if ($user instanceof Response) {
            $this->addFlash('error', 'No tienes permisos suficientes para acceder a esta sección.');
            return $this->redirectToRoute('landing');
        }

        $userToEdit = $this->entityManager->getRepository(User::class)->find($id);

        if (!$userToEdit) {
            $this->addFlash('error', 'Usuario no encontrado.');
            return $this->redirectToRoute('admin_users_index');
        }

        // Inyectar servicio de cifrado para obtener datos descifrados
        if ($this->encryptionService) {
            $userToEdit->setEncryptionService($this->encryptionService);
            
            if ($this->logger) {
                $this->logger->debug('Servicio de cifrado inyectado al usuario a editar ID: ' . $userToEdit->getId());
            }
        }

        if ($request->isMethod('POST')) {
            $nombre = $request->request->get('nombre');
            $apellidos = $request->request->get('apellidos');
            $ipAddress = $request->request->get('ip_address');
            $isAdmin = $request->request->get('is_admin') === 'on';
            $isActive = $request->request->get('is_active') === 'on';

            $errors = [];
            if (empty($nombre)) {
                $errors[] = 'El nombre es obligatorio.';
            }
            if (empty($apellidos)) {
                $errors[] = 'Los apellidos son obligatorios.';
            }

            if (!empty($ipAddress) && !filter_var($ipAddress, FILTER_VALIDATE_IP)) {
                $errors[] = 'La dirección IP no es válida.';
            }

            // Comprobar IP duplicada (con los datos descifrados)
            if (!empty($ipAddress) && $ipAddress !== $userToEdit->getIpAddress()) {
                $allUsers = $this->entityManager->getRepository(User::class)->findAll();
                $existingUserWithIp = null;
                
                foreach ($allUsers as $u) {
                    if ($this->encryptionService) {
                        $u->setEncryptionService($this->encryptionService);
                    }
                    
                    if ($u->getIpAddress() === $ipAddress && $u->getId() !== $userToEdit->getId()) {
                        $existingUserWithIp = $u;
                        break;
                    }
                }
                
                if ($existingUserWithIp) {
                    $errors[] = 'La dirección IP ya está asignada a otro usuario.';
                }
            }

            if (empty($errors)) {
                // Aplicar los cambios (los setters cifrarán automáticamente)
                $userToEdit->setNombre($nombre);
                $userToEdit->setApellidos($apellidos);
                $userToEdit->setIsActive($isActive);
                $userToEdit->setIpAddress($ipAddress ?: null);

                if ($isAdmin) {
                    $userToEdit->setRoles(['ROLE_ADMIN']);
                } else {
                    $userToEdit->setRoles(['ROLE_USER']);
                }

                $this->entityManager->flush();

                if ($this->logger) {
                    $this->logger->info('Usuario actualizado: ID=' . $userToEdit->getId());
                }

                $this->addFlash('success', 'Usuario actualizado exitosamente.');
                return $this->redirectToRoute('admin_users_index');
            }

            foreach ($errors as $error) {
                $this->addFlash('error', $error);
            }
        }

        return $this->render('admin/users/edit.html.twig', [
            'user' => $userToEdit,
            'current_user' => $user
        ]);
    }

    #[Route('/usuarios/{id}/eliminar', name: 'admin_users_delete', methods: ['POST'])]
    public function delete(Request $request, int $id): Response
    {
        $user = $this->requireJwtRoles($request, ['ROLE_ADMIN']);
        if ($user instanceof Response) {
            $this->addFlash('error', 'No tienes permisos suficientes para acceder a esta sección.');
            return $this->redirectToRoute('landing');
        }
    
        if (!$this->isCsrfTokenValid('delete_user_'.$id, $request->request->get('_token'))) {
            $this->addFlash('error', 'Token de seguridad inválido.');
            return $this->redirectToRoute('admin_users_index');
        }
    
        $userToDelete = $this->entityManager->getRepository(User::class)->find($id);
    
        if (!$userToDelete) {
            $this->addFlash('error', 'Usuario no encontrado.');
            return $this->redirectToRoute('admin_users_index');
        }
    
        // Inyectar el servicio de encriptación al usuario a eliminar
        if ($this->encryptionService) {
            $userToDelete->setEncryptionService($this->encryptionService);
        }
    
        if ($userToDelete->getId() === $user->getId()) {
            $this->addFlash('error', 'No puedes eliminarte a ti mismo.');
            return $this->redirectToRoute('admin_users_index');
        }
    
        // Intenta eliminar el usuario
        try {
            $this->entityManager->remove($userToDelete);
            $this->entityManager->flush();
            $this->addFlash('success', 'Usuario eliminado exitosamente.');
        } catch (\Exception $e) {
            $this->addFlash('error', 'Error al eliminar el usuario: ' . $e->getMessage());
            if ($this->logger) {
                $this->logger->error('Error al eliminar usuario: ' . $e->getMessage());
            }
        }
    
        return $this->redirectToRoute('admin_users_index');
    }

    #[Route('/usuarios/{id}/toggle-active', name: 'admin_users_toggle_active', methods: ['POST'])]
    public function toggleActive(Request $request, int $id): Response
    {
        $user = $this->requireJwtRoles($request, ['ROLE_ADMIN']);
        if ($user instanceof Response) {
            $this->addFlash('error', 'No tienes permisos suficientes para acceder a esta sección.');
            return $this->redirectToRoute('landing');
        }

        if (!$this->isCsrfTokenValid('toggle_active_'.$id, $request->request->get('_token'))) {
            $this->addFlash('error', 'Token de seguridad inválido.');
            return $this->redirectToRoute('admin_users_index');
        }

        $targetUser = $this->entityManager->getRepository(User::class)->find($id);

        if (!$targetUser) {
            $this->addFlash('error', 'Usuario no encontrado.');
            return $this->redirectToRoute('admin_users_index');
        }

        if ($targetUser->getId() === $user->getId()) {
            $this->addFlash('error', 'No puedes desactivar tu propia cuenta.');
            return $this->redirectToRoute('admin_users_index');
        }

        // Inyectar encriptación para verificar datos descifrados si es necesario
        if ($this->encryptionService) {
            $targetUser->setEncryptionService($this->encryptionService);
        }

        $targetUser->setIsActive(!$targetUser->isIsActive());
        $this->entityManager->flush();

        $status = $targetUser->isIsActive() ? 'activado' : 'desactivado';
        $this->addFlash('success', "Usuario {$status} exitosamente.");
        return $this->redirectToRoute('admin_users_index');
    }
    
    #[Route('/test-decrypt', name: 'admin_test_decrypt')]
    public function testDecrypt(Request $request): Response
    {
        $user = $this->requireJwtRoles($request, ['ROLE_ADMIN']);
        if ($user instanceof Response) {
            return $user;
        }
        
        $users = $this->entityManager->getRepository(User::class)->findAll();
        $result = [];
        
        foreach ($users as $user) {
            $rawEmail = $user->getEmail();
            $rawNombre = $user->getNombre();
            $rawApellidos = $user->getApellidos();
            
            if ($this->encryptionService) {
                $user->setEncryptionService($this->encryptionService);
            }
            
            $result[] = [
                'id' => $user->getId(),
                'email_raw' => $rawEmail,
                'email_decrypt' => $user->getEmail(),
                'nombre_raw' => $rawNombre,
                'nombre_decrypt' => $user->getNombre(),
                'apellidos_raw' => $rawApellidos,
                'apellidos_decrypt' => $user->getApellidos()
            ];
        }
        
        return $this->json($result);
    }
}