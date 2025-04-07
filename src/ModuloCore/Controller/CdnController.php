<?php

namespace App\ModuloCore\Controller;

use App\ModuloCore\Service\CdnService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Annotation\Route;
use Doctrine\ORM\EntityManagerInterface;
use App\ModuloCore\Entity\Modulo;
use Symfony\Component\Process\Process;
use Symfony\Component\Process\Exception\ProcessFailedException;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\DependencyInjection\ParameterBag\ParameterBagInterface;

class CdnController extends AbstractController
{
    use JwtAuthControllerTrait;
    
    private CdnService $cdnService;
    private EntityManagerInterface $entityManager;
    private string $projectDir;
    private Filesystem $filesystem;
    
    public function __construct(
        CdnService $cdnService,
        EntityManagerInterface $entityManager,
        ParameterBagInterface $parameterBag
    ) {
        $this->cdnService = $cdnService;
        $this->entityManager = $entityManager;
        $this->projectDir = $parameterBag->get('kernel.project_dir');
        $this->filesystem = new Filesystem();
    }

    #[Route('/modulos/marketplace', name: 'modulos_marketplace')]
    public function marketplace(Request $request): Response
    {
        $auth = $this->requireJwtAuthentication($request);
        if ($auth instanceof Response) {
            return $auth;
        }
        
        $marketplace = $this->cdnService->getAvailableModules();
        $error = isset($marketplace['error']) ? $marketplace['error'] : null;
        
        return $this->render('marketplace.html.twig', [
            'marketplace' => $marketplace,
            'error' => $error
        ]);
    }
    
    #[Route('/api/modulos/marketplace', name: 'api_modulos_marketplace')]
    public function apiMarketplace(Request $request): JsonResponse
    {
        $marketplace = $this->cdnService->getAvailableModules();
        
        if (isset($marketplace['error'])) {
            return $this->json(['success' => false, 'error' => $marketplace['error']], 500);
        }
        
        return $this->json(['success' => true, 'marketplace' => $marketplace]);
    }
    
    #[Route('/api/modulos/verify-license', name: 'api_verify_license', methods: ['POST'])]
    public function verifyLicense(Request $request): JsonResponse
    {
        $data = json_decode($request->getContent(), true);
        
        if (!isset($data['licenseKey']) || !isset($data['moduleFilename'])) {
            return $this->json(['valid' => false, 'message' => 'Datos incompletos'], 400);
        }
        
        $result = $this->cdnService->verifyLicense($data['licenseKey'], $data['moduleFilename']);
        
        return $this->json($result);
    }
    
    #[Route('/api/modulos/install', name: 'api_install_module', methods: ['POST'])]
    public function installModule(Request $request): JsonResponse
    {
        $auth = $this->requireJwtRoles($request, ['ROLE_ADMIN']);
        if ($auth instanceof JsonResponse) {
            return $auth;
        }
        
        $data = json_decode($request->getContent(), true);
        
        if (!isset($data['moduleType']) || !isset($data['filename'])) {
            return $this->json(['success' => false, 'message' => 'Datos incompletos'], 400);
        }
        
        $downloadToken = $data['downloadToken'] ?? null;
        
        $result = $this->cdnService->installModule(
            $data['moduleType'],
            $data['filename'],
            $downloadToken
        );
        
        return $this->json($result);
    }
    
    #[Route('/modulos/{id}/uninstall', name: 'modulos_uninstall', methods: ['POST'])]
    public function uninstallModule(Request $request, int $id): Response
    {
        $auth = $this->requireJwtRoles($request, ['ROLE_ADMIN']);
        if ($auth instanceof Response) {
            return $auth;
        }
        
        $modulo = $this->entityManager->getRepository(Modulo::class)->find($id);
        
        if (!$modulo) {
            $this->addFlash('error', 'Módulo no encontrado');
            return $this->redirectToRoute('modulos_index');
        }
        
        $result = $this->executeUninstallCommandAndRemoveFiles($modulo);
        
        if ($result['success']) {
            $this->addFlash('success', $result['message']);
        } else {
            $this->addFlash('error', $result['message']);
        }
        
        return $this->redirectToRoute('modulos_index');
    }
    
    #[Route('/api/modulos/{id}/uninstall', name: 'api_uninstall_module', methods: ['POST'])]
    public function apiUninstallModule(Request $request, int $id): JsonResponse
    {
        try {
            $auth = $this->requireJwtRoles($request, ['ROLE_ADMIN']);
            if ($auth instanceof JsonResponse) {
                return $auth;
            }
            
            $modulo = $this->entityManager->getRepository(Modulo::class)->find($id);
            
            if (!$modulo) {
                return $this->json([
                    'success' => false,
                    'message' => 'Módulo no encontrado'
                ], 404);
            }
            
            $result = $this->executeUninstallCommandAndRemoveFiles($modulo);
            
            return $this->json($result);
        } catch (\Exception $e) {
            return $this->json([
                'success' => false,
                'message' => 'Error al procesar la solicitud: ' . $e->getMessage(),
                'stackTrace' => $e->getTraceAsString()
            ], 500);
        }
    }

    #[Route('/api/modulos/{id}/get-url', name: 'api_get_module_url', methods: ['GET'])]
    public function getModuleUrl(int $id): JsonResponse
    {
        try {
            $modulo = $this->entityManager->getRepository(Modulo::class)->find($id);
            
            if (!$modulo) {
                return $this->json([
                    'success' => false,
                    'message' => 'Módulo no encontrado'
                ], 404);
            }

            $menuElements = $modulo->getMenuElements();

            if ($menuElements->isEmpty()) {
                return $this->json([
                    'success' => false,
                    'message' => 'No se encontraron elementos de menú asociados al módulo'
                ], 404);
            }

            foreach ($menuElements as $menuElement) {
                if ($menuElement->getParentId() !== 0) {
                    return $this->json([
                        'success' => true,
                        'url' => $menuElement->getRuta()
                    ]);
                }
            }

            $firstMenuElement = $menuElements->first();

            return $this->json([
                'success' => true,
                'url' => $firstMenuElement ? $firstMenuElement->getRuta() : null
            ]);
        } catch (\Exception $e) {
            return $this->json([
                'success' => false,
                'message' => 'Error al obtener la URL del módulo: ' . $e->getMessage()
            ], 500);
        }
    }

    private function executeUninstallCommandAndRemoveFiles(Modulo $modulo): array
    {
        try {
            $moduleDirectory = $modulo->getRuta();
            $commandsJsonPath = $moduleDirectory ? $moduleDirectory . '/commands.json' : null;
            $commandOutput = '';
            $commandSuccess = false;

            if ($commandsJsonPath && $this->filesystem->exists($commandsJsonPath)) {
                $commandsJson = json_decode(file_get_contents($commandsJsonPath), true);
                $uninstallCommand = $commandsJson['uninstall'] ?? null;

                if ($uninstallCommand) {
                    $process = new Process(explode(' ', $uninstallCommand));
                    $process->setWorkingDirectory($this->projectDir);
                    $process->run();

                    if ($process->isSuccessful()) {
                        $commandOutput = $process->getOutput();
                        $commandSuccess = true;
                    } else {
                        $commandOutput = $process->getErrorOutput();
                        $commandSuccess = false;
                        return [
                            'success' => false,
                            'commandSuccess' => $commandSuccess,
                            'message' => 'Error al ejecutar el comando de desinstalación',
                            'commandOutput' => $commandOutput
                        ];
                    }
                } else {
                    $commandOutput = 'No se encontró un comando de desinstalación en commands.json';
                }
            } else {
                $commandOutput = 'No se encontró el archivo commands.json en el directorio del módulo';
            }

            $this->limpiarRelacionesModulo($modulo);

            $this->entityManager->remove($modulo);
            $this->entityManager->flush();

            if ($moduleDirectory && $this->filesystem->exists($moduleDirectory)) {
                try {
                    $this->filesystem->remove($moduleDirectory);
                    $commandOutput .= "\nCarpeta del módulo ($moduleDirectory) eliminada correctamente.";
                } catch (\Exception $e) {
                    $commandOutput .= "\nError al eliminar la carpeta del módulo: " . $e->getMessage();
                    return [
                        'success' => false,
                        'commandSuccess' => $commandSuccess,
                        'message' => 'Módulo desinstalado, pero no se pudo eliminar la carpeta',
                        'commandOutput' => $commandOutput
                    ];
                }
            } else {
                $commandOutput .= "\nNo se encontró la carpeta del módulo en la ruta especificada: $moduleDirectory";
            }

            return [
                'success' => true,
                'commandSuccess' => $commandSuccess,
                'message' => 'Módulo desinstalado y carpeta eliminada correctamente',
                'commandOutput' => $commandOutput
            ];
        } catch (\Exception $e) {
            return [
                'success' => false,
                'message' => 'Error al desinstalar el módulo: ' . $e->getMessage(),
                'commandOutput' => $commandOutput,
                'stackTrace' => $e->getTraceAsString()
            ];
        }
    }

    private function limpiarRelacionesModulo(Modulo $modulo): void
    {
        $menuElements = $modulo->getMenuElements();
        if ($menuElements) {
            foreach ($menuElements as $menuElement) {
                $modulo->removeMenuElement($menuElement);
            }
        }
        
        $this->entityManager->flush();
    }
    
    private function findModuleDirectory(string $moduleName): ?string
    {
        $srcDir = $this->projectDir . '/src';
        
        // $directPath = $srcDir . '/ModuloMusica';
        // if (is_dir($directPath)) {
        //     return $directPath;
        // }
        
        $exactPath = $srcDir . '/' . $moduleName;
        if (is_dir($exactPath)) {
            return $exactPath;
        }
        
        $moduloPath = $srcDir . '/Modulo' . $moduleName;
        if (is_dir($moduloPath)) {
            return $moduloPath;
        }
        
        $normalizedName = $this->normalizeString($moduleName);
        
        $dirContents = scandir($srcDir);
        
        foreach ($dirContents as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            
            $itemPath = $srcDir . '/' . $item;
            
            if (is_dir($itemPath)) {
                $normalizedItemName = $this->normalizeString($item);
                
                if ($normalizedItemName === $normalizedName || 
                    $normalizedItemName === 'modulo' . $normalizedName ||
                    strpos($normalizedItemName, $normalizedName) !== false) {
                    return $itemPath;
                }
            }
        }
        
        return null;
    }
    
    private function normalizeString(string $string): string
    {
        $string = strtolower($string);
        
        $replacements = [
            'á' => 'a', 'é' => 'e', 'í' => 'i', 'ó' => 'o', 'ú' => 'u',
            'ä' => 'a', 'ë' => 'e', 'ï' => 'i', 'ö' => 'o', 'ü' => 'u',
            'à' => 'a', 'è' => 'e', 'ì' => 'i', 'ò' => 'o', 'ù' => 'u',
            'ñ' => 'n', 'ç' => 'c'
        ];
        
        return str_replace(array_keys($replacements), array_values($replacements), $string);
    }
}