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

            $moduleAttributes = $this->cdnService->getModuleAttributes($modulo);
            
            if (isset($moduleAttributes['route']) && !empty($moduleAttributes['route'])) {
                return $this->json([
                    'success' => true,
                    'url' => $moduleAttributes['route']
                ]);
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
            
            $moduleAttributes = $this->cdnService->getModuleAttributes($modulo);
            
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
            $moduleBaseName = $modulo->getNombre();
            $commandOutput .= "Directorio registrado del módulo: " . ($moduleDirectory ?? 'No especificado') . "\n";
            
            $moduleDirectory = $this->findModuleDirectoryPath($moduleBaseName, $commandOutput);
            
            if (!$moduleDirectory) {
                $commandOutput .= "ERROR: No se pudo determinar el directorio del módulo\n";
                return [
                    'success' => false,
                    'message' => 'No se pudo determinar el directorio del módulo',
                    'commandOutput' => $commandOutput
                ];
            }
            
            $commandOutput .= "Directorio del módulo localizado: $moduleDirectory\n";
            
            $uninstallCommand = null;
            
            $commandsJsonPath = $moduleDirectory . '/commands.json';
            $commandOutput .= "Buscando commands.json en: $commandsJsonPath\n";
            
            if (file_exists($commandsJsonPath)) {
                $commandOutput .= "Archivo commands.json encontrado\n";
                try {
                    $commandsContent = file_get_contents($commandsJsonPath);
                    $commandOutput .= "Contenido del archivo commands.json:\n$commandsContent\n";
                    
                    $commandsJson = json_decode($commandsContent, true);
                    if (json_last_error() === JSON_ERROR_NONE) {
                        if (isset($commandsJson['uninstall']) && !empty($commandsJson['uninstall'])) {
                            $uninstallCommand = $commandsJson['uninstall'];
                            $commandOutput .= "Comando de desinstalación encontrado: $uninstallCommand\n";
                        } else {
                            $commandOutput .= "No se encontró la clave 'uninstall' en commands.json o está vacía\n";
                        }
                    } else {
                        $commandOutput .= "Error al decodificar commands.json: " . json_last_error_msg() . "\n";
                    }
                } catch (\Exception $e) {
                    $commandOutput .= "Error al leer commands.json: " . $e->getMessage() . "\n";
                }
            } else {
                $commandOutput .= "No se encontró el archivo commands.json\n";
            }
            
            if (!$uninstallCommand) {
                $settingsJsonPath = $moduleDirectory . '/settings.json';
                $commandOutput .= "Buscando settings.json en: $settingsJsonPath\n";
                
                if (file_exists($settingsJsonPath)) {
                    try {
                        $settingsContent = file_get_contents($settingsJsonPath);
                        $commandOutput .= "Contenido del archivo settings.json:\n$settingsContent\n";
                        
                        $settingsJson = json_decode($settingsContent, true);
                        if (json_last_error() === JSON_ERROR_NONE) {
                            if (isset($settingsJson['uninstallCommand']) && !empty($settingsJson['uninstallCommand'])) {
                                $uninstallCommand = $settingsJson['uninstallCommand'];
                                $commandOutput .= "Comando de desinstalación encontrado: $uninstallCommand\n";
                            } else {
                                $commandOutput .= "No se encontró la clave 'uninstallCommand' en settings.json o está vacía\n";
                            }
                        } else {
                            $commandOutput .= "Error al decodificar settings.json: " . json_last_error_msg() . "\n";
                        }
                    } catch (\Exception $e) {
                        $commandOutput .= "Error al leer settings.json: " . $e->getMessage() . "\n";
                    }
                } else {
                    $commandOutput .= "No se encontró el archivo settings.json\n";
                }
            }
            
            if (!$uninstallCommand && $moduleBaseName === 'Explorador') {
                $uninstallCommand = 'php bin/console explorador:uninstall --force';
                $commandOutput .= "Usando comando directo para desinstalar módulo Explorador: $uninstallCommand\n";
            }
    
            $commandSuccess = false;
            
            if ($uninstallCommand) {
                $commandOutput .= "\n=== EJECUTANDO COMANDO DE DESINSTALACIÓN ===\n";
                $commandOutput .= "Comando original: $uninstallCommand\n";
                
                $uninstallCommand = str_replace(
                    ['{{moduleDir}}', '{{projectDir}}'],
                    [$moduleDirectory, $this->projectDir],
                    $uninstallCommand
                );
                
                $commandOutput .= "Comando con variables sustituidas: $uninstallCommand\n";
                $commandOutput .= "Directorio de trabajo: {$this->projectDir}\n";
                
                $hasSpecialOperators = strpos($uninstallCommand, '&&') !== false || 
                                      strpos($uninstallCommand, '||') !== false ||
                                      strpos($uninstallCommand, '>') !== false || 
                                      strpos($uninstallCommand, '|') !== false;
                
                try {
                    $commandOutput .= "Iniciando ejecución del comando...\n\n";
                    
                    if ($hasSpecialOperators) {
                        $commandOutput .= "Usando Process::fromShellCommandline por operadores especiales\n";
                        $process = Process::fromShellCommandline($uninstallCommand, $this->projectDir);
                    } else {
                        $commandOutput .= "Usando Process con array de argumentos\n";
                        $commandParts = explode(' ', $uninstallCommand);
                        $commandOutput .= "Partes del comando: " . json_encode($commandParts) . "\n";
                        $process = new Process($commandParts, $this->projectDir);
                    }
                    
                    $process->setTimeout(600);
                    $process->setEnv([
                        'PATH' => getenv('PATH'),
                        'COMPOSER_HOME' => getenv('COMPOSER_HOME') ?: $this->projectDir . '/.composer'
                    ]);
                    
                    $process->run(function ($type, $buffer) use (&$commandOutput) {
                        $prefix = (Process::ERR === $type) ? 'ERROR > ' : 'OUTPUT > ';
                        $commandOutput .= $prefix . $buffer;
                        if ($this->logger) {
                            $this->logger->info($prefix . $buffer);
                        }
                    });
                    
                    $commandSuccess = $process->isSuccessful();
                    $commandOutput .= "\nCódigo de salida: " . $process->getExitCode() . "\n";
                    $commandOutput .= "Comando exitoso: " . ($commandSuccess ? 'Sí' : 'No') . "\n";
                    
                    if (!$commandSuccess) {
                        $commandOutput .= "Error en la ejecución: " . $process->getErrorOutput() . "\n";
                        $commandOutput .= "\n=== INTENTANDO EJECUTAR COMANDO ALTERNATIVO DIRECTO ===\n";
                        
                        $directCommand = 'php bin/console explorador:uninstall --force';
                        $directProcess = Process::fromShellCommandline($directCommand, $this->projectDir);
                        $directProcess->setTimeout(600);
                        $directProcess->run(function ($type, $buffer) use (&$commandOutput) {
                            $prefix = (Process::ERR === $type) ? 'ERROR (DIRECTO) > ' : 'OUTPUT (DIRECTO) > ';
                            $commandOutput .= $prefix . $buffer;
                        });
                        
                        $commandSuccess = $directProcess->isSuccessful();
                        $commandOutput .= "\nCódigo de salida (directo): " . $directProcess->getExitCode() . "\n";
                        $commandOutput .= "Comando directo exitoso: " . ($commandSuccess ? 'Sí' : 'No') . "\n";
                    }
                } catch (\Exception $e) {
                    $commandOutput .= "\nExcepción al ejecutar el comando: " . $e->getMessage() . "\n";
                    $commandOutput .= "Traza: " . $e->getTraceAsString() . "\n";
                    
                    $commandOutput .= "\nA pesar del error, continuando con la eliminación de registros y archivos...\n";
                }
            } else {
                $commandOutput .= "\nNo se encontró un comando de desinstalación. Continuando con la eliminación de archivos y registros.\n";
                
                if ($moduleBaseName === 'Explorador') {
                    $commandOutput .= "\n=== INTENTANDO EJECUTAR COMANDO DIRECTO ===\n";
                    $directCommand = 'php bin/console explorador:uninstall --force';
                    $directProcess = Process::fromShellCommandline($directCommand, $this->projectDir);
                    $directProcess->setTimeout(600);
                    $directProcess->run(function ($type, $buffer) use (&$commandOutput) {
                        $prefix = (Process::ERR === $type) ? 'ERROR (DIRECTO) > ' : 'OUTPUT (DIRECTO) > ';
                        $commandOutput .= $prefix . $buffer;
                    });
                    
                    $commandSuccess = $directProcess->isSuccessful();
                    $commandOutput .= "\nCódigo de salida (directo): " . $directProcess->getExitCode() . "\n";
                    $commandOutput .= "Comando directo exitoso: " . ($commandSuccess ? 'Sí' : 'No') . "\n";
                }
            }
    
            $commandOutput .= "\n=== LIMPIANDO RELACIONES DEL MÓDULO ===\n";
            $this->limpiarRelacionesModulo($modulo);
            $commandOutput .= "Relaciones del módulo eliminadas de la base de datos.\n";
    
            $commandOutput .= "\n=== ELIMINANDO MÓDULO DE LA BASE DE DATOS ===\n";
            $this->entityManager->remove($modulo);
            $this->entityManager->flush();
            $commandOutput .= "Registro del módulo eliminado de la base de datos.\n";
    
            if ($moduleDirectory && $this->filesystem->exists($moduleDirectory)) {
                $commandOutput .= "\n=== ELIMINANDO ARCHIVOS DEL MÓDULO ===\n";
                try {
                    $this->filesystem->remove($moduleDirectory);
                    $commandOutput .= "Carpeta del módulo ($moduleDirectory) eliminada correctamente.\n";
                } catch (\Exception $e) {
                    $commandOutput .= "Error al eliminar la carpeta del módulo: " . $e->getMessage() . "\n";
                    return [
                        'success' => $commandSuccess,
                        'message' => 'Módulo desinstalado, pero no se pudo eliminar la carpeta',
                        'commandOutput' => $commandOutput
                    ];
                }
            } else {
                $commandOutput .= "\nNo se encontró la carpeta del módulo para eliminar\n";
            }
    
            $explorerSymlinkPath = $this->projectDir . '/public/explorer';
            if ($this->filesystem->exists($explorerSymlinkPath)) {
                $commandOutput .= "\n=== ELIMINANDO ENLACE SIMBÓLICO EN PUBLIC/EXPLORER ===\n";
                try {
                    $this->filesystem->remove($explorerSymlinkPath);
                    $commandOutput .= "Enlace simbólico/directorio ($explorerSymlinkPath) eliminado correctamente.\n";
                } catch (\Exception $e) {
                    $commandOutput .= "Error al eliminar el enlace simbólico/directorio: " . $e->getMessage() . "\n";
                }
            }
    
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
    
    private function findModuleDirectoryPath(string $baseModuleName, string &$installCommandOutput): ?string
    {
        $srcDir = $this->projectDir . '/src';
    
        $exactPath = $srcDir . '/' . $baseModuleName;
        if (is_dir($exactPath)) {
            $installCommandOutput .= "Directorio del módulo encontrado (exacto): $exactPath\n";
            return $exactPath;
        }
    
        $moduloPath = $srcDir . '/Modulo' . $baseModuleName;
        if (is_dir($moduloPath)) {
            $installCommandOutput .= "Directorio del módulo encontrado (con prefijo Modulo): $moduloPath\n";
            return $moduloPath;
        }
    
        $installCommandOutput .= "Directorio exacto '$exactPath' no encontrado, buscando alternativas...\n";
    
        $normalizedBaseName = strtolower(str_replace('modulo', '', $baseModuleName));
    
        $directories = scandir($srcDir);
        if($directories === false) {
             $installCommandOutput .= "Error: No se pudo leer el directorio $srcDir.\n";
             return null;
        }
    
        foreach ($directories as $dir) {
            $itemPath = $srcDir . '/' . $dir;
            if ($dir !== '.' && $dir !== '..' && is_dir($itemPath)) {
                $normalizedDir = strtolower(str_replace('modulo', '', $dir));
                $installCommandOutput .= "Verificando directorio: $dir (normalizado: $normalizedDir)\n";
    
                if ($normalizedDir === $normalizedBaseName ||
                    strtolower($dir) === strtolower($baseModuleName) || 
                    stripos($dir, $baseModuleName) !== false ||
                    stripos($baseModuleName, $dir) !== false)
                 {
                     $installCommandOutput .= "Usando directorio (alternativo): $itemPath\n";
                    return $itemPath;
                }
            }
        }
    
        $installCommandOutput .= "No se encontró un directorio apropiado para el módulo '$baseModuleName' en $srcDir.\n";
        $installCommandOutput .= "Directorios disponibles: " . implode(", ", array_filter($directories, fn($d) => $d !== '.' && $d !== '..')) . "\n";
        return null;
    }

    private function limpiarRelacionesModulo(Modulo $modulo): void
    {
        $this->logInfo("Limpiando relaciones del módulo: " . $modulo->getNombre());
        $menuElements = $modulo->getMenuElements();
        
        if ($menuElements) {
            foreach ($menuElements as $menuElement) {
                $this->logInfo("Eliminando relación con el elemento de menú: " . $menuElement->getNombre());
                $modulo->removeMenuElement($menuElement);
            }
        }
        
        $this->entityManager->flush();
        $this->logInfo("Relaciones del módulo limpiadas correctamente");
    }
    
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