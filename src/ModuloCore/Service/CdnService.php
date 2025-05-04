<?php

namespace App\ModuloCore\Service;

use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Process\Process;
use App\ModuloCore\Entity\Modulo;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\DependencyInjection\ParameterBag\ParameterBagInterface;
use Psr\Log\LoggerInterface;

class CdnService
{
    private HttpClientInterface $httpClient;
    private string $cdnBaseUrl;
    private EntityManagerInterface $entityManager;
    private string $projectDir;
    private Filesystem $filesystem;
    private ?LoggerInterface $logger;

    public function __construct(
        HttpClientInterface $httpClient,
        EntityManagerInterface $entityManager,
        ParameterBagInterface $parameterBag,
        LoggerInterface $logger = null
    ) {
        $this->httpClient = $httpClient;
        $this->entityManager = $entityManager;
        $this->cdnBaseUrl = $parameterBag->get('cdn_base_url', 'https://cdn.exnet.cloud');
        $this->projectDir = $parameterBag->get('kernel.project_dir');
        $this->filesystem = new Filesystem();
        $this->logger = $logger;
    }

    public function getModuleAttributes(Modulo $modulo): array
    {

        $moduleAttributes = [
            'name' => $modulo->getNombre(),
            'description' => $modulo->getDescripcion(),
            'icon' => $modulo->getIcon(),
            'route' => $modulo->getRuta()
        ];

        $version = '1.0.0';

        $modulePath = $modulo->getRuta();
        if ($modulePath && $this->filesystem->exists($modulePath)) {
            $settingsPath = rtrim($modulePath, '/') . '/settings.json';

            if ($this->filesystem->exists($settingsPath)) {
                try {
                    $settingsJson = file_get_contents($settingsPath);
                    $settings = json_decode($settingsJson, true);

                    if (json_last_error() === JSON_ERROR_NONE && is_array($settings)) {

                        if (isset($settings['version']) && !empty($settings['version'])) {
                            $version = $settings['version'];
                            $this->logInfo("Versión obtenida de settings.json para el módulo {$modulo->getNombre()}: $version");
                        }

                        $moduleAttributes = array_merge($moduleAttributes, $settings);

                        $this->logInfo("Leídos atributos de settings.json para el módulo {$modulo->getNombre()}");
                    } else {
                        $this->logWarning("Error al decodificar settings.json para el módulo {$modulo->getNombre()}: " . json_last_error_msg());
                    }
                } catch (\Exception $e) {
                    $this->logError("Excepción al leer settings.json del módulo {$modulo->getNombre()}: " . $e->getMessage());
                }
            } else {
                $this->logInfo("No se encontró settings.json para el módulo {$modulo->getNombre()} en: $settingsPath");
            }
        } else {
            $this->logWarning("La ruta del módulo {$modulo->getNombre()} no existe: $modulePath");
        }

        $moduleAttributes['version'] = $version;

        if ($modulo->getVersion() !== $version) {
            try {
                $modulo->setVersion($version);
                $this->entityManager->flush();
                $this->logInfo("Actualizada versión en base de datos para el módulo {$modulo->getNombre()}: $version");
            } catch (\Exception $e) {
                $this->logError("Error al actualizar la versión en la base de datos: " . $e->getMessage());
            }
        }

        return $moduleAttributes;
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
            $installedModuleMap[$name] = $module;

            if (strpos($name, 'modulo') === 0) {
                $normalizedName = substr($name, 6);
                $installedModuleMap[$normalizedName] = $module;
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
                    $this->logError("Módulo sin 'filename' en marketplace API: " . json_encode($module));
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

                $moduleAttributes = [];
                $needsUpdate = false;
                $installedVersion = '1.0.0'; 
                $marketplaceVersion = $module['version'] ?? '1.0.0';

                if ($isInstalled) {
                    $installedModule = $installedModuleMap[$normalizedName] ?? $installedModuleMap[$normalizedNameWithoutPrefix] ?? null;
                    if ($installedModule) {
                        $moduleAttributes = $this->getModuleAttributes($installedModule);

                        $installedVersion = $installedModule->getVersion() ?? '1.0.0';

                        $originalMarketplaceVersion = $marketplaceVersion;

                        $this->logInfo("Módulo {$moduleName}: instalado={$installedVersion}, marketplace={$marketplaceVersion}");

                        if ($this->compareVersions($installedVersion, $marketplaceVersion) < 0) {
                            $needsUpdate = true;
                            $this->logInfo("Módulo {$moduleName} necesita actualización: {$installedVersion} -> {$marketplaceVersion}");
                        }

                        $moduleData = array_merge([
                            'name' => $moduleName,
                            'filename' => $module['filename'],
                            'description' => $module['description'] ?? 'Módulo para Exnet',
                            'price' => $module['price'] ?? 'free',
                            'downloadUrl' => $module['downloadUrl'] ?? null,
                            'installed' => $isInstalled,
                            'needsUpdate' => $needsUpdate,
                            'installedVersion' => $installedVersion,
                            'installCommand' => $module['installCommand'] ?? null
                        ], $moduleAttributes);

                        $moduleData['version'] = $originalMarketplaceVersion;

                        $marketplace[$moduleType][] = $moduleData;
                    }
                }

                $moduleIdentifier = $moduleType . '_' . $normalizedName;
                if (isset($processedModules[$moduleIdentifier])) {
                    continue;
                }

                $processedModules[$moduleIdentifier] = true;

                $marketplace[$moduleType][] = array_merge([
                    'name' => $moduleName,
                    'filename' => $module['filename'],
                    'description' => $module['description'] ?? 'Módulo para Exnet',
                    'version' => $marketplaceVersion, 
                    'price' => $module['price'] ?? 'free',
                    'downloadUrl' => $module['downloadUrl'] ?? null,
                    'installed' => $isInstalled,
                    'needsUpdate' => $needsUpdate,
                    'installedVersion' => $installedVersion, 
                    'installCommand' => $module['installCommand'] ?? null
                ], $isInstalled ? $moduleAttributes : []);
            }
        }

        foreach ($marketplace as $moduleType => $modules) {
            if (empty($modules)) {
                unset($marketplace[$moduleType]);
            }
        }

        return $marketplace;
    } catch (\Exception $e) {
        $this->logError("Error en getAvailableModules: " . $e->getMessage());
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
            $this->logError("Error en verifyLicense: " . $e->getMessage());
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

                $moduleAttributes = $this->getModuleAttributes($existingModulo);

                return [
                    'success' => true,
                    'message' => 'El módulo ya estaba instalado y ha sido activado',
                    'module' => $moduleAttributes,
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
                    $moduleInfo = $moduleInfoData['module'] ?? null;
                    $installCommandOutput .= "Información recibida de la API:\n" . json_encode($moduleInfo, JSON_PRETTY_PRINT) . "\n\n";
                } else {
                     $installCommandOutput .= "Error HTTP " . $response->getStatusCode() . " al obtener información del módulo.\n\n";
                }
            } catch (\Exception $e) {
                $installCommandOutput .= "Error (excepción) al obtener información del módulo: " . $e->getMessage() . "\n";
            }

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

            $zipArchive = new \ZipArchive();
            $res = $zipArchive->open($tempFile);
            if ($res !== true) {
                return ['success' => false, 'message' => 'Error al abrir el archivo ZIP', 'commandOutput' => $installCommandOutput . "Error código: $res al abrir el ZIP"];
            }
            $installCommandOutput .= "Archivo ZIP abierto correctamente. Contenidos:\n";
            for ($i = 0; $i < $zipArchive->numFiles; $i++) {
                $installCommandOutput .= " - " . $zipArchive->getNameIndex($i) . "\n";
            }

            $settingsJson = null;
            $moduleJson = null;

            $settingsJsonContent = $zipArchive->getFromName('settings.json');
            if ($settingsJsonContent !== false) {
                $installCommandOutput .= "\nContenido de settings.json encontrado:\n" . $settingsJsonContent . "\n\n";
                $settingsJson = json_decode($settingsJsonContent, true);
                if (json_last_error() !== JSON_ERROR_NONE) {
                    $installCommandOutput .= "Error al decodificar settings.json: " . json_last_error_msg() . "\n";
                    $settingsJson = null;
                }
            } else {
                $installCommandOutput .= "No se encontró el archivo settings.json en la raíz del ZIP\n";
            }

            $moduleJsonContent = $zipArchive->getFromName('module.json');
            if ($moduleJsonContent !== false) {
                $installCommandOutput .= "\nContenido de module.json (raíz) encontrado:\n" . $moduleJsonContent . "\n\n";
                $moduleJson = json_decode($moduleJsonContent, true);
                if (json_last_error() !== JSON_ERROR_NONE) {
                    $installCommandOutput .= "Error al decodificar module.json: " . json_last_error_msg() . "\n";
                    $moduleJson = null;
                }
            } else {
                $installCommandOutput .= "No se encontró el archivo module.json en la raíz del ZIP\n";
            }

            $moduleSettings = $settingsJson ?? $moduleJson ?? null;

            if (!$moduleSettings) {
                $installCommandOutput .= "Usando información de la API (si existe) o predeterminada como respaldo\n";
                if ($moduleInfo) {
                    $moduleSettings = [
                        'name' => $moduleInfo['name'] ?? $baseModuleName,
                        'description' => $moduleInfo['description'] ?? 'Módulo para Exnet',
                        'version' => $moduleInfo['version'] ?? '1.0.0',
                        'icon' => $moduleInfo['icon'] ?? 'fas fa-puzzle-piece',
                        'route' => $moduleInfo['route'] ?? '/' . strtolower($baseModuleName),
                    ];
                    $installCommandOutput .= "Información reconstruida del módulo (desde API):\n" . json_encode($moduleSettings, JSON_PRETTY_PRINT) . "\n\n";
                } else {
                    $moduleSettings = [
                        'name' => $baseModuleName,
                        'description' => 'Módulo para Exnet',
                        'version' => '1.0.0',
                        'icon' => 'fas fa-puzzle-piece',
                        'route' => '/' . strtolower($baseModuleName)
                    ];
                    $installCommandOutput .= "Información predeterminada generada para el módulo (API falló)\n";
                }
            }

            $extractPath = $this->projectDir . '/src';
            $zipArchive->extractTo($extractPath);
            $zipArchive->close();
            $installCommandOutput .= "Archivos extraídos en: $extractPath\n";
            $this->filesystem->remove($tempFile);

            $installCommand = null;

            if (isset($settingsJson['installCommand']) && !empty($settingsJson['installCommand'])) {
                $installCommandOutput .= "Verificando comando: Encontrado en settings.json (del ZIP): '" . $settingsJson['installCommand'] . "'\n";
                $installCommand = $settingsJson['installCommand'];
            }
            elseif (isset($moduleJson['installCommand']) && !empty($moduleJson['installCommand'])) {
                $installCommandOutput .= "Verificando comando: Encontrado en module.json (del ZIP): '" . $moduleJson['installCommand'] . "'\n";
                $installCommand = $moduleJson['installCommand'];
            }
            elseif (isset($moduleInfo['installCommand']) && !empty($moduleInfo['installCommand'])) {
                $installCommandOutput .= "Verificando comando: No encontrado en JSON del ZIP. Encontrado en API: '" . $moduleInfo['installCommand'] . "'\n";
                $installCommand = $moduleInfo['installCommand'];
            }
            else {
                $installCommandOutput .= "Verificando comando: No encontrado en JSONs (ZIP) ni en API. Buscando en commands.json...\n";
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
                                $installCommand = $commandsJsonData['install'];
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

                $this->logInfo('Ejecutando comando: ' . $installCommand . ' en ' . $moduleDirectoryPath);

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
                        $this->logInfo($prefix . $buffer);
                    });

                    $commandSuccess = $process->isSuccessful();
                    $installCommandOutput .= "\n=== RESULTADO DEL COMANDO ===\n";
                    $installCommandOutput .= "Código de salida: " . $process->getExitCode() . "\n";
                    $installCommandOutput .= "Comando exitoso: " . ($commandSuccess ? 'Sí' : 'No') . "\n";

                    if (!$commandSuccess) {
                        $this->logError('Comando falló con código: ' . $process->getExitCode());
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
                    $this->logError('Excepción ejecutando comando: ' . $e->getMessage());
                     return [
                         'success' => false,
                         'message' => 'Excepción al ejecutar el comando de instalación: ' . $e->getMessage(),
                         'commandSuccess' => false,
                         'commandOutput' => $installCommandOutput
                     ];
                }
            } else {
                $installCommandOutput .= "\n=== NO HAY COMANDO DE INSTALACIÓN ===\n";
                $installCommandOutput .= "No se encontró ningún comando de instalación válido.\n";
                $installCommandOutput .= "\nRevisión final de la información del módulo:\n";
                $installCommandOutput .= "moduleSettings (final): " . json_encode($moduleSettings, JSON_PRETTY_PRINT) . "\n";
                $installCommandOutput .= "moduleInfo (API): " . json_encode($moduleInfo, JSON_PRETTY_PRINT) . "\n";
            }

            if ($commandSuccess) {
                if (!isset($moduleSettings['icon'])) {
                    $moduleSettings['icon'] = 'fas fa-puzzle-piece';
                }

                $installCommandOutput .= "\nEntidad Modulo creada/actualizada en la base de datos local.\n";
            }

            if ($commandSuccess && isset($moduleSettings['migrate']) && $moduleSettings['migrate'] === true) {
                 $installCommandOutput .= "\n=== EJECUTANDO MIGRACIONES ===\n";
                 $migrateResult = $this->runMigrations();
                 $installCommandOutput .= "Resultado de migraciones: " . ($migrateResult ? "Exitoso" : "Fallido") . "\n";
            }

            $cacheClear = new Process(['php', 'bin/console', 'cache:clear']);
            $cacheClear->setWorkingDirectory($this->projectDir);
            $cacheClear->run();
            $installCommandOutput .= "\nCache cleared:\n" . $cacheClear->getOutput();

            if ($moduleDirectoryPath && $commandSuccess && $moduleSettings && !$this->filesystem->exists($moduleDirectoryPath . '/settings.json')) {
                try {
                    file_put_contents(
                        $moduleDirectoryPath . '/settings.json',
                        json_encode($moduleSettings, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)
                    );
                    $installCommandOutput .= "\nCreado archivo settings.json en el directorio del módulo.\n";
                } catch (\Exception $e) {
                    $installCommandOutput .= "\nError al crear settings.json: " . $e->getMessage() . "\n";
                }
            }

            return [
                'success' => true,
                'message' => $installCommand ? ($commandSuccess ? 'Módulo instalado y comando ejecutado correctamente' : 'Módulo instalado, pero hubo un error al ejecutar el comando') : 'Módulo instalado correctamente (sin comando de instalación)',
                'module' => $moduleSettings,
                'commandSuccess' => $commandSuccess,
                'commandOutput' => $installCommandOutput
            ];

        } catch (\Exception $e) {
            $errorOutput = $installCommandOutput;
            $errorOutput .= "\n\n=== ERROR DE EXCEPCIÓN GENERAL ===\n";
            $errorOutput .= "Mensaje: " . $e->getMessage() . "\n";
            $errorOutput .= "Archivo: " . $e->getFile() . " (línea " . $e->getLine() . ")\n";
            $errorOutput .= "Traza:\n" . $e->getTraceAsString() . "\n";

            $this->logError("Error general en instalación de módulo: " . $e->getMessage());
            $this->logError("Trace: " . $e->getTraceAsString());

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
                 $this->logInfo('Migración: ' . $buffer);
            });

            $this->logInfo("Resultado ejecución migraciones:\n" . $output);

            return $process->isSuccessful();
        } catch (\Exception $e) {
            $this->logError('Error en migraciones: ' . $e->getMessage());
            $this->logError('Trace migraciones: ' . $e->getTraceAsString());
            return false;
        }
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

    private function compareVersions(string $version1, string $version2): int
    {

        $v1 = array_map('intval', explode('.', $version1));
        $v2 = array_map('intval', explode('.', $version2));

        while (count($v1) < 3) $v1[] = 0;
        while (count($v2) < 3) $v2[] = 0;

        for ($i = 0; $i < 3; $i++) {
            if ($v1[$i] < $v2[$i]) return -1;
            if ($v1[$i] > $v2[$i]) return 1;
        }

        return 0; 
    }
}