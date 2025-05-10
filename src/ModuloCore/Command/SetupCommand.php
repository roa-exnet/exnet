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
use Symfony\Component\Process\Process;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\DependencyInjection\ParameterBag\ParameterBagInterface;
use Symfony\Component\Dotenv\Dotenv;
use App\ModuloCore\Service\KeycloakRealmService;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Doctrine\ORM\Tools\SchemaTool;

#[AsCommand(
    name: 'exnet:setup',
    description: 'Instalación asistida de Exnet: configura la base de datos, crea usuario admin, y configura módulos básicos.'
)]
class SetupCommand extends Command
{
    private EntityManagerInterface $entityManager;
    private HttpClientInterface $httpClient;
    private ParameterBagInterface $parameterBag;
    private string $projectDir;
    private Filesystem $filesystem;

    public function __construct(
        EntityManagerInterface $entityManager,
        ParameterBagInterface $parameterBag,
        HttpClientInterface $httpClient
    ) {
        parent::__construct();

        $this->entityManager = $entityManager;
        $this->parameterBag = $parameterBag;
        $this->httpClient = $httpClient;
        $this->projectDir = $parameterBag->get('kernel.project_dir');
        $this->filesystem = new Filesystem();
    }

protected function configure(): void
{
    $this
        ->setHelp(<<<EOT
            El comando <info>exnet:setup</info> le guía a través del proceso de instalación de Exnet:

            1. Verifica los requisitos del sistema
            2. Configura el archivo .env y la base de datos
            3. Crea un usuario administrador (sin contraseña)
            4. Instala y configura los módulos esenciales
            5. Configura la aplicación

            Este comando puede ser interactivo o usar opciones para configuración automática.
            EOT
        )
        ->addOption('force', 'f', InputOption::VALUE_NONE, 'Forzar la ejecución incluso si el sistema ya está configurado')
        ->addOption('app-env', null, InputOption::VALUE_REQUIRED, 'Entorno de ejecución (dev, prod, test)', 'dev')
        ->addOption('app-secret', null, InputOption::VALUE_REQUIRED, 'Secret personalizado. Si no se especifica, se genera automáticamente según el entorno')
        ->addOption('cors-url', null, InputOption::VALUE_REQUIRED, 'URL para CORS (* para todos)', '*')
        ->addOption('admin-user', null, InputOption::VALUE_REQUIRED, 'Email del administrador', 'admin@example.com')
        ->addOption('admin-name', null, InputOption::VALUE_REQUIRED, 'Nombre del administrador', 'Admin')
        ->addOption('admin-lastname', null, InputOption::VALUE_REQUIRED, 'Apellidos del administrador', 'Usuario')
        ->addOption('admin-ip', null, InputOption::VALUE_REQUIRED, 'IP para autologueo del administrador', '127.0.0.1')
        ->addOption('install-modules', null, InputOption::VALUE_REQUIRED, 'Instalar módulos adicionales (yes/no)', 'yes')
        ->addOption('start-server', null, InputOption::VALUE_REQUIRED, 'Iniciar servidor web (yes/no)', 'yes')
        ->addOption('server-port', null, InputOption::VALUE_REQUIRED, 'Puerto para el servidor web', '8080');
}

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $io->title('Instalación asistida de Exnet');

        $isConfigured = $this->isAlreadyConfigured();
        if ($isConfigured && !$input->getOption('force')) {
            $io->warning('El sistema ya parece estar configurado. Use --force para reinstalar.');
            return Command::SUCCESS;
        }

        if (!$this->checkRequirements($io)) {
            $io->error('No se cumplen los requisitos mínimos. Por favor, corrija los problemas antes de continuar.');
            return Command::FAILURE;
        }

        if (!$this->configureEnvironment($io, $input, $output)) {
            $io->error('Error configurando el entorno.');
            return Command::FAILURE;
        }

        if (!$this->setupDatabase($io)) {
            $io->error('Error configurando la base de datos.');
            return Command::FAILURE;
        }

        if (!$this->createAdminUser($io, $input, $output)) {
            $io->error('Error creando el usuario administrador.');
            return Command::FAILURE;
        }

        if (!$this->setupEssentialModules($io, $input)) {
            $io->error('Error configurando los módulos esenciales.');
            return Command::FAILURE;
        }

        if (!$this->finalSetup($io, $input)) {
            $io->error('Error en la configuración final.');
            return Command::FAILURE;
        }

        $randomId = substr(str_replace(['+', '/', '='], '', base64_encode(random_bytes(8))), 0, 10);
        $realmName = 'realm-' . $randomId;
        $keycloakService = new KeycloakRealmService($this->httpClient);

        $io->section('Paso 7: Instalando realm en Keycloak...');

        $envFile = $this->projectDir . '/.env';
        $envLocalFile = $this->projectDir . '/.env.local';

        $io->note('Eliminando configuración previa de Keycloak si existe...');
        $this->removeEnvVariables(['KEYCLOAK_URL', 'KEYCLOAK_REALM'], $envFile);
        $this->removeEnvVariables(['KEYCLOAK_URL', 'KEYCLOAK_REALM'], $envLocalFile);

        $result = $keycloakService->instalarRealm($realmName);

        if (!$result['success']) {
            $io->error('Error al instalar el realm: ' . $result['error']);
            return Command::FAILURE;
        }

        $io->success('Realm instalado correctamente: ' . $result['realm']);

        if (!isset($_ENV['KEYCLOAK_URL']) || !isset($_ENV['KEYCLOAK_REALM'])) {
            $io->note('Asegurando que las variables de Keycloak estén configuradas correctamente...');

            $keycloakUrl = $result['keycloak_url'] ?? rtrim($this->apiUrl ?? $_ENV['API_URL'] ?? '', '/');
            $keycloakRealm = $result['realm'] ?? $realmName;

            $this->updateEnvVariable('KEYCLOAK_URL', $keycloakUrl, $envLocalFile);
            $this->updateEnvVariable('KEYCLOAK_REALM', $keycloakRealm, $envLocalFile);

            $io->note("Variables de Keycloak actualizadas: URL=$keycloakUrl, REALM=$keycloakRealm");
        }

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

        $envFile = $this->projectDir . '/.env';
        $envLocalFile = $this->projectDir . '/.env.local';

        if (!file_exists($envFile)) {
            $io->error('No se encontró el archivo .env base. Por favor, asegúrese de que el proyecto está correctamente instalado.');
            return false;
        }

        $dotenv = new Dotenv();
        $dotenv->load($envFile);
        if (file_exists($envLocalFile)) {
            $dotenv->load($envLocalFile);
        }

        $envOptions = ['dev', 'prod', 'test'];
        $environment = $input->getOption('app-env');
        if (!in_array($environment, $envOptions, true)) {
            $question = new ChoiceQuestion(
                'Seleccione el entorno de ejecución:',
                $envOptions,
                0 
            );
            $environment = $io->askQuestion($question);
        }
        $this->updateEnvVariable('APP_ENV', $environment, $envLocalFile);
        $io->note("Entorno configurado: $environment");

        $appSecret = $input->getOption('app-secret');
        if (empty($appSecret)) {
            $appSecret = $_ENV['APP_SECRET'] ?? '';

            if (empty($appSecret)) {

                if ($environment === 'prod') {

                    $appSecret = bin2hex(random_bytes(32)); 
                    $io->note('Generando APP_SECRET complejo para entorno de producción');
                } else {

                    $appSecret = bin2hex(random_bytes(16)); 
                    $io->note('Generando APP_SECRET estándar para entorno de desarrollo');
                }

                $this->updateEnvVariable('APP_SECRET', $appSecret, $envLocalFile);
                $io->note("APP_SECRET configurado según el entorno: " . substr($appSecret, 0, 8) . '...');
            } else {
                $io->note("Se conserva el APP_SECRET existente: " . substr($appSecret, 0, 8) . '...');
            }
        } else {

            $this->updateEnvVariable('APP_SECRET', $appSecret, $envLocalFile);
            $io->note("APP_SECRET personalizado configurado: " . substr($appSecret, 0, 8) . '...');
        }

        $corsUrl = $input->getOption('cors-url');
        if ($corsUrl === null) {
            $corsUrl = $io->ask('URL para CORS (separados por coma si hay varios, * para permitir todos)', '*');
        }
        $this->updateEnvVariable('CORS_ALLOW_ORIGIN', $corsUrl, $envLocalFile);
        $io->note("URL CORS configurada: $corsUrl");

        if (file_exists($envLocalFile)) {
            $dotenv->load($envLocalFile);
        }

        $io->success('Configuración del entorno completada.');
        return true;
    }

    private function updateEnvVariable(string $name, string $value, string $envFile): void
    {
        $escaped = str_replace('"', '\"', $value);

        if (file_exists($envFile)) {
            $content = file_get_contents($envFile);

            if (preg_match("/^{$name}=/m", $content)) {
                $content = preg_replace(
                    "/^{$name}=.*/m",
                    "{$name}=\"{$escaped}\"",
                    $content
                );
            } else {

                $content .= PHP_EOL . "{$name}=\"{$escaped}\"";
            }

            file_put_contents($envFile, $content);
        } else {

            file_put_contents($envFile, "{$name}=\"{$escaped}\"" . PHP_EOL);
        }

        $_ENV[$name] = $value;
        putenv("$name=$value");
    }

    private function removeEnvVariables(array $variableNames, string $envFile): void
    {
        if (file_exists($envFile)) {
            $content = file_get_contents($envFile);

            foreach ($variableNames as $name) {

                $pattern = "/^{$name}=.*(\r?\n)?/m";
                $content = preg_replace($pattern, '', $content);
            }

            $content = preg_replace("/(\r?\n){2,}/", PHP_EOL . PHP_EOL, $content);

            file_put_contents($envFile, $content);
        }
    }

    private function setupDatabase(SymfonyStyle $io): bool
    {
        $io->section('Configuración de la base de datos');

        $dbPath = $this->projectDir . '/var/data';
        if (!is_dir($dbPath)) {
            $io->note('Creando directorio para la base de datos SQLite...');
            $this->filesystem->mkdir($dbPath, 0777);
        }

        $dbFile = $dbPath . '/database.sqlite';
        $io->note('Verificando la base de datos SQLite...');

        if (file_exists($dbFile)) {
            $io->note('Se encontró una base de datos existente.');

            try {
                $connection = $this->entityManager->getConnection();

                if (!$connection->isConnected()) {
                    $connection->executeQuery('SELECT 1');
                }
                $io->note('Conexión a la base de datos existente establecida.');
            } catch (\Exception $e) {
                $io->warning('Error al conectar con la base de datos existente: ' . $e->getMessage());

                $backupFile = $dbFile . '.backup.' . date('YmdHis');
                $io->note('Haciendo backup de la base de datos actual en: ' . $backupFile);

                if (copy($dbFile, $backupFile)) {
                    $io->note('Backup realizado correctamente.');

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

            $io->note('Creando archivo de base de datos SQLite...');
            touch($dbFile);
            chmod($dbFile, 0666); 
        }

        $io->note('Creando esquema de base de datos usando Doctrine...');

        try {

            if (empty($_ENV['APP_SECRET'])) {
                $io->error('APP_SECRET no está definido. Por favor, configure esta variable antes de continuar.');
                return false;
            }

            $process = new Process(['php', 'bin/console', 'doctrine:schema:create', '--no-interaction']);
            $process->setWorkingDirectory($this->projectDir);
            $process->run();

            if (!$process->isSuccessful()) {
                $io->warning('No se pudo crear el esquema con doctrine:schema:create. Intentando métodos alternativos...');
                $io->warning('Mensaje de error: ' . $process->getErrorOutput());

                if (!$this->createTablesWithDoctrine($io)) {
                    return false;
                }
            } else {
                $io->note('Esquema de base de datos creado correctamente con doctrine:schema:create.');
            }

            $connection = $this->entityManager->getConnection();
            $tablesQuery = "SELECT name FROM sqlite_master WHERE type='table' AND name IN ('user', 'modulo', 'menu_element')";
            $tables = $connection->executeQuery($tablesQuery)->fetchFirstColumn();

            if (count($tables) >= 3) {
                $io->success('Tablas principales verificadas: ' . implode(', ', $tables));
                return true;
            } else {
                $io->warning('Algunas tablas principales podrían no haberse creado correctamente.');
                $io->note('Tablas actuales: ' . implode(', ', $tables));
                $io->note('Intentando crear tablas faltantes...');

                return $this->createTablesWithDoctrine($io);
            }
        } catch (\Exception $e) {
            $io->error('Error al configurar la base de datos: ' . $e->getMessage());
            return false;
        }
    }

    private function createTablesWithDoctrine(SymfonyStyle $io): bool
    {
        $io->note('Creando tablas usando SchemaTool de Doctrine...');

        try {

            $em = $this->entityManager;
            $metadataFactory = $em->getMetadataFactory();

            $classList = [
                User::class,
                Modulo::class,
                MenuElement::class
            ];

            $metadata = [];
            foreach ($classList as $className) {
                $metadata[] = $metadataFactory->getMetadataFor($className);
            }

            $schemaTool = new SchemaTool($em);
            $schemaTool->createSchema($metadata);

            $io->success('Tablas creadas correctamente usando SchemaTool de Doctrine.');

            $connection = $em->getConnection();
            $migrationsTable = $connection->executeQuery("SELECT name FROM sqlite_master WHERE type='table' AND name='doctrine_migration_versions'")->fetchOne();

            if (!$migrationsTable) {
                $io->note('Creando tabla de migraciones...');

                $connection->executeStatement('
                    CREATE TABLE IF NOT EXISTS doctrine_migration_versions (
                        version VARCHAR(191) NOT NULL, 
                        executed_at DATETIME DEFAULT NULL, 
                        execution_time INTEGER DEFAULT NULL, 
                        PRIMARY KEY(version)
                    )
                ');

                $io->note('Tabla de migraciones creada correctamente.');
            }

            return true;
        } catch (\Exception $e) {
            $io->error('Error al crear tablas usando SchemaTool: ' . $e->getMessage());
            return false;
        }
    }

    private function createAdminUser(SymfonyStyle $io, InputInterface $input, OutputInterface $output): bool
    {
        $io->section('Creación de usuario administrador');

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

        try {
            $user = new User();
            $user->setEmail($email);
            $user->setNombre($nombre);
            $user->setApellidos($apellidos);
            $user->setCreatedAt(new \DateTimeImmutable());
            $user->setIsActive(true);

            $userCount = $this->entityManager->getRepository(User::class)->count([]);
            if ($userCount === 0) {
                $user->setRoles(['ROLE_ADMIN']);
                $io->note('Este es el primer usuario, se asignará el rol de Administrador.');
            } else {
                $user->setRoles(['ROLE_USER']);
                $io->note('Usuario registrado con rol de Usuario regular.');
            }

            $user->setIpAddress($ipAddress);
            $io->note("IP registrada para autologueo: $ipAddress");

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

                $this->createMenuElement(
                    'Inicio', 
                    'fas fa-home', 
                    'menu', 
                    0, 
                    '/', 
                    $coreModule
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
        Modulo $modulo
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

    private function setupModuleCoreSymlinks(SymfonyStyle $io): bool
    {
        try {
            $filesystem = new Filesystem();

            $assetsSourcePath = $this->projectDir . '/src/ModuloCore/Assets';
            if (!$filesystem->exists($assetsSourcePath)) {
                $io->error('La carpeta Resources/public no existe en el módulo Core. No se puede crear el enlace simbólico.');
                return false;
            }

            $targetDir = $this->projectDir . '/public';
            $jsDir = $targetDir . '/js';
            if (!$filesystem->exists($jsDir)) {
                $filesystem->mkdir($jsDir);
                $io->text('Creado directorio: /public/js');
            }

            $symlinkPath = $targetDir . '/ModuloCore';

            if ($filesystem->exists($symlinkPath)) {
                if (is_link($symlinkPath)) {
                    $filesystem->remove($symlinkPath);
                    $io->text('Enlace simbólico existente eliminado');
                } else {
                    $io->warning('La ruta /public/ModuloCore existe pero no es un enlace simbólico. Eliminando...');
                    $filesystem->remove($symlinkPath);
                }
            }

            if (function_exists('symlink')) {
                $filesystem->symlink($assetsSourcePath, $symlinkPath);
                $io->success('Enlace simbólico creado correctamente: /public/ModuloCore -> /src/ModuloCore/Resources/public');
            } else {
                $io->warning('Tu sistema no soporta enlaces simbólicos. Copiando archivos en su lugar...');
                if (!$filesystem->exists($symlinkPath)) {
                    $filesystem->mkdir($symlinkPath);
                }
                $filesystem->mirror($assetsSourcePath, $symlinkPath);
                $io->text('Archivos copiados a /public/ModuloCore');
                $filesystem->dumpFile(
                    $symlinkPath . '/README.txt',
                    "Esta carpeta contiene una copia de los assets de src/ModuloCore/Resources/public.\n" .
                    "Se recomienda actualizar ambas carpetas cuando se realizan cambios en los archivos."
                );
            }

            return true;
        } catch (\Exception $e) {
            $io->error('Error al configurar el enlace simbólico para los assets del ModuloCore: ' . $e->getMessage());
            return false;
        }
    }

    private function finalSetup(SymfonyStyle $io, InputInterface $input): bool
    {
        $io->section('Configuración final');

        $io->note('Limpiando caché...');
        $process = new Process(['php', 'bin/console', 'cache:clear']);
        $process->setWorkingDirectory($this->projectDir);
        $process->run();

        if (!$process->isSuccessful()) {
            $io->error(['Error al limpiar la caché:', $process->getErrorOutput()]);
            return false;
        }

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

        if (!$this->setupModuleCoreSymlinks($io)) {
            $io->warning('No se pudieron configurar los enlaces simbólicos para ModuloCore correctamente.');

        }

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
            $io->note('Servidor arrancado correctamente');
        }

        return true;
    }
}