<?php

namespace App\ModuloMusica\Command;

use App\ModuloCore\Entity\MenuElement;
use App\ModuloCore\Entity\Modulo;
use App\ModuloMusica\Entity\Genero;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Process\Process;

#[AsCommand(
    name: 'musica:install',
    description: 'Instala el módulo de música'
)]
class MusicaInstallCommand extends Command
{
    private EntityManagerInterface $entityManager;

    public function __construct(EntityManagerInterface $entityManager)
    {
        $this->entityManager = $entityManager;
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('force', 'f', InputOption::VALUE_NONE, 'Forzar la instalación incluso si el módulo ya está instalado')
            ->addOption('skip-migrations', null, InputOption::VALUE_NONE, 'Omitir la generación y ejecución de migraciones')
            ->addOption('skip-menu', null, InputOption::VALUE_NONE, 'Omitir la creación de elementos de menú')
            ->addOption('yes', 'y', InputOption::VALUE_NONE, 'Confirmar automáticamente todas las preguntas');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $io->title('Instalación del Módulo de Música');

        try {
            // Comprobar si el módulo ya está instalado
            if (!$input->getOption('force') && $this->isModuleInstalled()) {
                $io->warning('El módulo de Música ya está instalado. Usa --force para reinstalarlo.');
                return Command::SUCCESS;
            }

            // 1. Actualizar services.yaml
            $this->updateServicesYaml($io);
            
            // 2. Actualizar routes.yaml
            $this->updateRoutesYaml($io);
            
            // 3. Actualizar twig.yaml
            $this->updateTwigYaml($io);
            
            // 4. Actualizar doctrine.yaml
            $this->updateDoctrineYaml($io);

            // 5. Registrar el módulo en la base de datos
            $modulo = $this->registerModule($io);
            
            // 6. Crear elementos de menú
            if (!$input->getOption('skip-menu')) {
                $this->createMenuItems($io, $modulo);
            }

            // 7. Crear géneros musicales predeterminados
            $this->createDefaultGenres($io);

            // Omitir migraciones si se solicita
            if ($input->getOption('skip-migrations')) {
                $io->note('Las migraciones han sido omitidas según los parámetros de entrada.');
                $io->success('Configuración del Módulo de Música completada exitosamente (sin migraciones).');
                return Command::SUCCESS;
            }
            
            // Confirmación para generar migraciones
            if (!$input->getOption('yes') && !$io->confirm('¿Deseas generar y ejecutar la migración de la base de datos ahora?', true)) {
                $io->note('Operaciones de base de datos omitidas. Puedes ejecutarlas manualmente más tarde.');
                $io->success('Configuración de archivos completada.');
                return Command::SUCCESS;
            }
            
            // 8. Generar migración
            $migrationSuccess = $this->generateMigration($io);
            if (!$migrationSuccess) {
                return Command::FAILURE;
            }
            
            // 9. Ejecutar migración
            $executionSuccess = $this->executeMigration($input, $io);
            if (!$executionSuccess) {
                return Command::FAILURE;
            }

            // 10. Limpiar caché
            $io->section('Limpiando caché');
            $process = new Process(['php', 'bin/console', 'cache:clear']);
            $process->run();
            
            if (!$process->isSuccessful()) {
                $io->warning('Error al limpiar la caché: ' . $process->getErrorOutput());
            } else {
                $io->success('Caché limpiada correctamente.');
            }
            
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
    
    private function updateServicesYaml(SymfonyStyle $io): void
    {
        $servicesYamlPath = 'config/services.yaml';
        $servicesContent = file_get_contents($servicesYamlPath);
        
        // Verificar si la configuración ya existe
        if (strpos($servicesContent, 'App\ModuloMusica\Controller') !== false && 
            strpos($servicesContent, '# DESACTIVADO') === false) {
            $io->note('La configuración de servicios ya incluye el módulo de música.');
            return;
        }
        
        // Si está desactivado, reactivarlo
        if (strpos($servicesContent, 'App\ModuloMusica\Controller') !== false && 
            strpos($servicesContent, '# DESACTIVADO') !== false) {
            $servicesContent = str_replace(
                "#START -----------------------------------------------------  ModuloMusica (DESACTIVADO) --------------------------------------------------------------------------",
                "#START -----------------------------------------------------  ModuloMusica --------------------------------------------------------------------------",
                $servicesContent
            );
            
            // Descomentar las líneas
            $pattern = "/#(\s+App\\\\ModuloMusica\\\\)/";
            $servicesContent = preg_replace($pattern, "$1", $servicesContent);
            
            file_put_contents($servicesYamlPath, $servicesContent);
            $io->success('El módulo de música ha sido reactivado en services.yaml.');
            return;
        }
        
        // Buscar dónde insertar la configuración
        $pattern = '#END\s+------+\s+ModuloCore\s+------+';
        
        if (!preg_match('/' . $pattern . '/', $servicesContent)) {
            $io->warning('No se pudo encontrar el punto de inserción en services.yaml.');
            if (!$io->confirm('¿Deseas añadir la configuración del módulo de música al final del archivo?', false)) {
                $io->note('Operación cancelada.');
                return;
            }
            
            // Añadir al final del archivo si no se encuentra el punto de inserción
            $servicesContent .= "\n\n" . $this->getMusicaServicesConfig();
            file_put_contents($servicesYamlPath, $servicesContent);
            $io->success('La configuración del módulo de música se ha añadido al final de services.yaml.');
            return;
        }
        
        // Si encontramos el punto de inserción, realizar reemplazo seguro
        $musicaConfig = $this->getMusicaServicesConfig();
        
        // Realizar reemplazo con preg_replace para mayor seguridad
        $newContent = preg_replace('/' . $pattern . '/', "$0" . $musicaConfig, $servicesContent, 1);
        
        // Verificar que el reemplazo fue exitoso
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
        
        // Verificar si la configuración ya existe y no está comentada
        if (strpos($routesContent, 'App\ModuloMusica\Controller') !== false && 
            strpos($routesContent, '# modulo_musica_controllers') === false) {
            $io->note('La configuración de rutas ya incluye el módulo de música.');
            return;
        }
        
        // Si está comentado, descomentarlo
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
        
        // Buscar un punto de inserción seguro
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
            
            // Añadir al final del archivo si no se encuentra el punto de inserción
            $routesContent .= "\n\n" . $this->getMusicaRoutesConfig();
            file_put_contents($routesYamlPath, $routesContent);
            $io->success('La configuración del módulo de música se ha añadido al final de routes.yaml.');
            return;
        }
        
        $musicaConfig = $this->getMusicaRoutesConfig();
        
        // Asegurar que insertamos después del elemento completo
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
        
        // Verificar si la configuración ya existe y no está comentada
        if (strpos($twigContent, "ModuloMusica/templates': ModuloMusica") !== false) {
            $io->note('La configuración de Twig ya incluye las plantillas del módulo de música.');
            return;
        }
        
        // Si está comentada, descomentarla
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
        
        // Buscar un punto de inserción seguro
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
            
            // Insertar justo después de 'paths:'
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
        
        // Insertar después del punto encontrado
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
        
        // Verificar si la configuración ya existe
        if (strpos($doctrineContent, 'ModuloMusica:') !== false) {
            $io->note('La configuración de Doctrine ya incluye las entidades del módulo de música.');
            return;
        }
        
        // Extraer la configuración actual para entender la estructura
        $pattern = '/mappings:\s*\n(.*?)(?:\n\s*\w+:|$)/s';
        
        if (!preg_match($pattern, $doctrineContent, $mappingsMatch)) {
            $io->error('No se pudo encontrar la sección "mappings" en doctrine.yaml');
            return;
        }
        
        // Determinar la indentación correcta basada en la estructura existente
        $mappingsIndentation = '';
        $moduleIndentation = '';
        
        if (preg_match('/(\s+)ModuloCore:/m', $doctrineContent, $indentMatch)) {
            $moduleIndentation = $indentMatch[1];
            // La indentación de 'mappings:' debe ser un nivel menos
            $mappingsIndentation = substr($moduleIndentation, 0, -4);
        } else {
            // Valores por defecto si no podemos determinar la indentación
            $moduleIndentation = '            '; // 12 espacios
            $mappingsIndentation = '        '; // 8 espacios
        }
        
        // Crear la configuración del módulo Música con la indentación correcta
        $musicaConfig = "\n{$moduleIndentation}ModuloMusica:
{$moduleIndentation}    type: attribute
{$moduleIndentation}    is_bundle: false
{$moduleIndentation}    dir: '%kernel.project_dir%/src/ModuloMusica/Entity'
{$moduleIndentation}    prefix: 'App\\ModuloMusica\\Entity'
{$moduleIndentation}    alias: ModuloMusica";
        
        // Encontrar dónde insertar el nuevo módulo
        $lastModules = [
            'ModuloChat:.*?alias: ModuloChat',
            'ModuloExplorador:.*?alias: ModuloExplorador',
            'ModuloCore:.*?alias: ModuloCore'
        ];
        
        $foundInsertPoint = false;
        foreach ($lastModules as $lastModulePattern) {
            if (preg_match('/(' . $lastModulePattern . ')/s', $doctrineContent, $lastModuleMatch)) {
                // Insertar después del último módulo
                $newContent = str_replace($lastModuleMatch[1], $lastModuleMatch[1] . $musicaConfig, $doctrineContent);
                file_put_contents($doctrineYamlPath, $newContent);
                $io->success('doctrine.yaml actualizado con las entidades del módulo de música.');
                $foundInsertPoint = true;
                break;
            }
        }
        
        if (!$foundInsertPoint) {
            // Si no encontramos un patrón específico, intentar agregar al final de la sección mappings
            $mappingsSection = "mappings:";
            $newMappingsSection = "mappings:" . $musicaConfig;
            
            if (strpos($doctrineContent, $mappingsSection) !== false) {
                $newContent = str_replace($mappingsSection, $newMappingsSection, $doctrineContent);
                file_put_contents($doctrineYamlPath, $newContent);
                $io->success('doctrine.yaml actualizado con las entidades del módulo de música al final de la sección mappings.');
            } else {
                $io->error('No se pudo actualizar doctrine.yaml. No se encontró un punto de inserción adecuado.');
            }
        }
    }
    
    private function registerModule(SymfonyStyle $io): ?Modulo
    {
        try {
            $moduloRepository = $this->entityManager->getRepository(Modulo::class);
            $musicaModule = $moduloRepository->findOneBy(['nombre' => 'Música']);
            
            if ($musicaModule) {
                // Si el módulo ya existe, lo activamos
                $musicaModule->setEstado(true);
                $io->note('El módulo Música ya existe en la base de datos. Se ha activado.');
            } else {
                // Si no existe, lo creamos
                $musicaModule = new Modulo();
                $musicaModule->setNombre('Música');
                $musicaModule->setDescripcion('Módulo para gestionar y reproducir música');
                $musicaModule->setIcon('fas fa-music');
                $musicaModule->setRuta('/musica');
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
                $menuItem->setParentId(0); // Menú principal
                $menuItem->setRuta('/musica');
                $menuItem->setEnabled(true);
                $menuItem->addModulo($modulo);
                
                $this->entityManager->persist($menuItem);
                $io->success('Se ha creado el elemento de menú para el módulo Música.');
            }
            
            $this->entityManager->flush();
        } catch (\Exception $e) {
            $io->error('Error al crear elementos de menú: ' . $e->getMessage());
            $io->note('Puedes crear los elementos de menú manualmente más tarde.');
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
    
    private function generateMigration(SymfonyStyle $io): bool
    {
        $io->section('Generando migración...');
        $process = new Process(['php', 'bin/console', 'make:migration']);
        $process->setTimeout(120); // 2 minutos de timeout
        $process->run(function ($type, $buffer) use ($io) {
            $io->write($buffer);
        });
        
        if (!$process->isSuccessful()) {
            $io->error('Error al generar la migración. Puedes intentarlo manualmente más tarde.');
            return false;
        }
        
        $io->success('Migración generada para las entidades del módulo de música.');
        return true;
    }
    
    private function executeMigration(InputInterface $input, SymfonyStyle $io): bool
    {
        if (!$input->getOption('yes') && !$io->confirm('¿Deseas ejecutar la migración ahora?', true)) {
            $io->note('Migración no ejecutada. Puedes ejecutarla manualmente más tarde.');
            return true;
        }
        
        $io->section('Ejecutando migración...');
        $process = new Process(['php', 'bin/console', 'doctrine:migrations:migrate', '--no-interaction']);
        $process->setTimeout(300); // 5 minutos de timeout
        $process->run(function ($type, $buffer) use ($io) {
            $io->write($buffer);
        });
        
        if (!$process->isSuccessful()) {
            $io->error('Error al ejecutar la migración. Puedes intentarlo manualmente más tarde.');
            return false;
        }
        
        $io->success('Migración ejecutada. Las tablas del módulo de música han sido creadas en la base de datos.');
        return true;
    }
}