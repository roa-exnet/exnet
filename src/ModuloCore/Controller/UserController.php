<?php

namespace App\ModuloCore\Controller;

use App\ModuloCore\Entity\User;
use App\ModuloCore\Service\JwtAuthService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

#[Route('/admin')]
class UserController extends AbstractController
{
    use JwtAuthControllerTrait;

    private EntityManagerInterface $entityManager;
    private UserPasswordHasherInterface $passwordHasher;
    private JwtAuthService $jwtAuthService;

    public function __construct(
        EntityManagerInterface $entityManager,
        UserPasswordHasherInterface $passwordHasher,
        JwtAuthService $jwtAuthService
    ) {
        $this->entityManager = $entityManager;
        $this->passwordHasher = $passwordHasher;
        $this->jwtAuthService = $jwtAuthService;
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

        return $this->render('admin/users/index.html.twig', [
            'users' => $users,
            'current_user' => $user
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

            if (!empty($ipAddress) && $ipAddress !== $userToEdit->getIpAddress()) {
                $existingUserWithIp = $this->entityManager->getRepository(User::class)->findOneBy(['ip_address' => $ipAddress]);
                if ($existingUserWithIp && $existingUserWithIp->getId() !== $userToEdit->getId()) {
                    $errors[] = 'La dirección IP ya está asignada a otro usuario.';
                }
            }

            if (empty($errors)) {

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

        if ($userToDelete->getId() === $user->getId()) {
            $this->addFlash('error', 'No puedes eliminarte a ti mismo.');
            return $this->redirectToRoute('admin_users_index');
        }

        $this->entityManager->remove($userToDelete);
        $this->entityManager->flush();

        $this->addFlash('success', 'Usuario eliminado exitosamente.');
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

        $targetUser->setIsActive(!$targetUser->isIsActive());
        $this->entityManager->flush();

        $status = $targetUser->isIsActive() ? 'activado' : 'desactivado';
        $this->addFlash('success', "Usuario {$status} exitosamente.");
        return $this->redirectToRoute('admin_users_index');
    }
}