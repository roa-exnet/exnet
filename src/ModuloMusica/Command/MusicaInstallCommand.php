<?php

namespace App\ModuloMusica\Command;

use App\ModuloCore\Entity\MenuElement;
use App\ModuloCore\Entity\Modulo;
use App\ModuloMusica\Entity\Genero;
use App\ModuloCore\Service\KeycloakModuleService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Process\Process;
use Symfony\Component\DependencyInjection\ParameterBag\ParameterBagInterface;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Contracts\HttpClient\HttpClientInterface;

#[AsCommand(
    name: 'musica:install',
    description: 'Instala el módulo de música'
)]
class MusicaInstallCommand extends Command
{
    private EntityManagerInterface $entityManager;
    private Filesystem $filesystem;
    private HttpClientInterface $httpClient;
    private ParameterBagInterface $parameterBag;
    private string $projectDir;

    public function __construct(EntityManagerInterface $entityManager, ParameterBagInterface $parameterBag, HttpClientInterface $httpClient,)
    {
        $this->entityManager = $entityManager;
        $this->filesystem = new Filesystem();
        $this->httpClient = $httpClient;
        $this->parameterBag = $parameterBag;
        $this->projectDir = $parameterBag->get('kernel.project_dir');
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
        $io->title('Instalación del Módulo de Música');

        if ($input->getOption('force')) {
            $input->setOption('yes', true);
        }

        try {
            // Limpiar caché de metadatos de Doctrine al inicio
            $io->section('Limpiando caché de metadatos de Doctrine');
            $this->clearDoctrineCache($io);
            
            if (!$input->getOption('force') && $this->isModuleInstalled()) {
                $io->warning('El módulo de Música ya está instalado. Usa --force para reinstalarlo.');
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

            $io->section('Creando carpetas y enlaces simbólicos para archivos de música');
            $this->setupAssetsSymlink($io);

            if ($input->getOption('skip-tables')) {
                $io->note('La creación de tablas ha sido omitida según los parámetros de entrada.');
                $io->success('Configuración del Módulo de Música completada exitosamente (sin tablas).');
                return Command::SUCCESS;
            }
            
            if (!$input->getOption('yes') && !$io->confirm('¿Deseas crear las tablas en la base de datos ahora?', true)) {
                $io->note('Operaciones de base de datos omitidas. Puedes ejecutarlas manualmente más tarde.');
                $io->success('Configuración de archivos completada.');
                return Command::SUCCESS;
            }
            
            // Crear tablas directamente con SQL en lugar de usar migraciones
            $tablesSuccess = $this->createTables($io);
            if (!$tablesSuccess) {
                return Command::FAILURE;
            }

            $this->createDefaultGenres($io);

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

            // Limpiar completamente la caché después de todas las operaciones
            $io->section('Reiniciando el kernel y limpiando caché');
            $this->resetKernel($io);
            
            $io->success([
                'Módulo de Música instalado correctamente.',
                'Puedes acceder a él en: /musica'
            ]);

            return Command::SUCCESS;
        } catch (\Exception $e) {
            $io->error('Error durante la instalación: ' . $e->getMessage());
            return Command::FAILURE;
        }
    }

    private function isModuleInstalled(): bool
    {
        try {
            $moduloRepository = $this->entityManager->getRepository(Modulo::class);
            $musicaModule = $moduloRepository->findOneBy(['nombre' => 'Música']);
            
            return $musicaModule !== null && $musicaModule->isEstado();
        } catch (\Exception $e) {
            return false;
        }
    }
    
    private function clearDoctrineCache(SymfonyStyle $io): void
    {
        try {
            // Limpiar caché de metadatos
            $this->entityManager->getConfiguration()->getMetadataCache()->clear();
            
            // También limpiar el caché de consultas si está disponible
            if (method_exists($this->entityManager->getConfiguration(), 'getQueryCache')) {
                $queryCache = $this->entityManager->getConfiguration()->getQueryCache();
                if ($queryCache) {
                    $queryCache->clear();
                }
            }
            
            // Limpiar el caché de resultados si está disponible
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
            // Limpiar caché de Doctrine
            $this->clearDoctrineCache($io);
            
            // Limpiar caché del sistema
            $process1 = new Process(['php', 'bin/console', 'cache:clear', '--no-warmup']);
            $process1->run();
            
            // Reconstruir el caché
            $process2 = new Process(['php', 'bin/console', 'cache:warmup']);
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
        $servicesYamlPath = 'config/services.yaml';
        $servicesContent = file_get_contents($servicesYamlPath);
        
        if (strpos($servicesContent, 'App\ModuloMusica\Controller') !== false && 
            strpos($servicesContent, '# DESACTIVADO') === false) {
            $io->note('La configuración de servicios ya incluye el módulo de música.');
            return;
        }
        
        if (strpos($servicesContent, 'App\ModuloMusica\Controller') !== false && 
            strpos($servicesContent, '# DESACTIVADO') !== false) {
            $servicesContent = str_replace(
                "#START -----------------------------------------------------  ModuloMusica (DESACTIVADO) --------------------------------------------------------------------------",
                "#START -----------------------------------------------------  ModuloMusica --------------------------------------------------------------------------",
                $servicesContent
            );
            
            $pattern = "/#(\s+App\\\\ModuloMusica\\\\)/";
            $servicesContent = preg_replace($pattern, "$1", $servicesContent);
            
            file_put_contents($servicesYamlPath, $servicesContent);
            $io->success('El módulo de música ha sido reactivado en services.yaml.');
            return;
        }
        
        $pattern = '#END\s+------+\s+ModuloCore\s+------+';
        
        if (!preg_match('/' . $pattern . '/', $servicesContent)) {
            $io->warning('No se pudo encontrar el punto de inserción en services.yaml.');
            if (!$io->confirm('¿Deseas añadir la configuración del módulo de música al final del archivo?', false)) {
                $io->note('Operación cancelada.');
                return;
            }
            
            $servicesContent .= "\n\n" . $this->getMusicaServicesConfig();
            file_put_contents($servicesYamlPath, $servicesContent);
            $io->success('La configuración del módulo de música se ha añadido al final de services.yaml.');
            return;
        }
        
        $musicaConfig = $this->getMusicaServicesConfig();
        
        $newContent = preg_replace('/' . $pattern . '/', "$0" . $musicaConfig, $servicesContent, 1);
        
        if ($newContent !== $servicesContent) {
            file_put_contents($servicesYamlPath, $newContent);
            $io->success('services.yaml actualizado con la configuración del módulo de música.');
        } else {
            $io->error('No se pudo actualizar services.yaml. Verifica el formato del archivo.');
        }
    }
    
    private function getMusicaServicesConfig(): string
    {
        return <<<EOT
        
    #START -----------------------------------------------------  ModuloMusica -------------------------------------------------------------------------- 
    App\ModuloMusica\Controller\:
        resource: '../src/ModuloMusica/Controller'
        tags: ['controller.service_arguments']
        
    App\ModuloMusica\Command\:
        resource: '../src/ModuloMusica/Command'
        tags: ['console.command']
        
    App\ModuloMusica\Service\:
        resource: '../src/ModuloMusica/Service/'
        autowire: true
        autoconfigure: true
        public: true
    #END ------------------------------------------------------- ModuloMusica -----------------------------------------------------------------------------
EOT;
    }
    
    private function updateRoutesYaml(SymfonyStyle $io): void
    {
        $routesYamlPath = 'config/routes.yaml';
        $routesContent = file_get_contents($routesYamlPath);
        
        if (strpos($routesContent, 'App\ModuloMusica\Controller') !== false && 
            strpos($routesContent, '# modulo_musica_controllers') === false) {
            $io->note('La configuración de rutas ya incluye el módulo de música.');
            return;
        }
        
        if (strpos($routesContent, '# modulo_musica_controllers') !== false) {
            $routesContent = str_replace(
                [
                    "# modulo_musica_controllers: DESACTIVADO", 
                    "# resource:", 
                    "#     path: ../src/ModuloMusica/Controller/", 
                    "#     namespace: App\ModuloMusica\Controller", 
                    "# type: attribute"
                ],
                [
                    "modulo_musica_controllers:", 
                    "    resource:", 
                    "        path: ../src/ModuloMusica/Controller/", 
                    "        namespace: App\ModuloMusica\Controller", 
                    "    type: attribute"
                ],
                $routesContent
            );
            file_put_contents($routesYamlPath, $routesContent);
            $io->success('Las rutas del módulo de música han sido descomentadas.');
            return;
        }
        
        $patterns = [
            'modulo_chat_controllers:',
            'modulo_Explorador_controllers:',
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
            if (!$io->confirm('¿Deseas añadir la configuración del módulo de música al final del archivo?', false)) {
                $io->note('Operación cancelada.');
                return;
            }
            
            $routesContent .= "\n\n" . $this->getMusicaRoutesConfig();
            file_put_contents($routesYamlPath, $routesContent);
            $io->success('La configuración del módulo de música se ha añadido al final de routes.yaml.');
            return;
        }
        
        $musicaConfig = $this->getMusicaRoutesConfig();
        
        $pattern = '/(' . preg_quote($insertPoint) . '.*?type:\s+attribute)/s';
        if (preg_match($pattern, $routesContent, $matches)) {
            $newContent = str_replace($matches[1], $matches[1] . $musicaConfig, $routesContent);
            file_put_contents($routesYamlPath, $newContent);
            $io->success('routes.yaml actualizado con las rutas del módulo de música.');
        } else {
            $io->error('No se pudo actualizar routes.yaml. Verifica el formato del archivo.');
        }
    }
    
    private function getMusicaRoutesConfig(): string
    {
        return <<<EOT


modulo_musica_controllers:
    resource:
        path: ../src/ModuloMusica/Controller/
        namespace: App\ModuloMusica\Controller
    type: attribute
EOT;
    }
    
    private function updateTwigYaml(SymfonyStyle $io): void
    {
        $twigYamlPath = 'config/packages/twig.yaml';
        $twigContent = file_get_contents($twigYamlPath);
        
        if (strpos($twigContent, "ModuloMusica/templates': ModuloMusica") !== false) {
            $io->note('La configuración de Twig ya incluye las plantillas del módulo de música.');
            return;
        }
        
        if (strpos($twigContent, "# '%kernel.project_dir%/src/ModuloMusica/templates': ~ # DESACTIVADO") !== false) {
            $twigContent = str_replace(
                "# '%kernel.project_dir%/src/ModuloMusica/templates': ~ # DESACTIVADO",
                "'%kernel.project_dir%/src/ModuloMusica/templates': ModuloMusica",
                $twigContent
            );
            file_put_contents($twigYamlPath, $twigContent);
            $io->success('Las plantillas Twig del módulo de música han sido descomentadas.');
            return;
        }
        
        if (!preg_match('/paths:/', $twigContent)) {
            $io->error('No se pudo encontrar la sección "paths" en twig.yaml');
            return;
        }
        
        $patterns = [
            "'%kernel.project_dir%/src/ModuloChat/templates': ModuloChat",
            "'%kernel.project_dir%/src/ModuloExplorador/templates': ~",
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
                "$1\n        '%kernel.project_dir%/src/ModuloMusica/templates': ModuloMusica",
                $twigContent
            );
            
            if ($newContent !== $twigContent) {
                file_put_contents($twigYamlPath, $newContent);
                $io->success('twig.yaml actualizado con las plantillas del módulo de música.');
            } else {
                $io->error('No se pudo actualizar twig.yaml. Verifica el formato del archivo.');
            }
            return;
        }
        
        $musicaConfig = "\n        '%kernel.project_dir%/src/ModuloMusica/templates': ModuloMusica";
        $newContent = str_replace($insertPoint, $insertPoint . $musicaConfig, $twigContent);
        
        if ($newContent !== $twigContent) {
            file_put_contents($twigYamlPath, $newContent);
            $io->success('twig.yaml actualizado con las plantillas del módulo de música.');
        } else {
            $io->error('No se pudo actualizar twig.yaml. Verifica el formato del archivo.');
        }
    }
    
    private function updateDoctrineYaml(SymfonyStyle $io): void
    {
        $doctrineYamlPath = 'config/packages/doctrine.yaml';
        $doctrineContent = file_get_contents($doctrineYamlPath);
        
        // Verificar si la configuración de ModuloMusica ya existe
        if (preg_match('/ModuloMusica:.*?\n\s+type: attribute\n\s+is_bundle: false\n\s+dir:.*?\n\s+prefix:.*?\n\s+alias: ModuloMusica/s', $doctrineContent)) {
            $io->note('La configuración de Doctrine ya incluye las entidades del módulo de música.');
            return;
        }
        
        // Verificar si existe la sección mappings
        if (!preg_match('/mappings:/', $doctrineContent)) {
            $io->error('No se pudo encontrar la sección "mappings" en doctrine.yaml');
            return;
        }
        
        // Determinar la indentación para la sección mappings
        $mappingsIndentation = '        '; // Indentación por defecto (8 espacios)
        $moduleIndentation = '            '; // Indentación para el módulo (12 espacios)
        
        // Configuración del módulo de música
        $musicaConfig = "\n{$moduleIndentation}ModuloMusica:
    {$moduleIndentation}    type: attribute
    {$moduleIndentation}    is_bundle: false
    {$moduleIndentation}    dir: '%kernel.project_dir%/src/ModuloMusica/Entity'
    {$moduleIndentation}    prefix: 'App\\ModuloMusica\\Entity'
    {$moduleIndentation}    alias: ModuloMusica";
        
        // Insertar la configuración justo debajo de mappings:
        $newContent = preg_replace(
            '/(mappings:)/',
            "$1{$musicaConfig}",
            $doctrineContent,
            1
        );
        
        if ($newContent !== $doctrineContent) {
            file_put_contents($doctrineYamlPath, $newContent);
            $io->success('doctrine.yaml actualizado con las entidades del módulo de música debajo de mappings.');
        } else {
            $io->error('No se pudo actualizar doctrine.yaml. Verifica el formato del archivo.');
        }
    }

    private function getParameter(string $name): string
    {
        if ($name === 'kernel.project_dir') {
            return $this->getProjectDir();
        }
        
        throw new \InvalidArgumentException(sprintf('Parámetro desconocido: %s', $name));
    }

    private function setupAssetsSymlink(SymfonyStyle $io): void
    {
        try {
            $filesystem = new Filesystem();
    
            $assetsSourcePath = $this->projectDir . '/src/ModuloMusica/Assets';
            if (!$filesystem->exists($assetsSourcePath)) {
                $io->error('La carpeta Assets no existe en el módulo Música. No se puede crear el enlace simbólico.');
                return;
            }
    
            $targetDir = $this->projectDir . '/public';
            $jsDir = $targetDir . '/js';
            if (!$filesystem->exists($jsDir)) {
                $filesystem->mkdir($jsDir);
                $io->text('Creado directorio: /public/js');
            }
    
            $symlinkPath = $targetDir . '/moduloMusica';
    
            if ($filesystem->exists($symlinkPath)) {
                if (is_link($symlinkPath)) {
                    $filesystem->remove($symlinkPath);
                    $io->text('Enlace simbólico existente eliminado');
                } else {
                    $io->warning('La ruta /public/moduloMusica existe pero no es un enlace simbólico. Eliminando...');
                    $filesystem->remove($symlinkPath);
                }
            }
    
            if (function_exists('symlink')) {
                $filesystem->symlink($assetsSourcePath, $symlinkPath);
                $io->success('Enlace simbólico creado correctamente: /public/moduloMusica -> /src/ModuloMusica/Assets');
            } else {
                $io->warning('Tu sistema no soporta enlaces simbólicos. Copiando archivos en su lugar...');
                if (!$filesystem->exists($symlinkPath)) {
                    $filesystem->mkdir($symlinkPath);
                }
                $filesystem->mirror($assetsSourcePath, $symlinkPath);
                $io->text('Archivos copiados a /public/moduloMusica');
                $filesystem->dumpFile(
                    $symlinkPath . '/README.txt',
                    "Esta carpeta contiene una copia de los assets de src/ModuloMusica/Assets.\n" .
                    "Se recomienda actualizar ambas carpetas cuando se realizan cambios en los archivos."
                );
            }
        } catch (\Exception $e) {
            $io->error('Error al configurar el enlace simbólico para los assets: ' . $e->getMessage());
        }
    }
    
    private function registerModule(SymfonyStyle $io): ?Modulo
    {
        try {
            $moduloRepository = $this->entityManager->getRepository(Modulo::class);
            $musicaModule = $moduloRepository->findOneBy(['nombre' => 'Música']);
            
            $moduleDir = __DIR__;
            $moduleBasePath = dirname($moduleDir);
            
            $io->note("Directorio base del módulo: " . $moduleBasePath);
            
            if ($musicaModule) {
                $musicaModule->setEstado(true);
                $musicaModule->setRuta($moduleBasePath);
                $io->note('El módulo Música ya existe en la base de datos. Se ha activado y actualizado su ruta.');
            } else {
                $musicaModule = new Modulo();
                $musicaModule->setNombre('Música');
                $musicaModule->setDescripcion('Módulo para gestionar y reproducir música');
                $musicaModule->setIcon('fas fa-music');
                $musicaModule->setRuta($moduleBasePath);
                $musicaModule->setEstado(true);
                $musicaModule->setInstallDate(new \DateTimeImmutable());
                
                $this->entityManager->persist($musicaModule);
                $io->success('Se ha registrado el módulo Música en la base de datos.');
            }
            
            $this->entityManager->flush();
            return $musicaModule;
        } catch (\Exception $e) {
            $io->error('Error al registrar el módulo en la base de datos: ' . $e->getMessage());
            $io->note('Puedes continuar con la instalación y añadir el módulo manualmente más tarde.');
            return null;
        }
    }

    private function getProjectDir(): string
    {
        return dirname(dirname(dirname(dirname(__DIR__))));
    }
    
    private function createMenuItems(SymfonyStyle $io, ?Modulo $modulo): void
    {
        if (!$modulo) {
            $io->warning('No se puede crear el elemento de menú sin un módulo válido.');
            return;
        }
        
        try {
            $menuRepository = $this->entityManager->getRepository(MenuElement::class);
            
            $existingMenuItem = $menuRepository->findOneBy(['nombre' => 'Música']);
            if ($existingMenuItem) {
                $existingMenuItem->setEnabled(true);
                $io->note('El elemento de menú para Música ya existe. Se ha activado.');
            } else {
                $menuItem = new MenuElement();
                $menuItem->setNombre('Música');
                $menuItem->setIcon('fas fa-music');
                $menuItem->setType('menu');
                $menuItem->setParentId(0);
                $menuItem->setRuta('/kc/musica');
                $menuItem->setEnabled(true);
                $menuItem->addModulo($modulo);
                
                $this->entityManager->persist($menuItem);
                $this->entityManager->flush();
                $existingMenuItem = $menuItem;
                $io->success('Se ha creado el elemento de menú para el módulo Música.');
            }

            $parentMenuId = $existingMenuItem->getId();

            $generalSubmenu = $menuRepository->findOneBy(['nombre' => 'General', 'parentId' => $parentMenuId]);
            if ($generalSubmenu) {
                $generalSubmenu->setEnabled(true);
                $io->note('El submenú "General" ya existe. Se ha activado.');
            } else {
                $generalSubmenu = new MenuElement();
                $generalSubmenu->setNombre('General');
                $generalSubmenu->setIcon('fas fa-list');
                $generalSubmenu->setType('menu');
                $generalSubmenu->setParentId($parentMenuId);
                $generalSubmenu->setRuta('/kc/musica');
                $generalSubmenu->setEnabled(true);
                $generalSubmenu->addModulo($modulo);
                
                $this->entityManager->persist($generalSubmenu);
                $io->success('Se ha creado el submenú "General" para el módulo Música.');
            }

            $adminSubmenu = $menuRepository->findOneBy(['nombre' => 'Admin', 'parentId' => $parentMenuId]);
            if ($adminSubmenu) {
                $adminSubmenu->setEnabled(true);
                $io->note('El submenú "Admin" ya existe. Se ha activado.');
            } else {
                $adminSubmenu = new MenuElement();
                $adminSubmenu->setNombre('Admin');
                $adminSubmenu->setIcon('fas fa-cog');
                $adminSubmenu->setType('menu');
                $adminSubmenu->setParentId($parentMenuId);
                $adminSubmenu->setRuta('/kc/musica/admin');
                $adminSubmenu->setEnabled(true);
                $adminSubmenu->addModulo($modulo);
                
                $this->entityManager->persist($adminSubmenu);
                $io->success('Se ha creado el submenú "Admin" para el módulo Música.');
            }
            
            $this->entityManager->flush();
        } catch (\Exception $e) {
            $io->error('Error al crear elementos de menú: ' . $e->getMessage());
            $io->note('Puedes crear los elementos de menú manualmente más tarde.');
        }
    }
    
    /**
     * Crea las tablas en la base de datos directamente usando SQL
     */
    private function createTables(SymfonyStyle $io): bool
    {
        $io->section('Creando tablas en la base de datos SQLite...');
        
        try {
            $conn = $this->entityManager->getConnection();
            
            // Sentencias SQL para crear las tablas
            $sqlStatements = [
                // Tabla para Genero
                "CREATE TABLE IF NOT EXISTS musica_genero (
                    id INTEGER PRIMARY KEY AUTOINCREMENT,
                    nombre VARCHAR(255) NOT NULL,
                    descripcion VARCHAR(255) DEFAULT NULL,
                    icono VARCHAR(255) DEFAULT NULL,
                    creado_en DATETIME NOT NULL
                )",
                
                // Tabla para Cancion
                "CREATE TABLE IF NOT EXISTS musica_cancion (
                    id INTEGER PRIMARY KEY AUTOINCREMENT,
                    titulo VARCHAR(255) NOT NULL,
                    artista VARCHAR(255) DEFAULT NULL,
                    album VARCHAR(255) DEFAULT NULL,
                    descripcion TEXT DEFAULT NULL,
                    imagen VARCHAR(255) DEFAULT NULL,
                    url VARCHAR(255) DEFAULT NULL,
                    es_publico BOOLEAN NOT NULL DEFAULT 1,
                    anio INTEGER DEFAULT NULL,
                    duracion INTEGER DEFAULT NULL,
                    genero_id INTEGER DEFAULT NULL,
                    creado_en DATETIME NOT NULL,
                    actualizado_en DATETIME DEFAULT NULL,
                    FOREIGN KEY (genero_id) REFERENCES musica_genero (id)
                )",
                
                // Tabla para Playlist
                "CREATE TABLE IF NOT EXISTS musica_playlist (
                    id INTEGER PRIMARY KEY AUTOINCREMENT,
                    nombre VARCHAR(255) NOT NULL,
                    descripcion TEXT DEFAULT NULL,
                    imagen VARCHAR(255) DEFAULT NULL,
                    creador_id VARCHAR(255) NOT NULL,
                    creador_nombre VARCHAR(255) DEFAULT NULL,
                    es_publica BOOLEAN NOT NULL DEFAULT 0,
                    creado_en DATETIME NOT NULL,
                    actualizado_en DATETIME DEFAULT NULL
                )",
                
                // Tabla de unión para Playlist-Cancion (many-to-many)
                "CREATE TABLE IF NOT EXISTS musica_playlist_cancion (
                    playlist_id INTEGER NOT NULL,
                    cancion_id INTEGER NOT NULL,
                    PRIMARY KEY (playlist_id, cancion_id),
                    FOREIGN KEY (playlist_id) REFERENCES musica_playlist (id) ON DELETE CASCADE,
                    FOREIGN KEY (cancion_id) REFERENCES musica_cancion (id) ON DELETE CASCADE
                )"
            ];
            
            // Ejecutar cada sentencia SQL
            foreach ($sqlStatements as $sql) {
                $conn->executeStatement($sql);
                $io->text('Ejecutada SQL: ' . substr($sql, 0, 60) . '...');
            }
            
            $io->success('Tablas creadas correctamente en la base de datos SQLite.');
            return true;
        } catch (\Exception $e) {
            $io->error('Error al crear las tablas: ' . $e->getMessage());
            return false;
        }
    }
    
    private function createDefaultGenres(SymfonyStyle $io): void
    {
        try {
            $generosDefault = [
                ['nombre' => 'Rock', 'icono' => 'fas fa-guitar', 'descripcion' => 'Rock clásico y contemporáneo'],
                ['nombre' => 'Pop', 'icono' => 'fas fa-music', 'descripcion' => 'Música popular y comercial'],
                ['nombre' => 'Hip Hop', 'icono' => 'fas fa-headphones', 'descripcion' => 'Rap y Hip Hop'],
                ['nombre' => 'Electrónica', 'icono' => 'fas fa-sliders-h', 'descripcion' => 'Música electrónica y dance'],
                ['nombre' => 'Jazz', 'icono' => 'fas fa-saxophone', 'descripcion' => 'Jazz y blues'],
                ['nombre' => 'Clásica', 'icono' => 'fas fa-violin', 'descripcion' => 'Música clásica y orquestal'],
                ['nombre' => 'Latina', 'icono' => 'fas fa-drum', 'descripcion' => 'Salsa, merengue, reggaeton y más'],
                ['nombre' => 'Country', 'icono' => 'fas fa-hat-cowboy', 'descripcion' => 'Música country y folk'],
            ];
            
            $generoRepository = $this->entityManager->getRepository(Genero::class);
            $createdCount = 0;
            
            foreach ($generosDefault as $generoData) {
                $existingGenero = $generoRepository->findOneBy(['nombre' => $generoData['nombre']]);
                
                if (!$existingGenero) {
                    $genero = new Genero();
                    $genero->setNombre($generoData['nombre']);
                    $genero->setIcono($generoData['icono']);
                    $genero->setDescripcion($generoData['descripcion']);
                    
                    $this->entityManager->persist($genero);
                    $createdCount++;
                }
            }
            
            if ($createdCount > 0) {
                $this->entityManager->flush();
                $io->success("Se han creado $createdCount géneros musicales predeterminados.");
            } else {
                $io->note('Los géneros musicales predeterminados ya están creados.');
            }
        } catch (\Exception $e) {
            $io->error('Error al crear géneros predeterminados: ' . $e->getMessage());
        }
    }
}