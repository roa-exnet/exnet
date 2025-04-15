<?php

namespace App\ModuloChat\Command;

use App\ModuloChat\Entity\Chat;
use App\ModuloCore\Entity\Modulo;
use App\ModuloCore\Entity\MenuElement;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Process\Process;
use Doctrine\ORM\EntityManagerInterface;

#[AsCommand(
    name: 'modulochat:setup',
    description: 'Configurar el módulo de chat: configura configuraciones y crea tablas directamente'
)]
class ChatSetupCommand extends Command
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
            ->addOption('skip-menu', null, InputOption::VALUE_NONE, 'Omitir la creación de elementos de menú')
            ->addOption('yes', 'y', InputOption::VALUE_NONE, 'Confirmar automáticamente todas las preguntas')
            ->setHelp(<<<EOT
                El comando <info>modulochat:setup</info> realiza los siguientes pasos automáticamente:

                1. Actualiza la configuración de servicios.yaml para incluir el módulo de chat.
                2. Actualiza la configuración de routes.yaml para incluir las rutas del módulo de chat.
                3. Actualiza la configuración de twig.yaml para incluir las plantillas del módulo de chat.
                4. Actualiza la configuración de doctrine.yaml para incluir las entidades del módulo de chat.
                5. Registra el módulo en la tabla de módulos de la aplicación.
                6. Crea elementos de menú para acceder al módulo de chat.
                7. Crea las tablas del módulo de chat directamente mediante SQL.

                Opciones:
                  --force, -f             Forzar la instalación incluso si el módulo ya está instalado
                  --skip-menu             Omitir la creación de elementos de menú
                  --yes, -y               Confirmar automáticamente todas las preguntas

                Ejemplo de uso:

                <info>php bin/console modulochat:setup</info>
                <info>php bin/console modulochat:setup --force</info>
                <info>php bin/console modulochat:setup --yes</info>
                EOT
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $io->title('Configuración del Módulo de Chat');
        
        // Comprobar si el módulo ya está instalado
        if (!$input->getOption('force') && $this->isModuleInstalled()) {
            $io->warning('El Módulo de Chat ya está instalado. Usa --force para reinstalarlo.');
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
        $this->registerModule($io);
        
        // 6. Crear elementos de menú
        if (!$input->getOption('skip-menu')) {
            $this->createMenuItems($io);
        }
        
        // Confirmación para crear tablas
        if (!$input->getOption('yes') && !$io->confirm('¿Deseas crear las tablas del módulo de chat ahora?', true)) {
            $io->note('Creación de tablas omitida. Puedes crearlas manualmente más tarde.');
            $io->success('Configuración de archivos completada.');
            return Command::SUCCESS;
        }
        
        // 7. Crear tablas directamente
        $this->createTables($io);
        
        $io->success('¡Módulo de Chat configurado exitosamente!');
        $io->note('Puedes acceder al chat en la ruta /chat');
        
        return Command::SUCCESS;
    }
    
    private function isModuleInstalled(): bool
    {
        try {
            $moduloRepository = $this->entityManager->getRepository(Modulo::class);
            $chatModule = $moduloRepository->findOneBy(['nombre' => 'Chat']);
            
            return $chatModule !== null && $chatModule->isEstado();
        } catch (\Exception $e) {
            return false;
        }
    }
    
    private function updateServicesYaml(SymfonyStyle $io): void
    {
        $servicesYamlPath = 'config/services.yaml';
        $servicesContent = file_get_contents($servicesYamlPath);
        
        // Verificar si la configuración ya existe
        if (strpos($servicesContent, 'App\ModuloChat\Controller') !== false && 
            strpos($servicesContent, '# DESACTIVADO') === false) {
            $io->note('La configuración de servicios ya incluye el módulo de chat.');
            return;
        }
        
        // Si está desactivado, reactivarlo
        if (strpos($servicesContent, 'App\ModuloChat\Controller') !== false && 
            strpos($servicesContent, '# DESACTIVADO') !== false) {
            $servicesContent = str_replace(
                "#START -----------------------------------------------------  ModuloChat (DESACTIVADO) --------------------------------------------------------------------------",
                "#START -----------------------------------------------------  ModuloChat --------------------------------------------------------------------------",
                $servicesContent
            );
            
            // Descomentar las líneas
            $pattern = "/#(\s+App\\\\ModuloChat\\\\)/";
            $servicesContent = preg_replace($pattern, "$1", $servicesContent);
            
            file_put_contents($servicesYamlPath, $servicesContent);
            $io->success('El módulo de chat ha sido reactivado en services.yaml.');
            return;
        }
        
        // Buscar dónde insertar la configuración
        $pattern = '#END\s+------+\s+ModuloCore\s+------+';
        
        if (!preg_match('/' . $pattern . '/', $servicesContent)) {
            $io->warning('No se pudo encontrar el punto de inserción en services.yaml.');
            if (!$io->confirm('¿Deseas añadir la configuración del chat al final del archivo?', false)) {
                $io->note('Operación cancelada.');
                return;
            }
            
            // Añadir al final del archivo si no se encuentra el punto de inserción
            $servicesContent .= "\n\n" . $this->getChatServicesConfig();
            file_put_contents($servicesYamlPath, $servicesContent);
            $io->success('La configuración del chat se ha añadido al final de services.yaml.');
            return;
        }
        
        // Si encontramos el punto de inserción, realizar reemplazo seguro
        $chatConfig = $this->getChatServicesConfig();
        
        // Realizar reemplazo con preg_replace para mayor seguridad
        $newContent = preg_replace('/' . $pattern . '/', "$0" . $chatConfig, $servicesContent, 1);
        
        // Verificar que el reemplazo fue exitoso
        if ($newContent !== $servicesContent) {
            file_put_contents($servicesYamlPath, $newContent);
            $io->success('services.yaml actualizado con la configuración del módulo de chat.');
        } else {
            $io->error('No se pudo actualizar services.yaml. Verifica el formato del archivo.');
        }
    }
    
    private function getChatServicesConfig(): string
    {
        return <<<EOT
        
    #START -----------------------------------------------------  ModuloChat -------------------------------------------------------------------------- 
    App\ModuloChat\Controller\:
        resource: '../src/ModuloChat/Controller'
        tags: ['controller.service_arguments']
        
    App\ModuloChat\Command\:
        resource: '../src/ModuloChat/Command'
        tags: ['console.command']
        
    App\ModuloChat\Service\:
        resource: '../src/ModuloChat/Service/'
        autowire: true
        autoconfigure: true
        public: true
    #END ------------------------------------------------------- ModuloChat -----------------------------------------------------------------------------
EOT;
    }
    
    private function updateRoutesYaml(SymfonyStyle $io): void
    {
        $routesYamlPath = 'config/routes.yaml';
        $routesContent = file_get_contents($routesYamlPath);
        
        // Verificar si la configuración ya existe y no está comentada
        if (strpos($routesContent, 'App\ModuloChat\Controller') !== false && 
            strpos($routesContent, '# modulo_chat_controllers') === false) {
            $io->note('La configuración de rutas ya incluye el módulo de chat.');
            return;
        }
        
        // Si está comentado, descomentarlo
        if (strpos($routesContent, '# modulo_chat_controllers') !== false) {
            $routesContent = str_replace(
                [
                    "# modulo_chat_controllers: DESACTIVADO", 
                    "# resource:", 
                    "#     path: ../src/ModuloChat/Controller/", 
                    "#     namespace: App\ModuloChat\Controller", 
                    "# type: attribute"
                ],
                [
                    "modulo_chat_controllers:", 
                    "    resource:", 
                    "        path: ../src/ModuloChat/Controller/", 
                    "        namespace: App\ModuloChat\Controller", 
                    "    type: attribute"
                ],
                $routesContent
            );
            file_put_contents($routesYamlPath, $routesContent);
            $io->success('Las rutas del módulo de chat han sido descomentadas.');
            return;
        }
        
        // Buscar un punto de inserción seguro
        $patterns = [
            'modulo_Explorador_controllers:',
            'modulo_core_controllers:',
            '# modulo_test_controllers:'
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
            if (!$io->confirm('¿Deseas añadir la configuración del chat al final del archivo?', false)) {
                $io->note('Operación cancelada.');
                return;
            }
            
            // Añadir al final del archivo si no se encuentra el punto de inserción
            $routesContent .= "\n\n" . $this->getChatRoutesConfig();
            file_put_contents($routesYamlPath, $routesContent);
            $io->success('La configuración del chat se ha añadido al final de routes.yaml.');
            return;
        }
        
        $chatConfig = $this->getChatRoutesConfig();
        
        // Asegurar que insertamos después del elemento completo
        $pattern = '/(' . preg_quote($insertPoint) . '.*?type:\s+attribute)/s';
        if (preg_match($pattern, $routesContent, $matches)) {
            $newContent = str_replace($matches[1], $matches[1] . $chatConfig, $routesContent);
            file_put_contents($routesYamlPath, $newContent);
            $io->success('routes.yaml actualizado con las rutas del módulo de chat.');
        } else {
            $io->error('No se pudo actualizar routes.yaml. Verifica el formato del archivo.');
        }
    }
    
    private function getChatRoutesConfig(): string
    {
        return <<<EOT


modulo_chat_controllers:
    resource:
        path: ../src/ModuloChat/Controller/
        namespace: App\ModuloChat\Controller
    type: attribute
EOT;
    }
    
    private function updateTwigYaml(SymfonyStyle $io): void
    {
        $twigYamlPath = 'config/packages/twig.yaml';
        $twigContent = file_get_contents($twigYamlPath);
        
        // Verificar si la configuración ya existe y no está comentada
        if (strpos($twigContent, "ModuloChat/templates': ModuloChat") !== false) {
            $io->note('La configuración de Twig ya incluye las plantillas del módulo de chat.');
            return;
        }
        
        // Si está comentada, descomentarla
        if (strpos($twigContent, "# '%kernel.project_dir%/src/ModuloChat/templates': ~ # DESACTIVADO") !== false) {
            $twigContent = str_replace(
                "# '%kernel.project_dir%/src/ModuloChat/templates': ~ # DESACTIVADO",
                "'%kernel.project_dir%/src/ModuloChat/templates': ModuloChat",
                $twigContent
            );
            file_put_contents($twigYamlPath, $twigContent);
            $io->success('Las plantillas Twig del módulo de chat han been descomentadas.');
            return;
        }
        
        // Buscar un punto de inserción seguro
        if (!preg_match('/paths:/', $twigContent)) {
            $io->error('No se pudo encontrar la sección "paths" en twig.yaml');
            return;
        }
        
        $patterns = [
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
                "$1\n        '%kernel.project_dir%/src/ModuloChat/templates': ModuloChat",
                $twigContent
            );
            
            if ($newContent !== $twigContent) {
                file_put_contents($twigYamlPath, $newContent);
                $io->success('twig.yaml actualizado con las plantillas del módulo de chat.');
            } else {
                $io->error('No se pudo actualizar twig.yaml. Verifica el formato del archivo.');
            }
            return;
        }
        
        // Insertar después del punto encontrado
        $chatConfig = "\n        '%kernel.project_dir%/src/ModuloChat/templates': ModuloChat";
        $newContent = str_replace($insertPoint, $insertPoint . $chatConfig, $twigContent);
        
        if ($newContent !== $twigContent) {
            file_put_contents($twigYamlPath, $newContent);
            $io->success('twig.yaml actualizado con las plantillas del módulo de chat.');
        } else {
            $io->error('No se pudo actualizar twig.yaml. Verifica el formato del archivo.');
        }
    }
    
    private function updateDoctrineYaml(SymfonyStyle $io): void
    {
        $doctrineYamlPath = 'config/packages/doctrine.yaml';
        $doctrineContent = file_get_contents($doctrineYamlPath);
        
        // Verificar si la configuración ya existe
        if (strpos($doctrineContent, 'ModuloChat:') !== false) {
            $io->note('La configuración de Doctrine ya incluye las entidades del módulo de chat.');
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
        
        // Crear la configuración del módulo Chat con la indentación correcta
        $chatConfig = "\n{$moduleIndentation}ModuloChat:
{$moduleIndentation}    type: attribute
{$moduleIndentation}    is_bundle: false
{$moduleIndentation}    dir: '%kernel.project_dir%/src/ModuloChat/Entity'
{$moduleIndentation}    prefix: 'App\\ModuloChat\\Entity'
{$moduleIndentation}    alias: ModuloChat";
        
        // Encontrar dónde insertar el nuevo módulo
        $lastModulePattern = '/(ModuloExplorador:.*?alias: ModuloExplorador)/s';
        
        if (preg_match($lastModulePattern, $doctrineContent, $lastModuleMatch)) {
            // Insertar después del último módulo
            $newContent = str_replace($lastModuleMatch[1], $lastModuleMatch[1] . $chatConfig, $doctrineContent);
            file_put_contents($doctrineYamlPath, $newContent);
            $io->success('doctrine.yaml actualizado con las entidades del módulo de chat.');
        } else {
            // Si no encontramos un patrón específico, intentar agregar al final de la sección mappings
            $mappingsSection = "mappings:";
            $newMappingsSection = "mappings:" . $chatConfig;
            
            if (strpos($doctrineContent, $mappingsSection) !== false) {
                $newContent = str_replace($mappingsSection, $newMappingsSection, $doctrineContent);
                file_put_contents($doctrineYamlPath, $newContent);
                $io->success('doctrine.yaml actualizado con las entidades del módulo de chat al final de la sección mappings.');
            } else {
                $io->error('No se pudo actualizar doctrine.yaml. No se encontró un punto de inserción adecuado.');
            }
        }
    }
    
    private function registerModule(SymfonyStyle $io): void
    {
        try {
            $moduloRepository = $this->entityManager->getRepository(Modulo::class);
            $chatModule = $moduloRepository->findOneBy(['nombre' => 'Chat']);
            
            if ($chatModule) {
                // Si el módulo ya existe, lo activamos
                $chatModule->setEstado(true);
                $io->note('El módulo Chat ya existe en la base de datos. Se ha activado.');
            } else {
                // Si no existe, lo creamos
                $modulo = new Modulo();
                $modulo->setNombre('Chat');
                $modulo->setDescripcion('Módulo de chat en tiempo real para la comunicación entre usuarios');
                $modulo->setIcon('fas fa-comments');
                $modulo->setRuta('/chat');
                $modulo->setEstado(true);
                $modulo->setInstallDate(new \DateTimeImmutable());
                
                $this->entityManager->persist($modulo);
                $io->success('Se ha registrado el módulo Chat en la base de datos.');
            }
            
            $this->entityManager->flush();
        } catch (\Exception $e) {
            $io->error('Error al registrar el módulo en la base de datos: ' . $e->getMessage());
            $io->note('Puedes continuar con la instalación y añadir el módulo manualmente más tarde.');
        }
    }
    
    private function createMenuItems(SymfonyStyle $io): void
    {
        try {
            $moduleRepository = $this->entityManager->getRepository(Modulo::class);
            $menuRepository = $this->entityManager->getRepository(MenuElement::class);
            
            $chatModule = $moduleRepository->findOneBy(['nombre' => 'Chat']);
            if (!$chatModule) {
                $io->warning('No se encuentra el módulo Chat en la base de datos. No se pueden crear elementos de menú.');
                return;
            }
            
            $existingMenuItem = $menuRepository->findOneBy(['nombre' => 'Chat']);
            if ($existingMenuItem) {
                $existingMenuItem->setEnabled(true);
                $io->note('El elemento de menú para Chat ya existe. Se ha activado.');
            } else {
                $menuItem = new MenuElement();
                $menuItem->setNombre('Chat');
                $menuItem->setIcon('fas fa-comments');
                $menuItem->setType('menu');
                $menuItem->setParentId(0); // Menú principal
                $menuItem->setRuta('/chat');
                $menuItem->setEnabled(true);
                $menuItem->addModulo($chatModule);
                
                $this->entityManager->persist($menuItem);
                $io->success('Se ha creado el elemento de menú para el módulo Chat.');
            }
            
            $this->entityManager->flush();
        } catch (\Exception $e) {
            $io->error('Error al crear elementos de menú: ' . $e->getMessage());
            $io->note('Puedes crear los elementos de menú manualmente más tarde.');
        }
    }
    
    private function createTables(SymfonyStyle $io): void
    {
        $io->section('Creando tablas del módulo de chat...');
        
        try {
            $connection = $this->entityManager->getConnection();
            
            // Clear cache before creating tables
            $io->section('Limpiando la caché antes de crear tablas...');
            $cacheClearProcess = new Process(['php', 'bin/console', 'cache:clear']);
            $cacheClearProcess->setTimeout(120); // 2 minutos de timeout
            $cacheClearProcess->run(function ($type, $buffer) use ($io) {
                $io->write($buffer);
            });

            if (!$cacheClearProcess->isSuccessful()) {
                $io->warning('No se pudo limpiar la caché. Continuando con la creación de tablas...');
            } else {
                $io->success('Caché limpiada exitosamente.');
            }

            // SQL to create the chat table
            $chatTableSql = <<<SQL
            CREATE TABLE IF NOT EXISTS chat (
                id VARCHAR(255) NOT NULL,
                name VARCHAR(255) NOT NULL,
                created_at DATETIME NOT NULL,
                closed_at DATETIME DEFAULT NULL,
                type VARCHAR(20) NOT NULL DEFAULT 'private',
                is_active BOOLEAN NOT NULL DEFAULT 1,
                PRIMARY KEY (id)
            );
SQL;

            // SQL to create the chat_message table
            $chatMessageTableSql = <<<SQL
            CREATE TABLE IF NOT EXISTS chat_message (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                chat_id VARCHAR(255) NOT NULL,
                sender_identifier VARCHAR(255) NOT NULL,
                sender_name VARCHAR(255) DEFAULT NULL,
                content TEXT NOT NULL,
                sent_at DATETIME NOT NULL,
                read_at DATETIME DEFAULT NULL,
                message_type VARCHAR(50) NOT NULL DEFAULT 'text',
                metadata TEXT DEFAULT NULL
            );
SQL;

            // SQL to create the chat_participant table
            $chatParticipantTableSql = <<<SQL
            CREATE TABLE IF NOT EXISTS chat_participant (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                chat_id VARCHAR(255) NOT NULL,
                participant_identifier VARCHAR(255) NOT NULL,
                participant_name VARCHAR(255) DEFAULT NULL,
                joined_at DATETIME NOT NULL,
                left_at DATETIME DEFAULT NULL,
                is_active BOOLEAN NOT NULL DEFAULT 1,
                role VARCHAR(50) NOT NULL DEFAULT 'member'
            );
SQL;

            // Execute SQL statements
            $connection->executeStatement($chatTableSql);
            $io->success('Tabla chat creada exitosamente.');
            
            $connection->executeStatement($chatMessageTableSql);
            $io->success('Tabla chat_message creada exitosamente.');
            
            $connection->executeStatement($chatParticipantTableSql);
            $io->success('Tabla chat_participant creada exitosamente.');
            
        } catch (\Exception $e) {
            $io->error('Error al crear las tablas: ' . $e->getMessage());
            throw $e;
        }
    }
}