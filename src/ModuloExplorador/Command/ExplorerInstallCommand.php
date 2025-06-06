<?php

namespace App\ModuloExplorador\Command;

use App\ModuloCore\Entity\MenuElement;
use App\ModuloCore\Entity\Modulo;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Process\Process;
use Symfony\Component\DependencyInjection\ParameterBag\ParameterBagInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Component\Filesystem\Filesystem;

#[AsCommand(
    name: 'explorador:install',
    description: 'Instala el módulo de explorador de archivos'
)]
class ExplorerInstallCommand extends Command
{
    private EntityManagerInterface $entityManager;
    private HttpClientInterface $httpClient;
    private ParameterBagInterface $parameterBag;
    private string $projectDir;

    public function __construct(EntityManagerInterface $entityManager, ParameterBagInterface $parameterBag, HttpClientInterface $httpClient)
    {
        $this->entityManager = $entityManager;
        $this->projectDir = $parameterBag->get('kernel.project_dir');
        $this->httpClient = $httpClient;
        $this->parameterBag = $parameterBag;
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('force', 'f', InputOption::VALUE_NONE, 'Forzar la instalación incluso si el módulo ya está instalado')
            ->addOption('skip-tables', null, InputOption::VALUE_NONE, 'Omitir la creación de tablas en la base de datos')
            ->addOption('skip-menu', null, InputOption::VALUE_NONE, 'Omitir la creación de elementos de menú')
            ->addOption('yes', 'y', InputOption::VALUE_NONE, 'Confirmar automáticamente todas las preguntas');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $io->title('Instalación del Módulo de Explorador de Archivos');

        if ($input->getOption('force')) {
            $input->setOption('yes', true);
        }

        try {
            $io->section('Limpiando caché de metadatos de Doctrine');
            $this->clearDoctrineCache($io);
            
            if (!$input->getOption('force') && $this->isModuleInstalled()) {
                $io->warning('El módulo de Explorador ya está instalado. Usa --force para reinstalarlo.');
                return Command::SUCCESS;
            }

            $this->updateServicesYaml($io);
            $this->updateRoutesYaml($io);
            $this->updateTwigYaml($io);
            $this->updateDoctrineYaml($io);

            $io->section('Limpiando caché del sistema');
            $this->clearCache($io);
            
            $modulo = $this->registerModule($io);
            
            if (!$input->getOption('skip-menu')) {
                $this->createMenuItems($io, $modulo);
            }

            if ($input->getOption('skip-tables')) {
                $io->note('La creación de tablas ha sido omitida según los parámetros de entrada.');
                $io->success('Configuración del Módulo de Explorador completada exitosamente (sin tablas).');
                return Command::SUCCESS;
            }
            
            if (!$input->getOption('yes') && !$io->confirm('¿Deseas crear las tablas en la base de datos ahora?', true)) {
                $io->note('Operaciones de base de datos omitidas. Puedes ejecutarlas manualmente más tarde.');
                $io->success('Configuración de archivos completada.');
                return Command::SUCCESS;
            }
            
            $this->createExplorerDirectories($io);

            $io->section('Configurando acceso a los assets del módulo');
            $this->setupAssetsSymlink($io);

            $io->section('Reiniciando el kernel y limpiando caché');
            $this->resetKernel($io);
            
            $io->success([
                'Módulo de Explorador de Archivos instalado correctamente.',
                'Puedes acceder a él en: /kc/explorador/archivos'
            ]);

            // 8. Crear cliente y licencia de keycloak para el modulo
            $io->section('Paso 8: Registrar licencia del módulo');

            $moduleFilePath = __DIR__;
            $modulePath = dirname($moduleFilePath);

            $settingsPath = $modulePath . "/settings.json";

            if (!file_exists($settingsPath)) {
                $io->error('No se encontró settings.json en la ruta: ' . $settingsPath);
                return Command::FAILURE;
            }


            $settings = json_decode(file_get_contents($settingsPath), true);
            $moduloNombre = $settings['name'] ?? null;
            $io->success('nombre del modulo: ' . $moduloNombre);
            if (!$moduloNombre) {
                $io->error('El archivo settings.json no contiene un campo "nombre".');
                return Command::FAILURE;
            }

            $moduleService = new \App\ModuloCore\Service\KeycloakModuleService(
                $this->httpClient,
                $this->entityManager->getConnection(),
                $this->parameterBag
            );

            $res = $moduleService->registrarLicencia($moduloNombre);
            if ($res['success']) {
                $io->success('Licencia registrada exitosamente');
            } else {
                $io->error('Error registrando licencia: ' . $res['error']);
                return Command::FAILURE;
            }

            $io->section('Limpieza final de caché');
            $cacheClearProcess = new Process(['php', 'bin/console', 'cache:clear']);
            $cacheClearProcess->setTimeout(120);
            $cacheClearProcess->run(function ($type, $buffer) use ($io) {
                $io->write($buffer);
            });
    
            if (!$cacheClearProcess->isSuccessful()) {
                $io->warning('Advertencia: No se pudo completar la limpieza final de caché. Es posible que necesites ejecutar manualmente: php bin/console cache:clear');
            } else {
                $io->success('Caché limpiada correctamente. Todas las configuraciones han sido aplicadas.');
            }

            return Command::SUCCESS;
        } catch (\Exception $e) {
            $io->error('Error durante la instalación: ' . $e->getMessage());
            $io->error($e->getTraceAsString());
            return Command::FAILURE;
        }
    }

    private function isModuleInstalled(): bool
    {
        try {
            $moduloRepository = $this->entityManager->getRepository(Modulo::class);
            $exploradorModule = $moduloRepository->findOneBy(['nombre' => 'Explorador']);
            
            return $exploradorModule !== null && $exploradorModule->isEstado();
        } catch (\Exception $e) {
            return false;
        }
    }
    
    private function clearDoctrineCache(SymfonyStyle $io): void
    {
        try {
            $this->entityManager->getConfiguration()->getMetadataCache()->clear();
            
            if (method_exists($this->entityManager->getConfiguration(), 'getQueryCache')) {
                $queryCache = $this->entityManager->getConfiguration()->getQueryCache();
                if ($queryCache) {
                    $queryCache->clear();
                }
            }
            
            if (method_exists($this->entityManager->getConfiguration(), 'getResultCache')) {
                $resultCache = $this->entityManager->getConfiguration()->getResultCache();
                if ($resultCache) {
                    $resultCache->clear();
                }
            }
            
            $io->success('Caché de Doctrine limpiada correctamente.');
        } catch (\Exception $e) {
            $io->warning('Error al limpiar la caché de Doctrine: ' . $e->getMessage());
        }
    }
    
    private function clearCache(SymfonyStyle $io): void
    {
        try {
            $process = new Process(['php', 'bin/console', 'cache:clear', '--no-warmup']);
            $process->setWorkingDirectory($this->projectDir);
            $process->run();
            
            if (!$process->isSuccessful()) {
                $io->warning('Error al limpiar la caché: ' . $process->getErrorOutput());
            } else {
                $io->success('Caché limpiada correctamente.');
            }
        } catch (\Exception $e) {
            $io->error('Error al limpiar la caché: ' . $e->getMessage());
        }
    }
    
    private function resetKernel(SymfonyStyle $io): void
    {
        try {
            $this->clearDoctrineCache($io);
            
            $process1 = new Process(['php', 'bin/console', 'cache:clear', '--no-warmup']);
            $process1->setWorkingDirectory($this->projectDir);
            $process1->run();
            
            $process2 = new Process(['php', 'bin/console', 'cache:warmup']);
            $process2->setWorkingDirectory($this->projectDir);
            $process2->run();
            
            if (!$process1->isSuccessful() || !$process2->isSuccessful()) {
                $io->warning('Error al resetear el kernel: ' . $process1->getErrorOutput() . ' ' . $process2->getErrorOutput());
            } else {
                $io->success('Kernel reseteado y caché reconstruido correctamente.');
            }
        } catch (\Exception $e) {
            $io->error('Error al resetear el kernel: ' . $e->getMessage());
        }
    }
    
    private function updateServicesYaml(SymfonyStyle $io): void
    {
        $servicesYamlPath = $this->projectDir . '/config/services.yaml';
        $servicesContent = file_get_contents($servicesYamlPath);
        
        if (strpos($servicesContent, 'App\ModuloExplorador\Controller') !== false && 
            strpos($servicesContent, '# DESACTIVADO') === false) {
            $io->note('La configuración de servicios ya incluye el módulo de explorador.');
            return;
        }
        
        if (strpos($servicesContent, 'App\ModuloExplorador\Controller') !== false && 
            strpos($servicesContent, '# DESACTIVADO') !== false) {
            $servicesContent = str_replace(
                "#START ----------------------------------------------------- ModuloExplorador (DESACTIVADO) --------------------------------------------------------------------------",
                "#START ----------------------------------------------------- ModuloExplorador --------------------------------------------------------------------------",
                $servicesContent
            );
            
            $pattern = "/#(\s+App\\\\ModuloExplorador\\\\)/";
            $servicesContent = preg_replace($pattern, "$1", $servicesContent);
            
            file_put_contents($servicesYamlPath, $servicesContent);
            $io->success('El módulo de explorador ha sido reactivado en services.yaml.');
            return;
        }
        
        $pattern = '#END\s+------+\s+ModuloCore\s+------+';
        
        if (!preg_match('/' . $pattern . '/', $servicesContent)) {
            $io->warning('No se pudo encontrar el punto de inserción en services.yaml.');
            if (!$io->confirm('¿Deseas añadir la configuración del módulo de explorador al final del archivo?', false)) {
                $io->note('Operación cancelada.');
                return;
            }
            
            $servicesContent .= "\n\n" . $this->getExplorerServicesConfig();
            file_put_contents($servicesYamlPath, $servicesContent);
            $io->success('La configuración del módulo de explorador se ha añadido al final de services.yaml.');
            return;
        }
        
        $explorerConfig = $this->getExplorerServicesConfig();
        
        $newContent = preg_replace('/' . $pattern . '/', "$0" . $explorerConfig, $servicesContent, 1);
        
        if ($newContent !== $servicesContent) {
            file_put_contents($servicesYamlPath, $newContent);
            $io->success('services.yaml actualizado con la configuración del módulo de explorador.');
        } else {
            $io->error('No se pudo actualizar services.yaml. Verifica el formato del archivo.');
        }
    }
    
    private function getExplorerServicesConfig(): string
    {
        return <<<EOT
        
    #START ----------------------------------------------------- ModuloExplorador -------------------------------------------------------------------------- 
    App\ModuloExplorador\Controller\:
        resource: '../src/ModuloExplorador/Controller'
        tags: ['controller.service_arguments']
        
    App\ModuloExplorador\Command\:
        resource: '../src/ModuloExplorador/Command'
        tags: ['console.command']
        
    App\ModuloExplorador\Service\:
        resource: '../src/ModuloExplorador/Service/'
        autowire: true
        autoconfigure: true
        public: true
    #END ------------------------------------------------------- ModuloExplorador -----------------------------------------------------------------------------
EOT;
    }
    
    private function updateRoutesYaml(SymfonyStyle $io): void
    {
        $routesYamlPath = $this->projectDir . '/config/routes.yaml';
        $routesContent = file_get_contents($routesYamlPath);
        
        if (strpos($routesContent, 'App\ModuloExplorador\Controller') !== false && 
            strpos($routesContent, '# modulo_explorador_controllers') === false) {
            $io->note('La configuración de rutas ya incluye el módulo de explorador.');
            return;
        }
        
        if (strpos($routesContent, '# modulo_explorador_controllers') !== false) {
            $routesContent = str_replace(
                [
                    "# modulo_explorador_controllers: DESACTIVADO", 
                    "# resource:", 
                    "#     path: ../src/ModuloExplorador/Controller/", 
                    "#     namespace: App\ModuloExplorador\Controller", 
                    "# type: attribute"
                ],
                [
                    "modulo_explorador_controllers:", 
                    "    resource:", 
                    "        path: ../src/ModuloExplorador/Controller/", 
                    "        namespace: App\ModuloExplorador\Controller", 
                    "    type: attribute"
                ],
                $routesContent
            );
            file_put_contents($routesYamlPath, $routesContent);
            $io->success('Las rutas del módulo de explorador han sido descomentadas.');
            return;
        }
        
        $patterns = [
            'modulo_streaming_controllers:',
            'modulo_chat_controllers:',
            'modulo_musica_controllers:',
            'modulo_core_controllers:'
        ];
        
        $insertPoint = null;
        foreach ($patterns as $pattern) {
            if (strpos($routesContent, $pattern) !== false) {
                $insertPoint = $pattern;
                break;
            }
        }
        
        if ($insertPoint === null) {
            $io->warning('No se pudo encontrar un punto de inserción seguro en routes.yaml.');
            if (!$io->confirm('¿Deseas añadir la configuración del módulo de explorador al final del archivo?', false)) {
                $io->note('Operación cancelada.');
                return;
            }
            
            $routesContent .= "\n\n" . $this->getExplorerRoutesConfig();
            file_put_contents($routesYamlPath, $routesContent);
            $io->success('La configuración del módulo de explorador se ha añadido al final de routes.yaml.');
            return;
        }
        
        $explorerConfig = $this->getExplorerRoutesConfig();
        
        $pattern = '/(' . preg_quote($insertPoint) . '.*?type:\s+attribute)/s';
        if (preg_match($pattern, $routesContent, $matches)) {
            $newContent = str_replace($matches[1], $matches[1] . $explorerConfig, $routesContent);
            file_put_contents($routesYamlPath, $newContent);
            $io->success('routes.yaml actualizado con las rutas del módulo de explorador.');
        } else {
            $io->error('No se pudo actualizar routes.yaml. Verifica el formato del archivo.');
        }
    }
    
    private function getExplorerRoutesConfig(): string
    {
        return <<<EOT


modulo_explorador_controllers:
    resource:
        path: ../src/ModuloExplorador/Controller/
        namespace: App\ModuloExplorador\Controller
    type: attribute
EOT;
    }
    
    private function updateTwigYaml(SymfonyStyle $io): void
    {
        $twigYamlPath = $this->projectDir . '/config/packages/twig.yaml';
        $twigContent = file_get_contents($twigYamlPath);
        
        if (strpos($twigContent, "ModuloExplorador/templates': ModuloExplorador") !== false) {
            $io->note('La configuración de Twig ya incluye las plantillas del módulo de explorador.');
            return;
        }
        
        if (strpos($twigContent, "# '%kernel.project_dir%/src/ModuloExplorador/templates': ~ # DESACTIVADO") !== false) {
            $twigContent = str_replace(
                "# '%kernel.project_dir%/src/ModuloExplorador/templates': ~ # DESACTIVADO",
                "'%kernel.project_dir%/src/ModuloExplorador/templates': ModuloExplorador",
                $twigContent
            );
            file_put_contents($twigYamlPath, $twigContent);
            $io->success('Las plantillas Twig del módulo de explorador han sido descomentadas.');
            return;
        }
        
        if (!preg_match('/paths:/', $twigContent)) {
            $io->error('No se pudo encontrar la sección "paths" en twig.yaml');
            return;
        }
        
        $patterns = [
            "'%kernel.project_dir%/src/ModuloStreaming/templates': ModuloStreaming",
            "'%kernel.project_dir%/src/ModuloMusica/templates': ModuloMusica",
            "'%kernel.project_dir%/src/ModuloChat/templates': ModuloChat",
            "'%kernel.project_dir%/src/ModuloCore/templates': ~"
        ];
        
        $insertPoint = null;
        foreach ($patterns as $pattern) {
            if (strpos($twigContent, $pattern) !== false) {
                $insertPoint = $pattern;
                break;
            }
        }
        
        if ($insertPoint === null) {
            $io->warning('No se pudo encontrar un punto de inserción específico en twig.yaml.');
            
            $newContent = preg_replace(
                '/(paths:)/i',
                "$1\n        '%kernel.project_dir%/src/ModuloExplorador/templates': ModuloExplorador",
                $twigContent
            );
            
            if ($newContent !== $twigContent) {
                file_put_contents($twigYamlPath, $newContent);
                $io->success('twig.yaml actualizado con las plantillas del módulo de explorador.');
            } else {
                $io->error('No se pudo actualizar twig.yaml. Verifica el formato del archivo.');
            }
            return;
        }
        
        $explorerConfig = "\n        '%kernel.project_dir%/src/ModuloExplorador/templates': ModuloExplorador";
        $newContent = str_replace($insertPoint, $insertPoint . $explorerConfig, $twigContent);
        
        if ($newContent !== $twigContent) {
            file_put_contents($twigYamlPath, $newContent);
            $io->success('twig.yaml actualizado con las plantillas del módulo de explorador.');
        } else {
            $io->error('No se pudo actualizar twig.yaml. Verifica el formato del archivo.');
        }
    }

    private function setupAssetsSymlink(SymfonyStyle $io): void
    {
        try {
            $filesystem = new Filesystem();
            
            $assetsSourcePath = $this->projectDir . '/src/ModuloExplorador/Assets';
            if (!$filesystem->exists($assetsSourcePath)) {
                $io->error('La carpeta Assets no existe en el módulo Explorador. No se puede crear el enlace simbólico.');
                return;
            }
            
            $targetParentDir = $this->projectDir . '/public';
            
            $symlinkPath = $targetParentDir . '/ModuloExplorador';
            
            if ($filesystem->exists($symlinkPath)) {
                if (is_link($symlinkPath)) {
                    $filesystem->remove($symlinkPath);
                    $io->text('Enlace simbólico existente eliminado');
                } else {
                    $io->warning('La ruta /public/ModuloExplorador existe pero no es un enlace simbólico. Eliminando...');
                    $filesystem->remove($symlinkPath);
                }
            }
            
            if (function_exists('symlink')) {
                $filesystem->symlink(
                    $assetsSourcePath,
                    $symlinkPath
                );
                $io->success('Enlace simbólico creado correctamente: /public/ModuloExplorador -> /src/ModuloExplorador/Assets');
            } else {
                $io->warning('Tu sistema no soporta enlaces simbólicos. Copiando archivos en su lugar...');
                
                if (!$filesystem->exists($symlinkPath)) {
                    $filesystem->mkdir($symlinkPath);
                }
                
                $filesystem->mirror($assetsSourcePath, $symlinkPath);
                $io->text('Archivos copiados a /public/ModuloExplorador');
                
                $filesystem->dumpFile(
                    $symlinkPath . '/README.txt',
                    "Esta carpeta contiene una copia de los assets de src/ModuloExplorador/Assets.\n" .
                    "Se recomienda actualizar ambas carpetas cuando se realizan cambios en los archivos."
                );
            }
        } catch (\Exception $e) {
            $io->error('Error al configurar el enlace simbólico para los assets: ' . $e->getMessage());
        }
    }

    private function createExplorerDirectories(SymfonyStyle $io): void
    {
        $io->section('Creando directorios necesarios para el explorador de archivos');
        
        $rootExplorerPath = $this->projectDir . '/var/workspace';
        
        if (!is_dir($rootExplorerPath)) {
            try {
                mkdir($rootExplorerPath, 0755, true);
                $io->success("Directorio principal para explorador creado: $rootExplorerPath");
            } catch (\Exception $e) {
                $io->error("No se pudo crear el directorio principal en $rootExplorerPath: " . $e->getMessage());
                $io->note("Asegúrate de tener los permisos adecuados o crea este directorio manualmente.");
            }
        } else {
            $io->note("El directorio principal $rootExplorerPath ya existe.");
        }
        
        $subDirectories = [
            $rootExplorerPath . '/documentos',
            $rootExplorerPath . '/imagenes',
            $rootExplorerPath . '/videos',
            $rootExplorerPath . '/musica',
            $rootExplorerPath . '/compartido'
        ];
        
        foreach ($subDirectories as $dir) {
            if (!is_dir($dir)) {
                try {
                    mkdir($dir, 0755, true);
                    $io->text("Creado subdirectorio: $dir");
                } catch (\Exception $e) {
                    $io->warning("No se pudo crear el subdirectorio $dir: " . $e->getMessage());
                }
            } else {
                $io->text("El subdirectorio $dir ya existe.");
            }
        }
        
        $welcomeFile = $rootExplorerPath . '/bienvenido.txt';
        if (!file_exists($welcomeFile)) {
            try {
                $content = "Bienvenido al Explorador de Archivos de Exnet.\n\n" .
                           "Este es tu espacio personal para almacenar y gestionar tus archivos.\n" .
                           "Fecha de creación: " . date('Y-m-d H:i:s') . "\n";
                
                file_put_contents($welcomeFile, $content);
                $io->text("Creado archivo de bienvenida.");
            } catch (\Exception $e) {
                $io->warning("No se pudo crear el archivo de bienvenida: " . $e->getMessage());
            }
        }
        
        $io->success('Configuración de directorios completada.');
    }

    private function updateDoctrineYaml(SymfonyStyle $io): void
    {
        $doctrineYamlPath = $this->projectDir . '/config/packages/doctrine.yaml';
        $doctrineContent = file_get_contents($doctrineYamlPath);
        
        if (preg_match('/ModuloExplorador:.*?\n\s+type: attribute\n\s+is_bundle: false\n\s+dir:.*?\n\s+prefix:.*?\n\s+alias: ModuloExplorador/s', $doctrineContent)) {
            $io->note('La configuración de Doctrine ya incluye las entidades del módulo de explorador.');
            return;
        }
        
        if (!preg_match('/mappings:/', $doctrineContent)) {
            $io->error('No se pudo encontrar la sección "mappings" en doctrine.yaml');
            return;
        }
        
        $mappingsIndentation = '        ';
        $moduleIndentation = '            ';
        
        $explorerConfig = "\n{$moduleIndentation}ModuloExplorador:
    {$moduleIndentation}    type: attribute
    {$moduleIndentation}    is_bundle: false
    {$moduleIndentation}    dir: '%kernel.project_dir%/src/ModuloExplorador/Entity'
    {$moduleIndentation}    prefix: 'App\\ModuloExplorador\\Entity'
    {$moduleIndentation}    alias: ModuloExplorador";
        
        $newContent = preg_replace(
            '/(mappings:)/',
            "$1{$explorerConfig}",
            $doctrineContent,
            1
        );
        
        if ($newContent !== $doctrineContent) {
            file_put_contents($doctrineYamlPath, $newContent);
            $io->success('doctrine.yaml actualizado con las entidades del módulo de explorador debajo de mappings.');
        } else {
            $io->error('No se pudo actualizar doctrine.yaml. Verifica el formato del archivo.');
        }
    }
    
    private function registerModule(SymfonyStyle $io): ?Modulo
    {
        try {
            $moduloRepository = $this->entityManager->getRepository(Modulo::class);
            $exploradorModule = $moduloRepository->findOneBy(['nombre' => 'Explorador']);
            
            if ($exploradorModule) {
                $exploradorModule->setEstado(true);
                $exploradorModule->setInstallDate(new \DateTimeImmutable());
                $exploradorModule->setUninstallDate(null);
                $io->note('El módulo Explorador ya existe en la base de datos. Se ha activado.');
            } else {
                $exploradorModule = new Modulo();
                $exploradorModule->setNombre('Explorador');
                $exploradorModule->setDescripcion('Módulo para explorar y gestionar archivos del sistema');
                $exploradorModule->setIcon('fas fa-folder-open');
                $exploradorModule->setRuta('/kc/explorador/archivos');  // Actualizado con prefijo /kc
                $exploradorModule->setEstado(true);
                $exploradorModule->setInstallDate(new \DateTimeImmutable());
                
                $this->entityManager->persist($exploradorModule);
                $io->success('Se ha registrado el módulo Explorador en la base de datos.');
            }
            
            $this->entityManager->flush();
            return $exploradorModule;
        } catch (\Exception $e) {
            $io->error('Error al registrar el módulo en la base de datos: ' . $e->getMessage());
            $io->note('Puedes continuar con la instalación y añadir el módulo manualmente más tarde.');
            return null;
        }
    }
    
    private function createMenuItems(SymfonyStyle $io, ?Modulo $modulo): void
    {
        if (!$modulo) {
            $io->warning('No se puede crear el elemento de menú sin un módulo válido.');
            return;
        }
        
        try {
            $menuRepository = $this->entityManager->getRepository(MenuElement::class);
            
            $existingMenuItem = $menuRepository->findOneBy(['nombre' => 'Explorador']);
            if ($existingMenuItem) {
                $existingMenuItem->setEnabled(true);
                // Actualizar la ruta para que use el prefijo kc
                $existingMenuItem->setRuta('/kc/explorador/archivos');
                $io->note('El elemento de menú para Explorador ya existe. Se ha activado y actualizado su ruta.');
                $this->entityManager->flush();
            } else {
                $menuItem = new MenuElement();
                $menuItem->setNombre('Explorador');
                $menuItem->setIcon('fas fa-folder-open');
                $menuItem->setType('menu');
                $menuItem->setParentId(0);
                $menuItem->setRuta('/kc/explorador/archivos');  // Actualizado con prefijo /kc
                $menuItem->setEnabled(true);
                $menuItem->addModulo($modulo);
                
                $this->entityManager->persist($menuItem);
                $this->entityManager->flush();
                $io->success('Se ha creado el elemento de menú para el módulo Explorador.');
            }
            
        } catch (\Exception $e) {
            $io->error('Error al crear elementos de menú: ' . $e->getMessage());
            $io->note('Puedes crear los elementos de menú manualmente más tarde.');
        }
    }
}