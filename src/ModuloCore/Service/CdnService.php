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
        $this->cdnBaseUrl = $parameterBag->get('cdn_base_url');
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
                    if (!isset($module['filename'])) {
                        error_log("Módulo sin 'filename' en marketplace API: " . json_encode($module));
                        continue;
                    }
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
                        'downloadUrl' => $module['downloadUrl'] ?? null,
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
            error_log("Error en getAvailableModules: " . $e->getMessage());
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
            error_log("Error en verifyLicense: " . $e->getMessage());
            return ['valid' => false, 'message' => 'Error al verificar licencia: ' . $e->getMessage()];
        }
    }

    public function installModule(string $moduleType, string $filename, string $downloadToken = null): array
    {
        $installCommandOutput = "=== INFORMACIÓN DE DEPURACIÓN ===\n";
        $installCommandOutput .= "Módulo: $filename\n";
        $installCommandOutput .= "Tipo: $moduleType\n";
        $installCommandOutput .= "Fecha/Hora: " . date('Y-m-d H:i:s') . "\n\n";
        $moduleDirectoryPath = null;

        try {
            $baseModuleName = str_replace('.zip', '', $filename);
            $normalizedName = strtolower($baseModuleName);

            $moduloRepository = $this->entityManager->getRepository(Modulo::class);
            $existingModulo = null;

            $existingModulo = $moduloRepository->findOneBy(['nombre' => $baseModuleName]);

            if ($existingModulo) {
                $now = new \DateTimeImmutable();
                $existingModulo->setEstado(true);
                $existingModulo->setInstallDate($now);
                $existingModulo->setUninstallDate(null);
                $this->entityManager->flush();
                return [
                    'success' => true,
                    'message' => 'El módulo ya estaba instalado y ha sido activado',
                    'module' => [ 'name' => $existingModulo->getNombre(), /* ... otros campos ... */ ],
                    'commandOutput' => $installCommandOutput . "Módulo ya existente, no se ejecuta ningún comando."
                ];
            }

            $moduleInfo = null;
            try {
                $moduleInfoUrl = $this->cdnBaseUrl . '/api/module-info/' . $moduleType . '/' . $filename;
                $installCommandOutput .= "Consultando información del módulo en: $moduleInfoUrl\n";
                $response = $this->httpClient->request('GET', $moduleInfoUrl);
                if ($response->getStatusCode() === 200) {
                    $moduleInfoData = $response->toArray();
                    $moduleInfo = $moduleInfoData['module'] ?? null; // Extrae el objeto 'module'
                    $installCommandOutput .= "Información recibida de la API:\n" . json_encode($moduleInfo, JSON_PRETTY_PRINT) . "\n\n";
                } else {
                     $installCommandOutput .= "Error HTTP " . $response->getStatusCode() . " al obtener información del módulo.\n\n";
                }
            } catch (\Exception $e) {
                $installCommandOutput .= "Error (excepción) al obtener información del módulo: " . $e->getMessage() . "\n";
                // $moduleInfo permanece null
            }

            // Descargar el módulo
            $tempDir = $this->projectDir . '/var/tmp';
            $this->filesystem->mkdir($tempDir, 0777);
            $downloadUrl = $this->cdnBaseUrl . '/api/download/' . $moduleType . '/' . $filename;
            if ($downloadToken) {
                $downloadUrl .= '?token=' . $downloadToken;
            }
            $installCommandOutput .= "Descargando módulo desde: $downloadUrl\n";
            $tempFile = $tempDir . '/' . $filename;
            $response = $this->httpClient->request('GET', $downloadUrl, ['timeout' => 120]);

            if ($response->getStatusCode() !== 200) {
                return ['success' => false, 'message' => 'Error al descargar el módulo: ' . $response->getStatusCode(), 'commandOutput' => $installCommandOutput];
            }
            file_put_contents($tempFile, $response->getContent());
            $installCommandOutput .= "Módulo descargado correctamente en: $tempFile\n";

            // Extraer información del ZIP
            $zipArchive = new \ZipArchive();
            $res = $zipArchive->open($tempFile);
            if ($res !== true) {
                return ['success' => false, 'message' => 'Error al abrir el archivo ZIP', 'commandOutput' => $installCommandOutput . "Error código: $res al abrir el ZIP"];
            }
            $installCommandOutput .= "Archivo ZIP abierto correctamente. Contenidos:\n";
            for ($i = 0; $i < $zipArchive->numFiles; $i++) {
                $installCommandOutput .= " - " . $zipArchive->getNameIndex($i) . "\n";
            }

            // Buscar module.json en la raíz del ZIP
            $moduleJson = null;
            $moduleJsonContent = $zipArchive->getFromName('module.json'); // Busca en la raíz
            if ($moduleJsonContent !== false) {
                $installCommandOutput .= "\nContenido de module.json (raíz) encontrado:\n" . $moduleJsonContent . "\n\n";
                $moduleJson = json_decode($moduleJsonContent, true);
                if (json_last_error() !== JSON_ERROR_NONE) {
                    $installCommandOutput .= "Error al decodificar module.json: " . json_last_error_msg() . "\n";
                    $moduleJson = null; // Invalidar si hay error
                }
            } else {
                 $installCommandOutput .= "No se encontró el archivo module.json en la raíz del ZIP\n";
            }

            // Si no se encontró/decodificó module.json, usar/generar $moduleJson a partir de $moduleInfo o defaults
            if (!$moduleJson) {
                $installCommandOutput .= "Usando información de la API (si existe) o predeterminada como respaldo para $moduleJson\n";
                if ($moduleInfo) { // Usar info de API si existe
                    $moduleJson = [
                        'name' => $moduleInfo['name'] ?? $baseModuleName,
                        'description' => $moduleInfo['description'] ?? 'Módulo para Exnet',
                        'version' => $moduleInfo['version'] ?? '1.0.0',
                        'icon' => $moduleInfo['icon'] ?? 'fas fa-puzzle-piece',
                        'route' => $moduleInfo['route'] ?? '/' . strtolower($baseModuleName),
                        // NO incluir installCommand aquí directamente, se verificará después
                    ];
                    $installCommandOutput .= "Información reconstruida del módulo (desde API):\n" . json_encode($moduleJson, JSON_PRETTY_PRINT) . "\n\n";
                } else { // Generar defaults si API falló
                    $moduleJson = [
                        'name' => $baseModuleName,
                        'description' => 'Módulo para Exnet',
                        'version' => '1.0.0',
                        'icon' => 'fas fa-puzzle-piece',
                        'route' => '/' . strtolower($baseModuleName)
                    ];
                    $installCommandOutput .= "Información predeterminada generada para el módulo (API falló)\n";
                }
            }

            // Extraer archivos AHORA, antes de buscar commands.json
            $extractPath = $this->projectDir . '/src';
            $zipArchive->extractTo($extractPath);
            $zipArchive->close(); // Cerrar el ZIP después de extraer
            $installCommandOutput .= "Archivos extraídos en: $extractPath\n";
            $this->filesystem->remove($tempFile); // Eliminar el ZIP temporal


            // --- Determinar el Comando de Instalación ---
            $installCommand = null; // Variable para el comando final

            // 1. Verificar si estaba en module.json (del ZIP)
            if (isset($moduleJson['installCommand']) && !empty($moduleJson['installCommand'])) {
                 $installCommandOutput .= "Verificando comando: Encontrado en module.json (del ZIP): '" . $moduleJson['installCommand'] . "'\n";
                 $installCommand = $moduleJson['installCommand'];
            }
            // 2. Si no, verificar si vino de la API
            elseif (isset($moduleInfo['installCommand']) && !empty($moduleInfo['installCommand'])) {
                 $installCommandOutput .= "Verificando comando: No encontrado en module.json del ZIP. Encontrado en API: '" . $moduleInfo['installCommand'] . "'\n";
                 $installCommand = $moduleInfo['installCommand'];
            }
            // 3. Si no, buscar en commands.json DENTRO del directorio extraído
            else {
                $installCommandOutput .= "Verificando comando: No encontrado en module.json (ZIP) ni en API. Buscando en commands.json...\n";
                // Encontrar el directorio del módulo YA EXTRAIDO
                $moduleDirectoryPath = $this->findModuleDirectoryPath($baseModuleName, $installCommandOutput);

                if ($moduleDirectoryPath && is_dir($moduleDirectoryPath)) {
                    $commandsJsonPath = $moduleDirectoryPath . '/commands.json';
                    $installCommandOutput .= "Verificando existencia de: $commandsJsonPath\n";

                    if ($this->filesystem->exists($commandsJsonPath)) {
                        $installCommandOutput .= "Archivo commands.json encontrado.\n";
                        try {
                            $commandsJsonContent = file_get_contents($commandsJsonPath);
                            $commandsJsonData = json_decode($commandsJsonContent, true);

                            if (json_last_error() === JSON_ERROR_NONE && isset($commandsJsonData['install']) && !empty($commandsJsonData['install'])) {
                                $installCommandOutput .= "Comando 'install' encontrado en commands.json: '" . $commandsJsonData['install'] . "'\n";
                                $installCommand = $commandsJsonData['install']; // <--- Asignar comando!
                            } else {
                                $installCommandOutput .= "Archivo commands.json existe pero no contiene una clave 'install' válida o está vacía.\n";
                                if(json_last_error() !== JSON_ERROR_NONE) {
                                     $installCommandOutput .= "Error al decodificar JSON de commands.json: " . json_last_error_msg() . "\n";
                                }
                            }
                        } catch (\Exception $e) {
                            $installCommandOutput .= "Error al leer o decodificar commands.json: " . $e->getMessage() . "\n";
                        }
                    } else {
                        $installCommandOutput .= "Archivo commands.json NO encontrado en la ruta esperada.\n";
                    }
                } else {
                     $installCommandOutput .= "No se pudo determinar el directorio del módulo para buscar commands.json.\n";
                }
            }

             $commandSuccess = true;

            if ($installCommand) {
                $installCommandOutput .= "\nComando de instalación final a usar: '$installCommand'\n";

                if (!$moduleDirectoryPath || !is_dir($moduleDirectoryPath)) {
                    $moduleDirectoryPath = $this->findModuleDirectoryPath($baseModuleName, $installCommandOutput);
                     if(!$moduleDirectoryPath || !is_dir($moduleDirectoryPath)) {
                         return [
                             'success' => false,
                             'message' => 'Error crítico: No se encontró el directorio del módulo para ejecutar el comando.',
                             'commandOutput' => $installCommandOutput . "Error: Directorio del módulo '$baseModuleName' no encontrado en /src después de extraer.\n"
                         ];
                     }
                }

                // Preparar el comando con las variables de sustitución
                $originalCommand = $installCommand;
                $installCommand = str_replace(
                    ['{{moduleDir}}', '{{projectDir}}'],
                    [$moduleDirectoryPath, $this->projectDir],
                    $installCommand
                );
                if ($originalCommand !== $installCommand) {
                    $installCommandOutput .= "Comando con variables sustituidas: '$installCommand'\n";
                }

                $installCommandOutput .= "\n=== EJECUCIÓN DEL COMANDO ===\n";
                $installCommandOutput .= "Comando a ejecutar: $installCommand\n";
                $installCommandOutput .= "Directorio de trabajo: $moduleDirectoryPath\n\n";

                error_log('Ejecutando comando: ' . $installCommand . ' en ' . $moduleDirectoryPath);

                if (strpos($installCommand, '&&') !== false || strpos($installCommand, '||') !== false ||
                    strpos($installCommand, '>') !== false || strpos($installCommand, '|') !== false) {
                    $installCommandOutput .= "Usando Process::fromShellCommandline\n";
                    $process = Process::fromShellCommandline($installCommand, $moduleDirectoryPath);
                } else {
                    $installCommandOutput .= "Usando Process con array de argumentos\n";
                    $commandParts = explode(' ', $installCommand);
                    $installCommandOutput .= "Partes del comando: " . json_encode($commandParts) . "\n";
                    $process = new Process($commandParts, $moduleDirectoryPath);
                }

                $process->setTimeout(300);
                $process->setEnv([
                    'PATH' => getenv('PATH'),
                    'COMPOSER_HOME' => getenv('COMPOSER_HOME') ?: $this->projectDir . '/.composer'
                ]);

                try {
                    $installCommandOutput .= "Iniciando ejecución del comando...\n\n";
                    $process->run(function ($type, $buffer) use (&$installCommandOutput) {
                        $prefix = (Process::ERR === $type) ? 'ERROR > ' : 'OUTPUT > ';
                        $installCommandOutput .= $prefix . $buffer;
                        error_log($prefix . $buffer);
                    });

                    $commandSuccess = $process->isSuccessful();
                    $installCommandOutput .= "\n=== RESULTADO DEL COMANDO ===\n";
                    $installCommandOutput .= "Código de salida: " . $process->getExitCode() . "\n";
                    $installCommandOutput .= "Comando exitoso: " . ($commandSuccess ? 'Sí' : 'No') . "\n";

                    if (!$commandSuccess) {
                        error_log('Comando falló con código: ' . $process->getExitCode());
                         return [
                             'success' => false,
                             'message' => 'Error al ejecutar el comando de instalación',
                             'commandSuccess' => false,
                             'commandOutput' => $installCommandOutput
                         ];
                    }
                } catch (\Exception $e) {
                    $commandSuccess = false;
                    $installCommandOutput .= "\n=== ERROR DE EXCEPCIÓN AL EJECUTAR PROCESO ===\n";
                    $installCommandOutput .= "Error: " . $e->getMessage() . "\n";
                    $installCommandOutput .= "Traza: " . $e->getTraceAsString() . "\n";
                    error_log('Excepción ejecutando comando: ' . $e->getMessage());
                     return [
                         'success' => false,
                         'message' => 'Excepción al ejecutar el comando de instalación: ' . $e->getMessage(),
                         'commandSuccess' => false,
                         'commandOutput' => $installCommandOutput
                     ];
                }
            } else {
                $installCommandOutput .= "\n=== NO HAY COMANDO DE INSTALACIÓN ===\n";
                $installCommandOutput .= "No se encontró ningún comando de instalación válido en module.json, API o commands.json.\n";
                $installCommandOutput .= "\nRevisión final de la información del módulo:\n";
                $installCommandOutput .= "moduleJson (final): " . json_encode($moduleJson, JSON_PRETTY_PRINT) . "\n";
                $installCommandOutput .= "moduleInfo (API): " . json_encode($moduleInfo, JSON_PRETTY_PRINT) . "\n";
            }



            if ($commandSuccess && isset($moduleJson['migrate']) && $moduleJson['migrate'] === true) {
                 $installCommandOutput .= "\n=== EJECUTANDO MIGRACIONES ===\n";
                 $migrateResult = $this->runMigrations();
                 $installCommandOutput .= "Resultado de migraciones: " . ($migrateResult ? "Exitoso" : "Fallido") . "\n";
            }

             if ($commandSuccess) {
                $installCommandOutput .= "\nEntidad Modulo creada/actualizada en la base de datos local.\n";
            }
        
            $cacheClear = new Process(['php', 'bin/console', 'cache:clear']);
            $cacheClear->setWorkingDirectory($this->projectDir);
            $cacheClear->run();
            $installCommandOutput .= "\nCache cleared:\n" . $cacheClear->getOutput();
        
            return [
                'success' => true,
                'message' => $installCommand ? ($commandSuccess ? 'Módulo instalado y comando ejecutado correctamente' : 'Módulo instalado, pero hubo un error al ejecutar el comando') : 'Módulo instalado correctamente (sin comando de instalación)',
                'module' => $moduleJson,
                'commandSuccess' => $commandSuccess,
                'commandOutput' => $installCommandOutput
            ];

        } catch (\Exception $e) {
            $errorOutput = $installCommandOutput;
            $errorOutput .= "\n\n=== ERROR DE EXCEPCIÓN GENERAL ===\n";
            $errorOutput .= "Mensaje: " . $e->getMessage() . "\n";
            $errorOutput .= "Archivo: " . $e->getFile() . " (línea " . $e->getLine() . ")\n";
            $errorOutput .= "Traza:\n" . $e->getTraceAsString() . "\n";

            error_log("Error general en instalación de módulo: " . $e->getMessage());
            error_log("Trace: " . $e->getTraceAsString());

            if (isset($tempFile) && $this->filesystem->exists($tempFile)) {
                 $this->filesystem->remove($tempFile);
            }

            return [
                'success' => false,
                'message' => 'Error fatal al instalar el módulo: ' . $e->getMessage(),
                'commandOutput' => $errorOutput
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


    private function runMigrations(): bool
    {
        try {
            $process = new Process(['php', 'bin/console', 'doctrine:migrations:migrate', '--no-interaction']);
            $process->setWorkingDirectory($this->projectDir);
            $process->setTimeout(300);

            $output = '';
            $process->run(function ($type, $buffer) use (&$output) {
                 $prefix = (Process::ERR === $type) ? 'ERROR > ' : 'OUTPUT > ';
                 $output .= $prefix.$buffer;
                 error_log('Migración: ' . $buffer);
            });

             error_log("Resultado ejecución migraciones:\n" . $output);

            return $process->isSuccessful();
        } catch (\Exception $e) {
            error_log('Error en migraciones: ' . $e->getMessage());
            error_log('Trace migraciones: ' . $e->getTraceAsString());
            return false;
        }
    }
}