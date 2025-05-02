<?php

namespace App\ModuloCore\Controller;

use App\ModuloCore\Service\BackupService;
use App\ModuloCore\Controller\JwtAuthControllerTrait;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\ResponseHeaderBag;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;
use Psr\Log\LoggerInterface;

class BackupController extends AbstractController
{
    use JwtAuthControllerTrait;
    
    private BackupService $backupService;
    private $logger;
    
    public function __construct(BackupService $backupService, LoggerInterface $logger)
    {
        $this->backupService = $backupService;
        $this->logger = $logger;
    }
    
    #[Route('/backups', name: 'backups_index')]
    public function index(Request $request): Response
    {
        // Verificar que el usuario es administrador
        $user = $this->requireJwtRoles($request, ['ROLE_ADMIN']);
        if ($user instanceof Response) {
            $this->addFlash('error', 'Acceso denegado: se requiere rol de administrador.');
            return $this->redirectToRoute('landing');
        }
        
        $backups = $this->backupService->getBackupsList();
        $stats = $this->backupService->getBackupStats();
        
        $response = $this->render('backup/index.html.twig', [
            'backups' => $backups,
            'stats' => $stats,
        ]);
        
        $response->headers->set('Cache-Control', 'no-cache, no-store, must-revalidate');
        $response->headers->set('Pragma', 'no-cache');
        $response->headers->set('Expires', '0');
        
        return $response;
    }
    
    #[Route('/backups/create', name: 'backups_create', methods: ['GET', 'POST'])]
    public function create(Request $request): Response
    {
        $user = $this->requireJwtRoles($request, ['ROLE_ADMIN']);
        if ($user instanceof Response) {
            $this->addFlash('error', 'Acceso denegado: se requiere rol de administrador.');
            return $this->redirectToRoute('landing');
        }

        if ($request->isMethod('POST')) {
            $name = $request->request->get('name');
            $description = $request->request->get('description');

            if (empty($name)) {
                $this->addFlash('error', 'El nombre del backup es obligatorio.');
                return $this->redirectToRoute('backups_create');
            }

            try {
                $this->backupService->createBackup($name, $description);
                $this->addFlash('success', 'Backup de base de datos creado correctamente.');
                return $this->redirectToRoute('backups_index');
            } catch (\Exception $e) {
                $this->logger->error('Error al crear backup: ' . $e->getMessage());
                $this->addFlash('error', 'Error al crear el backup: ' . $e->getMessage());
            }
        }

        return $this->render('backup/create.html.twig', []);
    }
    
    #[Route('/backups/{id}/download', name: 'backups_download')]
    public function download(Request $request, string $id): Response
    {
        $user = $this->requireJwtRoles($request, ['ROLE_ADMIN']);
        if ($user instanceof Response) {
            $this->addFlash('error', 'Acceso denegado: se requiere rol de administrador.');
            return $this->redirectToRoute('landing');
        }
        
        try {
            $backupFile = $this->backupService->getBackupFile($id);
            return $this->file($backupFile['path'], $backupFile['filename']);
        } catch (\Exception $e) {
            $this->addFlash('error', 'Error al descargar el backup: ' . $e->getMessage());
            return $this->redirectToRoute('backups_index');
        }
    }
    
    #[Route('/backups/{id}/restore', name: 'backups_restore', methods: ['POST'])]
    public function restore(Request $request, string $id): Response
    {
        $user = $this->requireJwtRoles($request, ['ROLE_ADMIN']);
        if ($user instanceof Response) {
            $this->addFlash('error', 'Acceso denegado: se requiere rol de administrador.');
            return $this->redirectToRoute('landing');
        }
        
        if (!$this->isCsrfTokenValid('restore_backup_' . $id, $request->request->get('_token'))) {
            $this->addFlash('error', 'Token CSRF inválido');
            return $this->redirectToRoute('backups_index');
        }
        
        try {
            $this->backupService->restoreBackup($id);
            $this->addFlash('success', 'Backup restaurado correctamente. Es posible que necesites recargar la página para ver los cambios.');
        } catch (\Exception $e) {
            $this->addFlash('error', 'Error al restaurar el backup: ' . $e->getMessage());
        }
        
        return $this->redirectToRoute('backups_index');
    }
    
    #[Route('/backups/{id}/delete', name: 'backups_delete', methods: ['POST'])]
    public function delete(Request $request, string $id): Response
    {
        $user = $this->requireJwtRoles($request, ['ROLE_ADMIN']);
        if ($user instanceof Response) {
            $this->addFlash('error', 'Acceso denegado: se requiere rol de administrador.');
            return $this->redirectToRoute('landing');
        }
        
        if (!$this->isCsrfTokenValid('delete_backup_' . $id, $request->request->get('_token'))) {
            $this->addFlash('error', 'Token CSRF inválido');
            return $this->redirectToRoute('backups_index');
        }
        
        try {
            $this->backupService->deleteBackup($id);
            $this->addFlash('success', 'Backup eliminado correctamente.');
        } catch (\Exception $e) {
            $this->addFlash('error', 'Error al eliminar el backup: ' . $e->getMessage());
        }
        
        return $this->redirectToRoute('backups_index');
    }
    
    #[Route('/api/backups/create', name: 'api_create', methods: ['POST'])]
    public function apiCreate(Request $request): JsonResponse
    {
        $auth = $this->requireJwtRoles($request, ['ROLE_ADMIN']);
        if ($auth instanceof JsonResponse) {
            return $auth;
        }
        
        $data = json_decode($request->getContent(), true);
        
        try {
            $name = $data['name'] ?? 'API Backup ' . date('Y-m-d H:i:s');
            $description = $data['description'] ?? null;
            
            $filename = $this->backupService->createBackup($name, $description);
            
            return $this->json([
                'success' => true,
                'message' => 'Backup de base de datos creado correctamente',
                'filename' => $filename
            ]);
        } catch (\Exception $e) {
            return $this->json([
                'success' => false,
                'error' => 'Error al crear backup: ' . $e->getMessage()
            ], 500);
        }
    }
}