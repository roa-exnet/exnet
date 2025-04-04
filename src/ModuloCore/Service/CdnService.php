<?php

namespace App\ModuloCore\Service;

use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Process\Process;
use App\ModuloCore\Entity\Modulo;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\DependencyInjection\ParameterBag\ParameterBagInterface;

class CdnService
{
    private HttpClientInterface $httpClient;
    private string $cdnBaseUrl;
    private EntityManagerInterface $entityManager;
    private string $projectDir;
    private Filesystem $filesystem;

    public function __construct(
        HttpClientInterface $httpClient,
        EntityManagerInterface $entityManager,
        ParameterBagInterface $parameterBag
    ) {
        $this->httpClient = $httpClient;
        $this->entityManager = $entityManager;
        $this->cdnBaseUrl = 'https://cdn.exnet.cloud';
        $this->projectDir = $parameterBag->get('kernel.project_dir');
        $this->filesystem = new Filesystem();
    }

    public function getAvailableModules(): array
    {
        try {
            $response = $this->httpClient->request('GET', $this->cdnBaseUrl . '/api/marketplace');
            
            $statusCode = $response->getStatusCode();
            if ($statusCode !== 200) {
                return ['error' => 'Error al conectar con el servidor CDN: ' . $statusCode];
            }
            
            $data = $response->toArray();
            if (!isset($data['marketplace'])) {
                return ['error' => 'Formato de respuesta inesperado'];
            }
            
            $installedModules = $this->entityManager->getRepository(Modulo::class)->findAll();
            
            $installedModuleMap = [];
            foreach ($installedModules as $module) {
                $name = strtolower($module->getNombre());
                $installedModuleMap[$name] = true;
                
                if (strpos($name, 'modulo') === 0) {
                    $normalizedName = substr($name, 6);
                    $installedModuleMap[$normalizedName] = true;
                }
            }
            
            $marketplace = [];
            $processedModules = [];
            
            foreach ($data['marketplace'] as $moduleType => $modules) {
                if (!isset($marketplace[$moduleType])) {
                    $marketplace[$moduleType] = [];
                }
                
                foreach ($modules as $module) {
                    $moduleName = $module['name'] ?? basename($module['filename'], '.zip');
                    $normalizedName = strtolower($moduleName);
                    
                    if (strpos($normalizedName, 'modulo') === 0) {
                        $normalizedNameWithoutPrefix = substr($normalizedName, 6);
                    } else {
                        $normalizedNameWithoutPrefix = $normalizedName;
                    }
                    
                    $isInstalled = isset($installedModuleMap[$normalizedName]) || 
                                   isset($installedModuleMap[$normalizedNameWithoutPrefix]);
                    
                    $moduleIdentifier = $moduleType . '_' . $normalizedName;
                    if (isset($processedModules[$moduleIdentifier])) {
                        continue;
                    }
                    
                    $processedModules[$moduleIdentifier] = true;
                    
                    $marketplace[$moduleType][] = [
                        'name' => $moduleName,
                        'filename' => $module['filename'],
                        'description' => $module['description'] ?? 'Módulo para Exnet',
                        'version' => $module['version'] ?? '1.0.0',
                        'price' => $module['price'] ?? 'free',
                        'downloadUrl' => $module['downloadUrl'],
                        'installed' => $isInstalled,
                        'installCommand' => $module['installCommand'] ?? null
                    ];
                }
            }
            
            foreach ($marketplace as $moduleType => $modules) {
                if (empty($modules)) {
                    unset($marketplace[$moduleType]);
                }
            }
            
            return $marketplace;
        } catch (\Exception $e) {
            return ['error' => 'Error al obtener módulos: ' . $e->getMessage()];
        }
    }
    
    public function verifyLicense(string $licenseKey, string $moduleFilename): array
    {
        try {
            $response = $this->httpClient->request('POST', $this->cdnBaseUrl . '/api/verify-license', [
                'json' => [
                    'license' => $licenseKey,
                    'moduleFilename' => $moduleFilename
                ]
            ]);
            
            $data = $response->toArray();
            
            if (!isset($data['valid'])) {
                return ['valid' => false, 'message' => 'Respuesta inesperada del servidor'];
            }
            
            return $data;
        } catch (\Exception $e) {
            return ['valid' => false, 'message' => 'Error al verificar licencia: ' . $e->getMessage()];
        }
    }
    
public function installModule(string $moduleType, string $filename, string $downloadToken = null): array
{
    try {
        $baseModuleName = str_replace('.zip', '', $filename);
        $normalizedName = strtolower($baseModuleName);
        
        $moduloRepository = $this->entityManager->getRepository(Modulo::class);
        $existingModulo = null;
        
        $existingModulo = $moduloRepository->findOneBy(['nombre' => $baseModuleName]);
        
        if (!$existingModulo && strpos($normalizedName, 'modulo') === 0) {
            $nameWithoutPrefix = substr($normalizedName, 6);
            $existingModulo = $moduloRepository->createQueryBuilder('m')
                ->where('LOWER(m.nombre) = :name')
                ->orWhere('LOWER(m.nombre) = :nameWithModulo')
                ->setParameter('name', $nameWithoutPrefix)
                ->setParameter('nameWithModulo', 'modulo' . $nameWithoutPrefix)
                ->getQuery()
                ->getOneOrNullResult();
        } elseif (!$existingModulo) {
            $existingModulo = $moduloRepository->createQueryBuilder('m')
                ->where('LOWER(m.nombre) = :name')
                ->orWhere('LOWER(m.nombre) = :nameWithModulo')
                ->setParameter('name', $normalizedName)
                ->setParameter('nameWithModulo', 'modulo' . $normalizedName)
                ->getQuery()
                ->getOneOrNullResult();
        }
        
        if ($existingModulo) {
            $now = new \DateTimeImmutable();
            $existingModulo->setEstado(true);
            $existingModulo->setInstallDate($now);
            $existingModulo->setUninstallDate(null);
            
            $this->entityManager->flush();
            
            return [
                'success' => true,
                'message' => 'El módulo ya estaba instalado y ha sido activado',
                'module' => [
                    'name' => $existingModulo->getNombre(),
                    'description' => $existingModulo->getDescripcion(),
                    'icon' => $existingModulo->getIcon(),
                    'route' => $existingModulo->getRuta()
                ]
            ];
        }
        
        try {
            $response = $this->httpClient->request('GET', $this->cdnBaseUrl . '/api/module-info/' . $moduleType . '/' . $filename);
            $moduleInfoData = $response->toArray();
            $moduleInfo = $moduleInfoData['module'] ?? null;
        } catch (\Exception $e) {
            $moduleInfo = null;
        }
        
        $tempDir = $this->projectDir . '/var/tmp';
        $this->filesystem->mkdir($tempDir, 0777);
        
        $downloadUrl = $this->cdnBaseUrl . '/api/download/' . $moduleType . '/' . $filename;
        
        if ($downloadToken) {
            $downloadUrl .= '?token=' . $downloadToken;
        }
        
        $tempFile = $tempDir . '/' . $filename;
        
        $response = $this->httpClient->request('GET', $downloadUrl, [
            'timeout' => 120,
            'on_progress' => function(int $dlNow, int $dlSize, array $info) {
            }
        ]);
        
        $statusCode = $response->getStatusCode();
        if ($statusCode !== 200) {
            return ['success' => false, 'message' => 'Error al descargar el módulo: ' . $statusCode];
        }
        
        file_put_contents($tempFile, $response->getContent());
        
        $zipArchive = new \ZipArchive();
        $res = $zipArchive->open($tempFile);
        
        if ($res !== true) {
            return ['success' => false, 'message' => 'Error al abrir el archivo ZIP'];
        }
        
        $moduleJson = null;
        for ($i = 0; $i < $zipArchive->numFiles; $i++) {
            $filename = $zipArchive->getNameIndex($i);
            if (basename($filename) === 'module.json') {
                $moduleJson = json_decode($zipArchive->getFromIndex($i), true);
                break;
            }
        }
        
        if (!$moduleJson) {
            if ($moduleInfo) {
                $moduleJson = [
                    'name' => $moduleInfo['name'] ?? $baseModuleName,
                    'description' => $moduleInfo['description'] ?? 'Módulo para Exnet',
                    'version' => $moduleInfo['version'] ?? '1.0.0',
                    'icon' => $moduleInfo['icon'] ?? 'fas fa-puzzle-piece',
                    'route' => $moduleInfo['route'] ?? '/' . strtolower($baseModuleName),
                    'installCommand' => $moduleInfo['installCommand'] ?? null
                ];
            } else {
                $moduleJson = [
                    'name' => $baseModuleName,
                    'description' => 'Módulo para Exnet',
                    'version' => '1.0.0',
                    'icon' => 'fas fa-puzzle-piece',
                    'route' => '/' . strtolower($baseModuleName)
                ];
            }
        }
        
        $extractPath = $this->projectDir . '/src';
        $zipArchive->extractTo($extractPath);
        $zipArchive->close();
        
        $this->filesystem->remove($tempFile);
        
        $commandSuccess = true;
        $installCommandOutput = '';
        
        if (isset($moduleJson['installCommand']) && !empty($moduleJson['installCommand'])) {
            $installCommand = $moduleJson['installCommand'];
            
            $moduleDirectoryPath = $this->projectDir . '/src/' . $baseModuleName;
            if (!is_dir($moduleDirectoryPath)) {
                $directories = scandir($this->projectDir . '/src');
                foreach ($directories as $dir) {
                    if ($dir !== '.' && $dir !== '..' && is_dir($this->projectDir . '/src/' . $dir) && 
                        (stripos($dir, $baseModuleName) !== false || stripos($baseModuleName, $dir) !== false)) {
                        $moduleDirectoryPath = $this->projectDir . '/src/' . $dir;
                        break;
                    }
                }
            }
            
            $installCommand = str_replace(
                ['{{moduleDir}}', '{{projectDir}}'], 
                [$moduleDirectoryPath, $this->projectDir], 
                $installCommand
            );
            
            error_log('Ejecutando comando: ' . $installCommand);
            error_log('En directorio: ' . $moduleDirectoryPath);
            
            if (strpos($installCommand, '&&') !== false || strpos($installCommand, '||') !== false || 
                strpos($installCommand, '>') !== false || strpos($installCommand, '|') !== false) {
                $process = Process::fromShellCommandline($installCommand);
            } else {
                $commandParts = explode(' ', $installCommand);
                $process = new Process($commandParts);
            }
            
            $process->setWorkingDirectory($moduleDirectoryPath);
            $process->setTimeout(300);
            $process->setEnv([
                'PATH' => getenv('PATH'),
                'COMPOSER_HOME' => getenv('COMPOSER_HOME') ?: $this->projectDir . '/.composer'
            ]);
            
            try {
                $process->run(function ($type, $buffer) use (&$installCommandOutput) {
                    if (Process::ERR === $type) {
                        $installCommandOutput .= 'ERROR > '.$buffer;
                        error_log('Salida de error del comando: ' . $buffer);
                    } else {
                        $installCommandOutput .= 'OUTPUT > '.$buffer;
                        error_log('Salida estándar del comando: ' . $buffer);
                    }
                });
                
                $commandSuccess = $process->isSuccessful();
                
                if (!$commandSuccess) {
                    error_log('Comando falló con código: ' . $process->getExitCode());
                    $errorOutput = $process->getErrorOutput();
                    if (!empty($errorOutput) && empty($installCommandOutput)) {
                        $installCommandOutput = $errorOutput;
                    }
                }
            } catch (\Exception $e) {
                $commandSuccess = false;
                $installCommandOutput = 'Error de excepción: ' . $e->getMessage();
                error_log('Excepción ejecutando comando: ' . $e->getMessage());
            }
            
            if ($commandSuccess) {
                if (isset($moduleJson['migrate']) && $moduleJson['migrate'] === true) {
                    $this->runMigrations();
                }
                
                return [
                    'success' => true,
                    'message' => 'Módulo instalado correctamente mediante comando',
                    'module' => $moduleJson,
                    'commandOutput' => $installCommandOutput
                ];
            } else {
                return [
                    'success' => false,
                    'message' => 'Error al ejecutar el comando de instalación',
                    'commandOutput' => $installCommandOutput
                ];
            }
        }
        
        $now = new \DateTimeImmutable();
        $newModulo = new Modulo();
        $newModulo->setNombre($moduleJson['name']);
        $newModulo->setDescripcion($moduleJson['description'] ?? 'Módulo para Exnet');
        $newModulo->setIcon($moduleJson['icon'] ?? 'fas fa-puzzle-piece');
        $newModulo->setRuta($moduleJson['route'] ?? '/' . strtolower($moduleJson['name']));
        $newModulo->setInstallDate($now);
        $newModulo->setEstado(true);
        
        $this->entityManager->persist($newModulo);
        $this->entityManager->flush();
        
        if (isset($moduleJson['migrate']) && $moduleJson['migrate'] === true) {
            $this->runMigrations();
        }
        
        return [
            'success' => true,
            'message' => 'Módulo instalado correctamente',
            'module' => $moduleJson,
            'commandOutput' => $installCommandOutput
        ];
    } catch (\Exception $e) {
        return ['success' => false, 'message' => 'Error al instalar el módulo: ' . $e->getMessage()];
    }
}
    
    private function runMigrations(): bool
    {
        try {
            $process = new Process(['php', 'bin/console', 'doctrine:migrations:migrate', '--no-interaction']);
            $process->setWorkingDirectory($this->projectDir);
            $process->run();
            
            return $process->isSuccessful();
        } catch (\Exception $e) {
            return false;
        }
    }
}