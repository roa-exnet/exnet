<?php

namespace App\ModuloCore\Command;

use App\ModuloCore\Entity\User;
use App\ModuloCore\Entity\Modulo;
use App\ModuloCore\Entity\MenuElement;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Question\Question;
use Symfony\Component\Console\Question\ChoiceQuestion;
use Symfony\Component\Console\Question\ConfirmationQuestion;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Process\Process;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\DependencyInjection\ParameterBag\ParameterBagInterface;
use Symfony\Component\Dotenv\Dotenv;
use App\ModuloCore\Service\KeycloakRealmService;
use Symfony\Contracts\HttpClient\HttpClientInterface;

#[AsCommand(
    name: 'exnet:setup',
    description: 'Instalación asistida de Exnet: configura la base de datos, crea usuario admin, y configura módulos básicos.'
)]
class SetupCommand extends Command
{
    private EntityManagerInterface $entityManager;
    private UserPasswordHasherInterface $passwordHasher;
    private HttpClientInterface $httpClient;
    private ParameterBagInterface $parameterBag;
    private string $projectDir;
    private Filesystem $filesystem;

    public function __construct(
        EntityManagerInterface $entityManager,
        UserPasswordHasherInterface $passwordHasher,
        ParameterBagInterface $parameterBag,
        HttpClientInterface $httpClient
    ) {
        $this->entityManager = $entityManager;
        $this->passwordHasher = $passwordHasher;
        $this->httpClient = $httpClient;
        $this->parameterBag = $parameterBag;
        $this->projectDir = $parameterBag->get('kernel.project_dir');
        $this->filesystem = new Filesystem();
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->setHelp(<<<EOT
                El comando <info>exnet:setup</info> le guía a través del proceso de instalación de Exnet:

                1. Verifica los requisitos del sistema
                2. Configura el archivo .env y la base de datos
                3. Crea un usuario administrador
                4. Instala y configura los módulos esenciales
                5. Configura la aplicación

                Este comando puede ser interactivo o usar opciones para configuración automática.
                EOT
            )
            ->addOption('force', 'f', InputOption::VALUE_NONE, 'Forzar la ejecución incluso si el sistema ya está configurado')
            ->addOption('app-env', null, InputOption::VALUE_REQUIRED, 'Entorno de ejecución (dev, prod, test)', 'dev')
            ->addOption('cors-url', null, InputOption::VALUE_REQUIRED, 'URL para CORS (* para todos)', '*')
            ->addOption('ws-port', null, InputOption::VALUE_REQUIRED, 'Puerto para el servidor WebSocket', '3088')
            ->addOption('admin-user', null, InputOption::VALUE_REQUIRED, 'Email del administrador', 'admin@example.com')
            ->addOption('admin-name', null, InputOption::VALUE_REQUIRED, 'Nombre del administrador', 'Admin')
            ->addOption('admin-lastname', null, InputOption::VALUE_REQUIRED, 'Apellidos del administrador', 'Usuario')
            ->addOption('admin-password', null, InputOption::VALUE_REQUIRED, 'Contraseña del administrador', null)
            ->addOption('admin-ip', null, InputOption::VALUE_REQUIRED, 'IP para autologueo del administrador', '127.0.0.1')
            ->addOption('install-modules', null, InputOption::VALUE_REQUIRED, 'Instalar módulos adicionales (yes/no)', 'yes')
            ->addOption('start-server', null, InputOption::VALUE_REQUIRED, 'Iniciar servidor web (yes/no)', 'yes')
            ->addOption('server-port', null, InputOption::VALUE_REQUIRED, 'Puerto para el servidor web', '8080');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $io->title('Instalación asistida de Exnet');

        // Verificar si el sistema ya está configurado
        $isConfigured = $this->isAlreadyConfigured();
        if ($isConfigured && !$input->getOption('force')) {
            $io->warning('El sistema ya parece estar configurado. Use --force para reinstalar.');
            return Command::SUCCESS;
        }

        // Paso 1: Verificar requisitos
        if (!$this->checkRequirements($io)) {
            $io->error('No se cumplen los requisitos mínimos. Por favor, corrija los problemas antes de continuar.');
            return Command::FAILURE;
        }

        // Paso 2: Configurar entorno
        if (!$this->configureEnvironment($io, $input, $output)) {
            $io->error('Error configurando el entorno.');
            return Command::FAILURE;
        }

        // Paso 3: Configurar base de datos
        if (!$this->setupDatabase($io)) {
            $io->error('Error configurando la base de datos.');
            return Command::FAILURE;
        }

        // Paso 4: Crear usuario administrador
        if (!$this->createAdminUser($io, $input, $output)) {
            $io->error('Error creando el usuario administrador.');
            return Command::FAILURE;
        }

        // Paso 5: Configurar módulos esenciales
        if (!$this->setupEssentialModules($io, $input)) {
            $io->error('Error configurando los módulos esenciales.');
            return Command::FAILURE;
        }

        // Paso 6: Configuraciones finales
        if (!$this->finalSetup($io, $input)) {
            $io->error('Error en la configuración final.');
            return Command::FAILURE;
        }

        // Paso 7: Levantar reino keycloak, escribir .env y clave pública en key.txt
        $randomId = substr(str_replace(['+', '/', '='], '', base64_encode(random_bytes(8))), 0, 10);
        $realmName = 'realm-' . $randomId;
        $keycloakService = new KeycloakRealmService($this->httpClient);

        $io->section('Paso 7: Instalando realm en Keycloak...');
        $result = $keycloakService->instalarRealm($realmName);

        if (!$result['success']) {
            $io->error('Error al instalar el realm: ' . $result['error']);
            return Command::FAILURE;
        }

        $io->success('Realm instalado correctamente: ' . $result['realm']);

        if (!$result['success']) {
            $output->writeln('<error>Error al instalar el realm: ' . $result['error'] . '</error>');
            return Command::FAILURE;
        }
        
        $output->writeln('<info>Realm instalado correctamente: ' . $result['realm'] . '</info>');

        $io->success([
            'Instalación completada con éxito.',
            'Puede acceder al sistema en: http://localhost:' . ($input->getOption('server-port') ?: '8080'),
            'Usuario admin creado. Use las credenciales que configuró durante la instalación.'
        ]);

        return Command::SUCCESS;
    }

    private function isAlreadyConfigured(): bool
    {
        try {
            $userCount = $this->entityManager->getRepository(User::class)->count([]);
            return $userCount > 0;
        } catch (\Exception $e) {
            return false;
        }
    }

    private function checkRequirements(SymfonyStyle $io): bool
    {
        $io->section('Verificando requisitos del sistema');
        
        $requirements = [
            'PHP versión >= 8.2' => version_compare(PHP_VERSION, '8.2.0', '>='),
            'Extensión PDO SQLite' => extension_loaded('pdo_sqlite'),
            'Extensión JSON' => extension_loaded('json'),
            'Extensión Ctype' => extension_loaded('ctype'),
            'Extensión Tokenizer' => extension_loaded('tokenizer'),
            'Extensión XML' => extension_loaded('xml'),
            'Extensión Mbstring' => extension_loaded('mbstring'),
            'Permisos de escritura en /var' => is_writable($this->projectDir . '/var')
        ];
        
        $pass = true;
        $rows = [];
        
        foreach ($requirements as $requirement => $satisfied) {
            $rows[] = [$requirement, $satisfied ? '<info>✓</info>' : '<error>✗</error>'];
            if (!$satisfied) {
                $pass = false;
            }
        }
        
        $io->table(['Requisito', 'Estado'], $rows);
        
        if (!$pass) {
            $io->error('No se cumplen todos los requisitos. Por favor, instale las extensiones faltantes o configure los permisos adecuados.');
        } else {
            $io->success('Todos los requisitos se cumplen correctamente.');
        }
        
        return $pass;
    }

    private function configureEnvironment(SymfonyStyle $io, InputInterface $input, OutputInterface $output): bool
    {
        $io->section('Configuración del entorno');
        
        // Verificar si tenemos un archivo .env
        $envFile = $this->projectDir . '/.env';
        $envLocalFile = $this->projectDir . '/.env.local';
        
        if (!file_exists($envFile)) {
            $io->error('No se encontró el archivo .env base. Por favor, asegúrese de que el proyecto está correctamente instalado.');
            return false;
        }
        
        // Generar Secret si no existe
        $dotenv = new Dotenv();
        $dotenv->load($envFile);
        
        $appSecret = $_ENV['APP_SECRET'] ?? '';
        if (empty($appSecret)) {
            $newSecret = bin2hex(random_bytes(16));
            $io->note('Generando APP_SECRET automáticamente');
            $this->updateEnvVariable('APP_SECRET', $newSecret, $envLocalFile);
        }
        
        // Configurar entorno
        $envOptions = ['dev', 'prod', 'test'];
        $environment = $input->getOption('app-env');
        if (!in_array($environment, $envOptions, true)) {
            $question = new ChoiceQuestion(
                'Seleccione el entorno de ejecución:',
                $envOptions,
                0 // dev es el valor por defecto
            );
            $environment = $io->askQuestion($question);
        }
        $this->updateEnvVariable('APP_ENV', $environment, $envLocalFile);
        $io->note("Entorno configurado: $environment");
        
        // Configurar URL CORS
        $corsUrl = $input->getOption('cors-url');
        if ($corsUrl === null) {
            $corsUrl = $io->ask('URL para CORS (separados por coma si hay varios, * para permitir todos)', '*');
        }
        $this->updateEnvVariable('CORS_ALLOW_ORIGIN', $corsUrl, $envLocalFile);
        $io->note("URL CORS configurada: $corsUrl");
        
        // Configurar puerto para WebSocket
        $wsPort = $input->getOption('ws-port');
        if ($wsPort === null) {
            $wsPort = $io->ask('Puerto para el servidor WebSocket', '3088');
        }
        $this->updateEnvVariable('WS_SERVER_URL', 'ws://localhost:' . $wsPort, $envLocalFile);
        $io->note("Puerto WebSocket configurado: $wsPort");
        
        $io->success('Configuración del entorno completada.');
        return true;
    }

    private function updateEnvVariable(string $name, string $value, string $envFile): void
    {
        $escaped = str_replace('"', '\"', $value);
        
        if (file_exists($envFile)) {
            $content = file_get_contents($envFile);
            
            // Si la variable ya existe, actualizar su valor
            if (preg_match("/^{$name}=/m", $content)) {
                $content = preg_replace(
                    "/^{$name}=.*/m",
                    "{$name}=\"{$escaped}\"",
                    $content
                );
            } else {
                // De lo contrario, añadir la variable al final
                $content .= PHP_EOL . "{$name}=\"{$escaped}\"";
            }
            
            file_put_contents($envFile, $content);
        } else {
            // Si el archivo no existe, crearlo con la variable
            file_put_contents($envFile, "{$name}=\"{$escaped}\"" . PHP_EOL);
        }
    }

    private function setupDatabase(SymfonyStyle $io): bool
    {
        $io->section('Configuración de la base de datos');
        
        // Crear directorio para la base de datos SQLite
        $dbPath = $this->projectDir . '/var/data';
        if (!is_dir($dbPath)) {
            $io->note('Creando directorio para la base de datos SQLite...');
            $this->filesystem->mkdir($dbPath, 0777);
        }
        
        // Para SQLite no necesitamos crear la base de datos, solo asegurarnos que el directorio existe
        $dbFile = $dbPath . '/database.sqlite';
        $io->note('Verificando la base de datos SQLite...');
        
        // Si hay una base de datos existente pero está fallando, podemos hacer backup y crear una nueva
        if (file_exists($dbFile)) {
            $io->note('Se encontró una base de datos existente.');
            
            // Verificar si podemos conectar a la base de datos
            try {
                $connection = $this->entityManager->getConnection();
                // Usar método isConnected para evitar la advertencia de deprecación
                if (!$connection->isConnected()) {
                    $connection->executeQuery('SELECT 1');
                }
                $io->note('Conexión a la base de datos existente establecida.');
            } catch (\Exception $e) {
                $io->warning('Error al conectar con la base de datos existente: ' . $e->getMessage());
                
                // Hacer backup de la base de datos problemática
                $backupFile = $dbFile . '.backup.' . date('YmdHis');
                $io->note('Haciendo backup de la base de datos actual en: ' . $backupFile);
                
                if (copy($dbFile, $backupFile)) {
                    $io->note('Backup realizado correctamente.');
                    // Eliminar la base de datos problemática
                    unlink($dbFile);
                    $io->note('Creando nueva base de datos...');
                    touch($dbFile);
                    chmod($dbFile, 0666);
                } else {
                    $io->error('No se pudo hacer backup de la base de datos existente.');
                    return false;
                }
            }
        } else {
            // La base de datos no existe, crearla
            $io->note('Creando archivo de base de datos SQLite...');
            touch($dbFile);
            chmod($dbFile, 0666); // Asegurar permisos adecuados
        }
        
        // Ahora vamos a crear un esquema limpio usando doctrine:schema:create en lugar de migraciones
        $io->note('Creando esquema de base de datos...');
        $process = new Process(['php', 'bin/console', 'doctrine:schema:create', '--no-interaction']);
        $process->setWorkingDirectory($this->projectDir);
        $process->run();
        
        if (!$process->isSuccessful()) {
            // Si falla, intentamos con otro enfoque
            $io->warning('No se pudo crear el esquema directamente. Intentando con migraciones...');
            
            // Intentamos borrar directamente las tablas de migración si existen
            try {
                $connection = $this->entityManager->getConnection();
                $connection->executeQuery('DROP TABLE IF EXISTS doctrine_migration_versions');
                $io->note('Eliminadas tablas de migración previas.');
            } catch (\Exception $e) {
                $io->note('No se encontraron tablas de migración previas para eliminar.');
            }
            
            // Generamos una migración limpia
            $io->note('Generando migraciones frescas...');
            $process = new Process(['php', 'bin/console', 'make:migration']);
            $process->setWorkingDirectory($this->projectDir);
            $process->run();
            
            if (!$process->isSuccessful() && 
                !str_contains($process->getOutput(), 'No changes detected') && 
                !str_contains($process->getErrorOutput(), 'No changes detected')) {
                $io->error(['Error al generar migraciones:', $process->getErrorOutput()]);
                return false;
            }
            
            // Ejecutamos la migración
            $io->note('Ejecutando migraciones...');
            $process = new Process(['php', 'bin/console', 'doctrine:migrations:migrate', '--no-interaction']);
            $process->setWorkingDirectory($this->projectDir);
            $process->run();
            
            if (!$process->isSuccessful()) {
                $io->error(['Error al ejecutar migraciones:', $process->getErrorOutput()]);
                
                // Como último recurso, intentamos una solución más directa
                $io->warning('Intentando solución alternativa...');
                $this->createTablesDirectly($io);
            }
        } else {
            $io->note('Esquema de base de datos creado correctamente.');
        }
        
        // Verificamos si las tablas principales existen
        try {
            $connection = $this->entityManager->getConnection();
            $tableExists = $connection->executeQuery("SELECT name FROM sqlite_master WHERE type='table' AND name='user'")->fetchOne();
            
            if ($tableExists) {
                $io->success('Base de datos SQLite configurada correctamente.');
                return true;
            } else {
                $io->warning('No se pudo verificar la existencia de las tablas principales.');
                return $this->createTablesDirectly($io);
            }
        } catch (\Exception $e) {
            $io->error(['Error al verificar las tablas:', $e->getMessage()]);
            return false;
        }
    }
    
    private function createTablesDirectly(SymfonyStyle $io): bool
    {
        $io->note('Creando tablas directamente...');
        
        try {
            $connection = $this->entityManager->getConnection();
            
            // Crear tabla user
            $connection->executeQuery('
                CREATE TABLE IF NOT EXISTS "user" (
                    id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL, 
                    email VARCHAR(180) NOT NULL,
                    roles CLOB NOT NULL --(DC2Type:json)
                    ,
                    password VARCHAR(255) NOT NULL, 
                    nombre VARCHAR(255) NOT NULL,
                    apellidos VARCHAR(255) NOT NULL, 
                    created_at DATETIME NOT NULL --(DC2Type:datetime_immutable)
                    , 
                    last_login DATETIME DEFAULT NULL --(DC2Type:datetime_immutable)
                    , 
                    is_active BOOLEAN NOT NULL, 
                    ip_address VARCHAR(45) DEFAULT NULL
                )
            ');
            
            // Crear índice único en email
            $connection->executeQuery('CREATE UNIQUE INDEX IF NOT EXISTS UNIQ_8D93D649E7927C74 ON "user" (email)');
            
            // Crear tabla modulo
            $connection->executeQuery('
                CREATE TABLE IF NOT EXISTS modulo (
                    id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL, 
                    nombre VARCHAR(255) NOT NULL, 
                    descripcion VARCHAR(255) NOT NULL, 
                    install_date DATETIME DEFAULT NULL --(DC2Type:datetime_immutable)
                    , 
                    uninstall_date DATETIME DEFAULT NULL --(DC2Type:datetime_immutable)
                    , 
                    icon VARCHAR(255) NOT NULL, 
                    ruta VARCHAR(255) NOT NULL, 
                    estado BOOLEAN NOT NULL
                )
            ');
            
            // Crear tabla menu_element
            $connection->executeQuery('
                CREATE TABLE IF NOT EXISTS menu_element (
                    id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL, 
                    icon VARCHAR(255) NOT NULL, 
                    type VARCHAR(255) NOT NULL, 
                    parent_id INTEGER DEFAULT NULL, 
                    ruta VARCHAR(255) NOT NULL, 
                    enabled BOOLEAN NOT NULL, 
                    nombre VARCHAR(255) NOT NULL
                )
            ');
            
            // Crear tabla de relación menu_element_modulo
            $connection->executeQuery('
                CREATE TABLE IF NOT EXISTS menu_element_modulo (
                    menu_element_id INTEGER NOT NULL, 
                    modulo_id INTEGER NOT NULL, 
                    PRIMARY KEY(menu_element_id, modulo_id),
                    CONSTRAINT FK_A48FEF9F3F0A914D FOREIGN KEY (menu_element_id) REFERENCES menu_element (id) ON DELETE CASCADE,
                    CONSTRAINT FK_A48FEF9FC07F55F5 FOREIGN KEY (modulo_id) REFERENCES modulo (id) ON DELETE CASCADE
                )
            ');
            
            // Crear índices para la tabla de relación
            $connection->executeQuery('CREATE INDEX IF NOT EXISTS IDX_A48FEF9F3F0A914D ON menu_element_modulo (menu_element_id)');
            $connection->executeQuery('CREATE INDEX IF NOT EXISTS IDX_A48FEF9FC07F55F5 ON menu_element_modulo (modulo_id)');
            
            // Crear tabla de migraciones
            $connection->executeQuery('
                CREATE TABLE IF NOT EXISTS doctrine_migration_versions (
                    version VARCHAR(191) NOT NULL, 
                    executed_at DATETIME DEFAULT NULL, 
                    execution_time INTEGER DEFAULT NULL, 
                    PRIMARY KEY(version)
                )
            ');
            
            $io->success('Tablas creadas correctamente de forma directa.');
            return true;
        } catch (\Exception $e) {
            $io->error(['Error al crear tablas directamente:', $e->getMessage()]);
            return false;
        }
    }

    private function createAdminUser(SymfonyStyle $io, InputInterface $input, OutputInterface $output): bool
    {
        $io->section('Creación de usuario administrador');
        
        // Obtener valores de las opciones o preguntar interactivamente
        $email = $input->getOption('admin-user');
        if ($email === null) {
            $email = $io->ask('Email del administrador', 'admin@example.com', function ($email) {
                if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                    throw new \RuntimeException('El email no es válido.');
                }
                return $email;
            });
        } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $io->error('El email proporcionado no es válido.');
            return false;
        }
        
        // Verificar si el email ya está en uso
        $existingUser = $this->entityManager->getRepository(User::class)->findOneBy(['email' => $email]);
        if ($existingUser) {
            $io->error('Este email ya está en uso.');
            return false;
        }
        
        $nombre = $input->getOption('admin-name');
        if ($nombre === null) {
            $nombre = $io->ask('Nombre', 'Admin');
        }
        
        $apellidos = $input->getOption('admin-lastname');
        if ($apellidos === null) {
            $apellidos = $io->ask('Apellidos', 'Usuario');
        }
        
        $ipAddress = $input->getOption('admin-ip');
        if ($ipAddress === null) {
            $ipAddress = $io->ask('IP para autologueo del administrador (deje en blanco para usar localhost)', '127.0.0.1', function ($ip) {
                if (empty($ip)) {
                    return '127.0.0.1';
                }
                if (!filter_var($ip, FILTER_VALIDATE_IP)) {
                    throw new \RuntimeException('La IP no es válida.');
                }
                return $ip;
            });
        } elseif (!filter_var($ipAddress, FILTER_VALIDATE_IP)) {
            $io->error('La IP proporcionada no es válida.');
            return false;
        }
        
        $password = $input->getOption('admin-password');
        if ($password === null) {
            $helper = $this->getHelper('question');
            $question = new Question('Contraseña (no se mostrará): ');
            $question->setHidden(true);
            $question->setHiddenFallback(false);
            $password = $helper->ask($input, $output, $question);
            
            $confirmQuestion = new Question('Confirme la contraseña: ');
            $confirmQuestion->setHidden(true);
            $confirmQuestion->setHiddenFallback(false);
            $confirmPassword = $helper->ask($input, $output, $confirmQuestion);
            
            if ($password !== $confirmPassword) {
                $io->error('Las contraseñas no coinciden.');
                return false;
            }
        }
        
        try {
            $user = new User();
            $user->setEmail($email);
            $user->setNombre($nombre);
            $user->setApellidos($apellidos);
            $user->setCreatedAt(new \DateTimeImmutable());
            $user->setIsActive(true);
            
            // Verificar si es el primer usuario
            $userCount = $this->entityManager->getRepository(User::class)->count([]);
            if ($userCount === 0) {
                $user->setRoles(['ROLE_ADMIN']);
                $io->note('Este es el primer usuario, se asignará el rol de Administrador.');
            } else {
                $user->setRoles(['ROLE_USER']);
                $io->note('Usuario registrado con rol de Usuario regular.');
            }
            
            // Establecer la IP para autologueo
            $user->setIpAddress($ipAddress);
            $io->note("IP registrada para autologueo: $ipAddress");
            
            $hashedPassword = $this->passwordHasher->hashPassword($user, $password);
            $user->setPassword($hashedPassword);
            
            $this->entityManager->persist($user);
            $this->entityManager->flush();
            
            $io->success("Usuario administrador creado con éxito: $email");
            return true;
        } catch (\Exception $e) {
            $io->error('Error al crear el usuario: ' . $e->getMessage());
            return false;
        }
    }

    private function setupEssentialModules(SymfonyStyle $io, InputInterface $input): bool
    {
        $io->section('Configuración de módulos esenciales');
        
        // Verificar si el módulo Core ya está registrado
        $coreModule = $this->entityManager->getRepository(Modulo::class)->findOneBy(['nombre' => 'Core']);
        
        if (!$coreModule) {
            $io->note('Registrando el módulo Core...');
            
            try {
                $coreModule = new Modulo();
                $coreModule->setNombre('Core');
                $coreModule->setDescripcion('Módulo principal del sistema Exnet');
                $coreModule->setIcon('fas fa-cube');
                $coreModule->setRuta('/');
                $coreModule->setEstado(true);
                $coreModule->setInstallDate(new \DateTimeImmutable());
                
                $this->entityManager->persist($coreModule);
                
                // Crear elementos de menú para Core
                $this->createMenuElement(
                    'Inicio', 
                    'fas fa-home', 
                    'menu', 
                    0, 
                    '/', 
                    $coreModule, 
                    0
                );
                
                // Elemento de menú Respaldos
                $this->createMenuElement(
                    'Respaldos', 
                    'fas fa-database', 
                    'menu', 
                    0, 
                    '/backups', 
                    $coreModule, 
                    1
                );
                
                $this->entityManager->flush();
                $io->success('Módulo Core registrado correctamente.');
            } catch (\Exception $e) {
                $io->error('Error al registrar el módulo Core: ' . $e->getMessage());
                return false;
            }
        } else {
            $io->note('El módulo Core ya está registrado.');
        }
        
        // Preguntar sobre la instalación de otros módulos
        $installModules = $input->getOption('install-modules');
        $shouldInstallModules = $installModules === 'yes';
        
        if ($installModules === null) {
            $question = new ConfirmationQuestion('¿Desea configurar los módulos adicionales ahora? (recomendado) [s/n]', true);
            $shouldInstallModules = $io->askQuestion($question);
        }
        
        if ($shouldInstallModules) {
            $io->note('La funcionalidad para instalar módulos adicionales desde este comando está en desarrollo.');
            $io->note('Por ahora, puede instalar módulos adicionales desde la interfaz web en: /modulos/marketplace');
        } else {
            $io->note('No se instalarán módulos adicionales.');
        }
        
        return true;
    }

    private function createMenuElement(
        string $nombre, 
        string $icon, 
        string $type, 
        int $parentId, 
        string $ruta, 
        Modulo $modulo, 
        int $orden = 0
    ): MenuElement {
        $menuElement = new MenuElement();
        $menuElement->setNombre($nombre);
        $menuElement->setIcon($icon);
        $menuElement->setType($type);
        $menuElement->setParentId($parentId);
        $menuElement->setRuta($ruta);
        $menuElement->setEnabled(true);
        $menuElement->addModulo($modulo);
        
        $this->entityManager->persist($menuElement);
        
        return $menuElement;
    }

    private function finalSetup(SymfonyStyle $io, InputInterface $input): bool
    {
        $io->section('Configuración final');
        
        // Limpiar la caché
        $io->note('Limpiando caché...');
        $process = new Process(['php', 'bin/console', 'cache:clear']);
        $process->setWorkingDirectory($this->projectDir);
        $process->run();
        
        if (!$process->isSuccessful()) {
            $io->error(['Error al limpiar la caché:', $process->getErrorOutput()]);
            return false;
        }
        
        // Verificar configuración de permisos
        $paths = [
            'var/cache',
            'var/log',
            'var/data'
        ];
        
        $io->note('Verificando permisos de directorios...');
        foreach ($paths as $path) {
            $fullPath = $this->projectDir . '/' . $path;
            
            if (!is_dir($fullPath)) {
                $this->filesystem->mkdir($fullPath, 0777);
                $io->note('Creado directorio: ' . $path);
            }
            
            if (!is_writable($fullPath)) {
                $io->warning('El directorio ' . $path . ' no tiene permisos de escritura. Considere ejecutar: chmod -R 777 ' . $path);
            }
        }
        
        // Iniciar servidor web
        $startServer = $input->getOption('start-server');
        $shouldStartServer = $startServer === 'yes';
        
        if ($startServer === null) {
            $question = new ConfirmationQuestion('¿Desea iniciar el servidor web ahora? [s/n]', true);
            $shouldStartServer = $io->askQuestion($question);
        }
        
        if ($shouldStartServer) {
            $port = $input->getOption('server-port');
            if ($port === null) {
                $port = $io->ask('Puerto para el servidor web', '8080');
            }
            
            $io->note('Iniciando servidor web en http://localhost:' . $port);
            $io->note('Presione Ctrl+C para detener el servidor.');
            
            $process = new Process(['php', '-S', 'localhost:' . $port, '-t', 'public']);
            $process->setWorkingDirectory($this->projectDir);
            $process->setTimeout(null);
            $process->start();
            
            $process->wait(function ($type, $buffer) use ($io) {
                if (Process::ERR === $type) {
                    $io->error($buffer);
                } else {
                    $io->write($buffer);
                }
            });
        } else {
            $io->note('Puede iniciar el servidor web manualmente con: php -S localhost:8080 -t public');
        }
        
        return true;
    }
}