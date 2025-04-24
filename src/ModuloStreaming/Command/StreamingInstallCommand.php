<?php

namespace App\ModuloStreaming\Command;

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
use Symfony\Component\Filesystem\Filesystem;

#[AsCommand(
    name: 'streaming:install',
    description: 'Instala el módulo de streaming'
)]
class StreamingInstallCommand extends Command
{
    private EntityManagerInterface $entityManager;
    private string $projectDir;

    public function __construct(EntityManagerInterface $entityManager, ParameterBagInterface $parameterBag)
    {
        $this->entityManager = $entityManager;
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
        $io->title('Instalación del Módulo de Streaming');

        if ($input->getOption('force')) {
            $input->setOption('yes', true);
        }

        try {
            // Limpiar caché de metadatos de Doctrine al inicio
            $io->section('Limpiando caché de metadatos de Doctrine');
            $this->clearDoctrineCache($io);
            
            if (!$input->getOption('force') && $this->isModuleInstalled()) {
                $io->warning('El módulo de Streaming ya está instalado. Usa --force para reinstalarlo.');
                return Command::SUCCESS;
            }

            // Actualizar archivos de configuración
            $this->updateServicesYaml($io);
            $this->updateRoutesYaml($io);
            $this->updateTwigYaml($io);
            $this->updateDoctrineYaml($io);

            // Limpiar caché después de actualizar los archivos de configuración
            $io->section('Limpiando caché del sistema');
            $this->clearCache($io);
            
            // Registrar el módulo en la base de datos
            $modulo = $this->registerModule($io);
            
            // Crear elementos de menú si no se ha omitido
            if (!$input->getOption('skip-menu')) {
                $this->createMenuItems($io, $modulo);
            }

            // Si se omiten las tablas, terminar aquí
            if ($input->getOption('skip-tables')) {
                $io->note('La creación de tablas ha sido omitida según los parámetros de entrada.');
                $io->success('Configuración del Módulo de Streaming completada exitosamente (sin tablas).');
                return Command::SUCCESS;
            }
            
            // Confirmar creación de tablas si no es automático
            if (!$input->getOption('yes') && !$io->confirm('¿Deseas crear las tablas en la base de datos ahora?', true)) {
                $io->note('Operaciones de base de datos omitidas. Puedes ejecutarlas manualmente más tarde.');
                $io->success('Configuración de archivos completada.');
                return Command::SUCCESS;
            }
            
            // Crear tablas directamente con SQL
            $tablesSuccess = $this->createTables($io);
            if (!$tablesSuccess) {
                return Command::FAILURE;
            }

            // Crear categorías predeterminadas
            $this->createDefaultCategories($io);

            $this->ensureUploadsDirectory($io);

            $io->section('Configurando acceso a los assets del módulo');
            $this->setupAssetsSymlink($io);

            // Limpiar completamente la caché después de todas las operaciones
            $io->section('Reiniciando el kernel y limpiando caché');
            $this->resetKernel($io);
            
            $io->success([
                'Módulo de Streaming instalado correctamente.',
                'Puedes acceder a él en: /streaming'
            ]);

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
            $streamingModule = $moduloRepository->findOneBy(['nombre' => 'Streaming']);
            
            return $streamingModule !== null && $streamingModule->isEstado();
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
            // Limpiar caché de Doctrine
            $this->clearDoctrineCache($io);
            
            // Limpiar caché del sistema
            $process1 = new Process(['php', 'bin/console', 'cache:clear', '--no-warmup']);
            $process1->setWorkingDirectory($this->projectDir);
            $process1->run();
            
            // Reconstruir el caché
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
        
        if (strpos($servicesContent, 'App\ModuloStreaming\Controller') !== false && 
            strpos($servicesContent, '# DESACTIVADO') === false) {
            $io->note('La configuración de servicios ya incluye el módulo de streaming.');
            return;
        }
        
        if (strpos($servicesContent, 'App\ModuloStreaming\Controller') !== false && 
            strpos($servicesContent, '# DESACTIVADO') !== false) {
            $servicesContent = str_replace(
                "#START -----------------------------------------------------  ModuloStreaming (DESACTIVADO) --------------------------------------------------------------------------",
                "#START -----------------------------------------------------  ModuloStreaming --------------------------------------------------------------------------",
                $servicesContent
            );
            
            $pattern = "/#(\s+App\\\\ModuloStreaming\\\\)/";
            $servicesContent = preg_replace($pattern, "$1", $servicesContent);
            
            file_put_contents($servicesYamlPath, $servicesContent);
            $io->success('El módulo de streaming ha sido reactivado en services.yaml.');
            return;
        }
        
        $pattern = '#END\s+------+\s+ModuloCore\s+------+';
        
        if (!preg_match('/' . $pattern . '/', $servicesContent)) {
            $io->warning('No se pudo encontrar el punto de inserción en services.yaml.');
            if (!$io->confirm('¿Deseas añadir la configuración del módulo de streaming al final del archivo?', false)) {
                $io->note('Operación cancelada.');
                return;
            }
            
            $servicesContent .= "\n\n" . $this->getStreamingServicesConfig();
            file_put_contents($servicesYamlPath, $servicesContent);
            $io->success('La configuración del módulo de streaming se ha añadido al final de services.yaml.');
            return;
        }
        
        $streamingConfig = $this->getStreamingServicesConfig();
        
        $newContent = preg_replace('/' . $pattern . '/', "$0" . $streamingConfig, $servicesContent, 1);
        
        if ($newContent !== $servicesContent) {
            file_put_contents($servicesYamlPath, $newContent);
            $io->success('services.yaml actualizado con la configuración del módulo de streaming.');
        } else {
            $io->error('No se pudo actualizar services.yaml. Verifica el formato del archivo.');
        }
    }
    
    private function getStreamingServicesConfig(): string
    {
        return <<<EOT
        
    #START -----------------------------------------------------  ModuloStreaming -------------------------------------------------------------------------- 
    App\ModuloStreaming\Controller\:
        resource: '../src/ModuloStreaming/Controller'
        tags: ['controller.service_arguments']
        
    App\ModuloStreaming\Command\:
        resource: '../src/ModuloStreaming/Command'
        tags: ['console.command']
        
    App\ModuloStreaming\Service\:
        resource: '../src/ModuloStreaming/Service/'
        autowire: true
        autoconfigure: true
        public: true
    #END ------------------------------------------------------- ModuloStreaming -----------------------------------------------------------------------------
EOT;
    }
    
    private function updateRoutesYaml(SymfonyStyle $io): void
    {
        $routesYamlPath = $this->projectDir . '/config/routes.yaml';
        $routesContent = file_get_contents($routesYamlPath);
        
        if (strpos($routesContent, 'App\ModuloStreaming\Controller') !== false && 
            strpos($routesContent, '# modulo_streaming_controllers') === false) {
            $io->note('La configuración de rutas ya incluye el módulo de streaming.');
            return;
        }
        
        if (strpos($routesContent, '# modulo_streaming_controllers') !== false) {
            $routesContent = str_replace(
                [
                    "# modulo_streaming_controllers: DESACTIVADO", 
                    "# resource:", 
                    "#     path: ../src/ModuloStreaming/Controller/", 
                    "#     namespace: App\ModuloStreaming\Controller", 
                    "# type: attribute"
                ],
                [
                    "modulo_streaming_controllers:", 
                    "    resource:", 
                    "        path: ../src/ModuloStreaming/Controller/", 
                    "        namespace: App\ModuloStreaming\Controller", 
                    "    type: attribute"
                ],
                $routesContent
            );
            file_put_contents($routesYamlPath, $routesContent);
            $io->success('Las rutas del módulo de streaming han sido descomentadas.');
            return;
        }
        
        $patterns = [
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
            if (!$io->confirm('¿Deseas añadir la configuración del módulo de streaming al final del archivo?', false)) {
                $io->note('Operación cancelada.');
                return;
            }
            
            $routesContent .= "\n\n" . $this->getStreamingRoutesConfig();
            file_put_contents($routesYamlPath, $routesContent);
            $io->success('La configuración del módulo de streaming se ha añadido al final de routes.yaml.');
            return;
        }
        
        $streamingConfig = $this->getStreamingRoutesConfig();
        
        $pattern = '/(' . preg_quote($insertPoint) . '.*?type:\s+attribute)/s';
        if (preg_match($pattern, $routesContent, $matches)) {
            $newContent = str_replace($matches[1], $matches[1] . $streamingConfig, $routesContent);
            file_put_contents($routesYamlPath, $newContent);
            $io->success('routes.yaml actualizado con las rutas del módulo de streaming.');
        } else {
            $io->error('No se pudo actualizar routes.yaml. Verifica el formato del archivo.');
        }
    }
    
    private function getStreamingRoutesConfig(): string
    {
        return <<<EOT


modulo_streaming_controllers:
    resource:
        path: ../src/ModuloStreaming/Controller/
        namespace: App\ModuloStreaming\Controller
    type: attribute
EOT;
    }
    
    private function updateTwigYaml(SymfonyStyle $io): void
    {
        $twigYamlPath = $this->projectDir . '/config/packages/twig.yaml';
        $twigContent = file_get_contents($twigYamlPath);
        
        if (strpos($twigContent, "ModuloStreaming/templates': ModuloStreaming") !== false) {
            $io->note('La configuración de Twig ya incluye las plantillas del módulo de streaming.');
            return;
        }
        
        if (strpos($twigContent, "# '%kernel.project_dir%/src/ModuloStreaming/templates': ~ # DESACTIVADO") !== false) {
            $twigContent = str_replace(
                "# '%kernel.project_dir%/src/ModuloStreaming/templates': ~ # DESACTIVADO",
                "'%kernel.project_dir%/src/ModuloStreaming/templates': ModuloStreaming",
                $twigContent
            );
            file_put_contents($twigYamlPath, $twigContent);
            $io->success('Las plantillas Twig del módulo de streaming han sido descomentadas.');
            return;
        }
        
        if (!preg_match('/paths:/', $twigContent)) {
            $io->error('No se pudo encontrar la sección "paths" en twig.yaml');
            return;
        }
        
        $patterns = [
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
                "$1\n        '%kernel.project_dir%/src/ModuloStreaming/templates': ModuloStreaming",
                $twigContent
            );
            
            if ($newContent !== $twigContent) {
                file_put_contents($twigYamlPath, $newContent);
                $io->success('twig.yaml actualizado con las plantillas del módulo de streaming.');
            } else {
                $io->error('No se pudo actualizar twig.yaml. Verifica el formato del archivo.');
            }
            return;
        }
        
        $streamingConfig = "\n        '%kernel.project_dir%/src/ModuloStreaming/templates': ModuloStreaming";
        $newContent = str_replace($insertPoint, $insertPoint . $streamingConfig, $twigContent);
        
        if ($newContent !== $twigContent) {
            file_put_contents($twigYamlPath, $newContent);
            $io->success('twig.yaml actualizado con las plantillas del módulo de streaming.');
        } else {
            $io->error('No se pudo actualizar twig.yaml. Verifica el formato del archivo.');
        }
    }

    private function setupAssetsSymlink(SymfonyStyle $io): void
    {
        try {
            $filesystem = new Filesystem();
            
            $assetsSourcePath = $this->projectDir . '/src/ModuloStreaming/Assets';
            if (!$filesystem->exists($assetsSourcePath)) {
                $io->error('La carpeta Assets no existe en el módulo Streaming. No se puede crear el enlace simbólico.');
                return;
            }
            
            $targetParentDir = $this->projectDir . '/public/css';
            if (!$filesystem->exists($targetParentDir)) {
                $filesystem->mkdir($targetParentDir);
                $io->text('Creado directorio: /public/css');
            }
            
            $symlinkPath = $targetParentDir . '/moduloStreaming';
            
            if ($filesystem->exists($symlinkPath)) {
                if (is_link($symlinkPath)) {
                    $filesystem->remove($symlinkPath);
                    $io->text('Enlace simbólico existente eliminado');
                } else {
                    $io->warning('La ruta /public/css/moduloStreaming existe pero no es un enlace simbólico. Eliminando...');
                    $filesystem->remove($symlinkPath);
                }
            }
            
            if (function_exists('symlink')) {
                $filesystem->symlink(
                    $assetsSourcePath,
                    $symlinkPath
                );
                $io->success('Enlace simbólico creado correctamente: /public/css/moduloStreaming -> /src/ModuloStreaming/Assets');
            } else {
                $io->warning('Tu sistema no soporta enlaces simbólicos. Copiando archivos en su lugar...');
                
                if (!$filesystem->exists($symlinkPath)) {
                    $filesystem->mkdir($symlinkPath);
                }
                
                $filesystem->mirror($assetsSourcePath, $symlinkPath);
                $io->text('Archivos copiados a /public/css/moduloStreaming');
                
                $filesystem->dumpFile(
                    $symlinkPath . '/README.txt',
                    "Esta carpeta contiene una copia de los assets de src/ModuloStreaming/Assets.\n" .
                    "Se recomienda actualizar ambas carpetas cuando se realizan cambios en los archivos."
                );
            }
        } catch (\Exception $e) {
            $io->error('Error al configurar el enlace simbólico para los assets: ' . $e->getMessage());
        }
    }

    private function ensureUploadsDirectory(SymfonyStyle $io): void
    {
        $uploadPath = $this->projectDir . '/public/uploads/videos';

        if (!is_dir($uploadPath)) {
            mkdir($uploadPath, 0775, true);
            $io->success("Directorio creado: $uploadPath");
        } else {
            $io->note("El directorio de videos ya existe: $uploadPath");
        }
    }

    
    private function updateDoctrineYaml(SymfonyStyle $io): void
    {
        $doctrineYamlPath = $this->projectDir . '/config/packages/doctrine.yaml';
        $doctrineContent = file_get_contents($doctrineYamlPath);
        
        // Verificar si la configuración de ModuloStreaming ya existe
        if (preg_match('/ModuloStreaming:.*?\n\s+type: attribute\n\s+is_bundle: false\n\s+dir:.*?\n\s+prefix:.*?\n\s+alias: ModuloStreaming/s', $doctrineContent)) {
            $io->note('La configuración de Doctrine ya incluye las entidades del módulo de streaming.');
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
        
        // Configuración del módulo de streaming
        $streamingConfig = "\n{$moduleIndentation}ModuloStreaming:
    {$moduleIndentation}    type: attribute
    {$moduleIndentation}    is_bundle: false
    {$moduleIndentation}    dir: '%kernel.project_dir%/src/ModuloStreaming/Entity'
    {$moduleIndentation}    prefix: 'App\\ModuloStreaming\\Entity'
    {$moduleIndentation}    alias: ModuloStreaming";
        
        // Insertar la configuración justo debajo de mappings:
        $newContent = preg_replace(
            '/(mappings:)/',
            "$1{$streamingConfig}",
            $doctrineContent,
            1
        );
        
        if ($newContent !== $doctrineContent) {
            file_put_contents($doctrineYamlPath, $newContent);
            $io->success('doctrine.yaml actualizado con las entidades del módulo de streaming debajo de mappings.');
        } else {
            $io->error('No se pudo actualizar doctrine.yaml. Verifica el formato del archivo.');
        }
    }
    
    private function registerModule(SymfonyStyle $io): ?Modulo
    {
        try {
            $moduloRepository = $this->entityManager->getRepository(Modulo::class);
            $streamingModule = $moduloRepository->findOneBy(['nombre' => 'Streaming']);
            
            if ($streamingModule) {
                $streamingModule->setEstado(true);
                $streamingModule->setInstallDate(new \DateTimeImmutable());
                $streamingModule->setUninstallDate(null);
                $io->note('El módulo Streaming ya existe en la base de datos. Se ha activado.');
            } else {
                $streamingModule = new Modulo();
                $streamingModule->setNombre('Streaming');
                $streamingModule->setDescripcion('Módulo para gestionar y reproducir series y películas');
                $streamingModule->setIcon('fas fa-film');
                $streamingModule->setRuta('/streaming');
                $streamingModule->setEstado(true);
                $streamingModule->setInstallDate(new \DateTimeImmutable());
                
                $this->entityManager->persist($streamingModule);
                $io->success('Se ha registrado el módulo Streaming en la base de datos.');
            }
            
            $this->entityManager->flush();
            return $streamingModule;
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
            
            $existingMenuItem = $menuRepository->findOneBy(['nombre' => 'Streaming']);
            if ($existingMenuItem) {
                $existingMenuItem->setEnabled(true);
                $io->note('El elemento de menú para Streaming ya existe. Se ha activado.');
            } else {
                $menuItem = new MenuElement();
                $menuItem->setNombre('Streaming');
                $menuItem->setIcon('fas fa-film');
                $menuItem->setType('menu');
                $menuItem->setParentId(0);
                $menuItem->setRuta('/streaming');
                $menuItem->setEnabled(true);
                $menuItem->addModulo($modulo);
                
                $this->entityManager->persist($menuItem);
                $this->entityManager->flush();
                $existingMenuItem = $menuItem;
                $io->success('Se ha creado el elemento de menú para el módulo Streaming.');
            }

            $parentMenuId = $existingMenuItem->getId();

            $seriesSubmenu = $menuRepository->findOneBy(['nombre' => 'Series', 'parentId' => $parentMenuId]);
            if ($seriesSubmenu) {
                $seriesSubmenu->setEnabled(true);
                $io->note('El submenú "Series" ya existe. Se ha activado.');
            } else {
                $seriesSubmenu = new MenuElement();
                $seriesSubmenu->setNombre('Series');
                $seriesSubmenu->setIcon('fas fa-tv');
                $seriesSubmenu->setType('menu');
                $seriesSubmenu->setParentId($parentMenuId);
                $seriesSubmenu->setRuta('/streaming/series');
                $seriesSubmenu->setEnabled(true);
                $seriesSubmenu->addModulo($modulo);
                
                $this->entityManager->persist($seriesSubmenu);
                $io->success('Se ha creado el submenú "Series" para el módulo Streaming.');
            }

            $peliculasSubmenu = $menuRepository->findOneBy(['nombre' => 'Películas', 'parentId' => $parentMenuId]);
            if ($peliculasSubmenu) {
                $peliculasSubmenu->setEnabled(true);
                $io->note('El submenú "Películas" ya existe. Se ha activado.');
            } else {
                $peliculasSubmenu = new MenuElement();
                $peliculasSubmenu->setNombre('Películas');
                $peliculasSubmenu->setIcon('fas fa-video');
                $peliculasSubmenu->setType('menu');
                $peliculasSubmenu->setParentId($parentMenuId);
                $peliculasSubmenu->setRuta('/streaming/peliculas');
                $peliculasSubmenu->setEnabled(true);
                $peliculasSubmenu->addModulo($modulo);
                
                $this->entityManager->persist($peliculasSubmenu);
                $io->success('Se ha creado el submenú "Películas" para el módulo Streaming.');
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
                $adminSubmenu->setRuta('/streaming/admin');
                $adminSubmenu->setEnabled(true);
                $adminSubmenu->addModulo($modulo);
                
                $this->entityManager->persist($adminSubmenu);
                $io->success('Se ha creado el submenú "Admin" para el módulo Streaming.');
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
            
            // Verificar si las tablas ya existen
            $tablaCategoria = $conn->executeQuery("SELECT name FROM sqlite_master WHERE type='table' AND name='categoria'")->fetchOne();
            $tablaVideo = $conn->executeQuery("SELECT name FROM sqlite_master WHERE type='table' AND name='video'")->fetchOne();
            
            if ($tablaCategoria && $tablaVideo) {
                $io->note('Las tablas ya existen en la base de datos.');
                return true;
            }
            
            // Sentencias SQL para crear las tablas
            $sqlStatements = [
                // Tabla para Categoria
                "CREATE TABLE IF NOT EXISTS categoria (
                    id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL,
                    nombre VARCHAR(255) NOT NULL,
                    descripcion VARCHAR(255) DEFAULT NULL,
                    icono VARCHAR(255) DEFAULT NULL,
                    creado_en DATETIME NOT NULL
                )",
                
                // Tabla para Video
                "CREATE TABLE IF NOT EXISTS video (
                    id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL,
                    categoria_id INTEGER DEFAULT NULL,
                    titulo VARCHAR(255) NOT NULL,
                    descripcion CLOB DEFAULT NULL,
                    imagen VARCHAR(255) DEFAULT NULL,
                    url VARCHAR(255) DEFAULT NULL,
                    es_publico BOOLEAN NOT NULL DEFAULT 1,
                    tipo VARCHAR(20) NOT NULL,
                    anio INTEGER DEFAULT NULL,
                    temporada INTEGER DEFAULT NULL,
                    episodio INTEGER DEFAULT NULL,
                    creado_en DATETIME NOT NULL,
                    actualizado_en DATETIME DEFAULT NULL,
                    FOREIGN KEY (categoria_id) REFERENCES categoria (id)
                )",
                
                // Índice para la clave foránea
                "CREATE INDEX IF NOT EXISTS IDX_VIDEO_CATEGORIA ON video (categoria_id)"
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
    
    private function createDefaultCategories(SymfonyStyle $io): void
    {
        try {
            $categoriesDefault = [
                ['nombre' => 'Acción', 'icono' => 'fas fa-running', 'descripcion' => 'Películas y series de acción'],
                ['nombre' => 'Comedia', 'icono' => 'fas fa-laugh', 'descripcion' => 'Películas y series de comedia'],
                ['nombre' => 'Drama', 'icono' => 'fas fa-theater-masks', 'descripcion' => 'Películas y series dramáticas'],
                ['nombre' => 'Ciencia Ficción', 'icono' => 'fas fa-rocket', 'descripcion' => 'Películas y series de ciencia ficción'],
                ['nombre' => 'Terror', 'icono' => 'fas fa-ghost', 'descripcion' => 'Películas y series de terror'],
                ['nombre' => 'Documental', 'icono' => 'fas fa-film', 'descripcion' => 'Documentales y series documentales'],
            ];
            
            $conn = $this->entityManager->getConnection();
            $insertedCount = 0;
            
            foreach ($categoriesDefault as $categoryData) {
                // Verificar si la categoría ya existe
                $existingCategory = $conn->executeQuery(
                    "SELECT id FROM categoria WHERE nombre = ?",
                    [$categoryData['nombre']]
                )->fetchOne();
                
                if (!$existingCategory) {
                    $conn->executeStatement(
                        'INSERT INTO categoria (nombre, descripcion, icono, creado_en) VALUES (?, ?, ?, ?)',
                        [
                            $categoryData['nombre'],
                            $categoryData['descripcion'],
                            $categoryData['icono'],
                            (new \DateTimeImmutable())->format('Y-m-d H:i:s')
                        ]
                    );
                    $insertedCount++;
                }
            }
            
            if ($insertedCount > 0) {
                $io->success("Se han creado $insertedCount categorías predeterminadas.");
            } else {
                $io->note('Las categorías predeterminadas ya existen.');
            }
        } catch (\Exception $e) {
            $io->error('Error al crear categorías predeterminadas: ' . $e->getMessage());
        }
    }
}