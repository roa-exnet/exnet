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
use Psr\Log\LoggerInterface;

class CdnController extends AbstractController
{
    use JwtAuthControllerTrait;
    
    private CdnService $cdnService;
    private EntityManagerInterface $entityManager;
    private string $projectDir;
    private Filesystem $filesystem;
    private ?LoggerInterface $logger;
    
    public function __construct(
        CdnService $cdnService,
        EntityManagerInterface $entityManager,
        ParameterBagInterface $parameterBag,
        LoggerInterface $logger = null
    ) {
        $this->cdnService = $cdnService;
        $this->entityManager = $entityManager;
        $this->projectDir = $parameterBag->get('kernel.project_dir');
        $this->filesystem = new Filesystem();
        $this->logger = $logger;
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
            $this->logError("Error en apiUninstallModule: " . $e->getMessage());
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

            // Primero intentamos obtener la URL desde settings.json
            $moduleAttributes = $this->cdnService->getModuleAttributes($modulo);
            
            if (isset($moduleAttributes['route']) && !empty($moduleAttributes['route'])) {
                return $this->json([
                    'success' => true,
                    'url' => $moduleAttributes['route']
                ]);
            }

            // Si no hay ruta en settings.json, intentamos con el menú como respaldo
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
            $this->logError("Error en getModuleUrl: " . $e->getMessage());
            return $this->json([
                'success' => false,
                'message' => 'Error al obtener la URL del módulo: ' . $e->getMessage()
            ], 500);
        }
    }
    
    #[Route('/api/modulos/{id}/details', name: 'api_get_module_details', methods: ['GET'])]
    public function getModuleDetails(int $id): JsonResponse
    {
        try {
            $modulo = $this->entityManager->getRepository(Modulo::class)->find($id);
            
            if (!$modulo) {
                return $this->json([
                    'success' => false,
                    'message' => 'Módulo no encontrado'
                ], 404);
            }
            
            // Obtener atributos del settings.json
            $moduleAttributes = $this->cdnService->getModuleAttributes($modulo);
            
            // Añadir la información básica del modelo
            $moduleDetails = array_merge([
                'id' => $modulo->getId(),
                'nombre' => $modulo->getNombre(),
                'descripcion' => $modulo->getDescripcion(),
                'installDate' => $modulo->getInstallDate() ? $modulo->getInstallDate()->format('Y-m-d H:i:s') : null,
                'uninstallDate' => $modulo->getUninstallDate() ? $modulo->getUninstallDate()->format('Y-m-d H:i:s') : null,
                'icon' => $modulo->getIcon(),
                'ruta' => $modulo->getRuta(),
                'estado' => $modulo->isEstado(),
                'version' => $modulo->getVersion(),
            ], $moduleAttributes);
            
            return $this->json([
                'success' => true,
                'module' => $moduleDetails
            ]);
        } catch (\Exception $e) {
            $this->logError("Error en getModuleDetails: " . $e->getMessage());
            return $this->json([
                'success' => false,
                'message' => 'Error al obtener detalles del módulo: ' . $e->getMessage()
            ], 500);
        }
    }

    private function executeUninstallCommandAndRemoveFiles(Modulo $modulo): array
    {
        $commandOutput = "=== DESINSTALACIÓN DE MÓDULO ===\n";
        $commandOutput .= "Módulo: " . $modulo->getNombre() . "\n";
        $commandOutput .= "Fecha/Hora: " . date('Y-m-d H:i:s') . "\n\n";
        
        try {
            $moduleDirectory = $modulo->getRuta();
            $commandOutput .= "Directorio del módulo: " . ($moduleDirectory ?? 'No especificado') . "\n";
            
            // Primero intentar buscar en settings.json
            $uninstallCommand = null;
            $settingsJsonPath = $moduleDirectory ? rtrim($moduleDirectory, '/') . '/settings.json' : null;
            
            if ($settingsJsonPath && $this->filesystem->exists($settingsJsonPath)) {
                $commandOutput .= "Encontrado archivo settings.json: $settingsJsonPath\n";
                try {
                    $settingsJson = json_decode(file_get_contents($settingsJsonPath), true);
                    if (json_last_error() === JSON_ERROR_NONE && isset($settingsJson['uninstallCommand']) && !empty($settingsJson['uninstallCommand'])) {
                        $uninstallCommand = $settingsJson['uninstallCommand'];
                        $commandOutput .= "Comando de desinstalación encontrado en settings.json: $uninstallCommand\n";
                    } else {
                        $commandOutput .= "No se encontró un comando de desinstalación en settings.json\n";
                    }
                } catch (\Exception $e) {
                    $commandOutput .= "Error al leer settings.json: " . $e->getMessage() . "\n";
                }
            } else {
                $commandOutput .= "No se encontró el archivo settings.json\n";
            }
            
            // Si no se encontró en settings.json, buscar en commands.json como respaldo
            if (!$uninstallCommand) {
                $commandsJsonPath = $moduleDirectory ? $moduleDirectory . '/commands.json' : null;
                $commandOutput .= "Buscando en commands.json: " . ($commandsJsonPath ?? 'Ruta no disponible') . "\n";
                
                if ($commandsJsonPath && $this->filesystem->exists($commandsJsonPath)) {
                    $commandOutput .= "Encontrado archivo commands.json\n";
                    try {
                        $commandsJson = json_decode(file_get_contents($commandsJsonPath), true);
                        if (json_last_error() === JSON_ERROR_NONE && isset($commandsJson['uninstall']) && !empty($commandsJson['uninstall'])) {
                            $uninstallCommand = $commandsJson['uninstall'];
                            $commandOutput .= "Comando de desinstalación encontrado en commands.json: $uninstallCommand\n";
                        } else {
                            $commandOutput .= "No se encontró un comando de desinstalación en commands.json\n";
                        }
                    } catch (\Exception $e) {
                        $commandOutput .= "Error al leer commands.json: " . $e->getMessage() . "\n";
                    }
                } else {
                    $commandOutput .= "No se encontró el archivo commands.json\n";
                }
            }

            $commandSuccess = false;
            
            if ($uninstallCommand) {
                $commandOutput .= "\n=== EJECUTANDO COMANDO DE DESINSTALACIÓN ===\n";
                $commandOutput .= "Comando: $uninstallCommand\n";
                
                // Preparar el comando con las variables de sustitución
                $originalCommand = $uninstallCommand;
                $uninstallCommand = str_replace(
                    ['{{moduleDir}}', '{{projectDir}}'],
                    [$moduleDirectory, $this->projectDir],
                    $uninstallCommand
                );
                
                if ($originalCommand !== $uninstallCommand) {
                    $commandOutput .= "Comando con variables sustituidas: '$uninstallCommand'\n";
                }
                
                // Determinar cómo ejecutar el comando
                if (strpos($uninstallCommand, '&&') !== false || strpos($uninstallCommand, '||') !== false ||
                    strpos($uninstallCommand, '>') !== false || strpos($uninstallCommand, '|') !== false) {
                    $process = Process::fromShellCommandline($uninstallCommand, $this->projectDir);
                } else {
                    $commandParts = explode(' ', $uninstallCommand);
                    $process = new Process($commandParts, $this->projectDir);
                }
                
                $process->setTimeout(300);
                
                try {
                    $process->run(function ($type, $buffer) use (&$commandOutput) {
                        $prefix = (Process::ERR === $type) ? 'ERROR > ' : 'OUTPUT > ';
                        $commandOutput .= $prefix . $buffer;
                    });
                    
                    $commandSuccess = $process->isSuccessful();
                    $commandOutput .= "\nCódigo de salida: " . $process->getExitCode() . "\n";
                    $commandOutput .= "Comando exitoso: " . ($commandSuccess ? 'Sí' : 'No') . "\n";
                    
                    if (!$commandSuccess) {
                        return [
                            'success' => false,
                            'commandSuccess' => false,
                            'message' => 'Error al ejecutar el comando de desinstalación',
                            'commandOutput' => $commandOutput
                        ];
                    }
                } catch (\Exception $e) {
                    $commandOutput .= "\nError al ejecutar el comando: " . $e->getMessage() . "\n";
                    return [
                        'success' => false,
                        'commandSuccess' => false,
                        'message' => 'Excepción al ejecutar el comando de desinstalación: ' . $e->getMessage(),
                        'commandOutput' => $commandOutput
                    ];
                }
            } else {
                $commandOutput .= "\nNo se encontró un comando de desinstalación. Continuando con la eliminación de archivos y registros.\n";
            }

            // Limpiar relaciones del módulo
            $commandOutput .= "\n=== LIMPIANDO RELACIONES DEL MÓDULO ===\n";
            $this->limpiarRelacionesModulo($modulo);
            $commandOutput .= "Relaciones del módulo eliminadas de la base de datos.\n";

            // Eliminar el módulo de la base de datos
            $commandOutput .= "\n=== ELIMINANDO MÓDULO DE LA BASE DE DATOS ===\n";
            $this->entityManager->remove($modulo);
            $this->entityManager->flush();
            $commandOutput .= "Registro del módulo eliminado de la base de datos.\n";

            // Eliminar archivos del módulo
            if ($moduleDirectory && $this->filesystem->exists($moduleDirectory)) {
                $commandOutput .= "\n=== ELIMINANDO ARCHIVOS DEL MÓDULO ===\n";
                try {
                    $this->filesystem->remove($moduleDirectory);
                    $commandOutput .= "Carpeta del módulo ($moduleDirectory) eliminada correctamente.\n";
                } catch (\Exception $e) {
                    $commandOutput .= "Error al eliminar la carpeta del módulo: " . $e->getMessage() . "\n";
                    return [
                        'success' => false,
                        'commandSuccess' => $commandSuccess,
                        'message' => 'Módulo desinstalado, pero no se pudo eliminar la carpeta',
                        'commandOutput' => $commandOutput
                    ];
                }
            } else {
                $commandOutput .= "\nNo se encontró la carpeta del módulo en la ruta especificada: $moduleDirectory\n";
            }

            // Limpiar la caché
            $commandOutput .= "\n=== LIMPIANDO CACHÉ ===\n";
            $cacheClear = new Process(['php', 'bin/console', 'cache:clear']);
            $cacheClear->setWorkingDirectory($this->projectDir);
            $cacheClear->run();
            $commandOutput .= "Caché limpiada.\n";

            return [
                'success' => true,
                'commandSuccess' => $commandSuccess,
                'message' => 'Módulo desinstalado y carpeta eliminada correctamente',
                'commandOutput' => $commandOutput
            ];
        } catch (\Exception $e) {
            $this->logError("Error en executeUninstallCommandAndRemoveFiles: " . $e->getMessage());
            return [
                'success' => false,
                'message' => 'Error al desinstalar el módulo: ' . $e->getMessage(),
                'commandOutput' => $commandOutput . "\n\nERROR DE EXCEPCIÓN: " . $e->getMessage(),
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
    
    /**
     * Métodos auxiliares para registro de logs
     */
    private function logInfo(string $message): void
    {
        if ($this->logger) {
            $this->logger->info($message);
        }
    }
    
    private function logWarning(string $message): void
    {
        if ($this->logger) {
            $this->logger->warning($message);
        }
    }
    
    private function logError(string $message): void
    {
        if ($this->logger) {
            $this->logger->error($message);
        } else {
            error_log($message);
        }
    }
}