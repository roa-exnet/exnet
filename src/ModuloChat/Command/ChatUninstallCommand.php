<?php

namespace App\ModuloChat\Command;

use App\ModuloCore\Entity\Modulo;
use App\ModuloCore\Entity\MenuElement;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Process\Process;

#[AsCommand(
    name: 'modulochat:uninstall',
    description: 'Desinstala completamente el módulo de chat'
)]
class ChatUninstallCommand extends Command
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
            ->addOption('keep-tables', null, InputOption::VALUE_NONE, 'Mantener las tablas en la base de datos')
            ->addOption('keep-config', null, InputOption::VALUE_NONE, 'Mantener los archivos de configuración')
            ->addOption('force', 'f', InputOption::VALUE_NONE, 'Forzar la desinstalación sin confirmaciones')
            ->setHelp(<<<EOT
                El comando <info>modulochat:uninstall</info> realiza lo siguiente:

                1. Elimina las configuraciones del módulo de chat de los archivos:
                   - services.yaml
                   - routes.yaml
                   - twig.yaml
                   - doctrine.yaml
                2. Elimina el registro del módulo en la base de datos
                3. Elimina los elementos de menú asociados
                4. Genera una migración para eliminar las tablas de la base de datos
                5. Ejecuta la migración para eliminar las tablas

                Opciones:
                  --keep-tables     No eliminar las tablas de la base de datos
                  --keep-config     No eliminar las configuraciones de los archivos
                  --force, -f       Forzar la desinstalación sin confirmaciones

                Ejemplo de uso:

                <info>php bin/console modulochat:uninstall</info>
                <info>php bin/console modulochat:uninstall --keep-tables</info>
                <info>php bin/console modulochat:uninstall --force</info>
                EOT
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $io->title('Desinstalación del Módulo de Chat');
        
        $keepTables = $input->getOption('keep-tables');
        $keepConfig = $input->getOption('keep-config');
        $force = $input->getOption('force');
        
        // Confirmación si no se usa --force
        if (!$force && !$io->confirm('¿Estás seguro de que deseas desinstalar completamente el módulo de chat?', false)) {
            $io->warning('Operación cancelada por el usuario.');
            return Command::SUCCESS;
        }
        
        // Primero desactivar el módulo para evitar errores durante la desinstalación
        $io->section('Desactivando el módulo de chat');
        $this->runCommand('modulochat:deactivate', $io, $output);
        
        // Eliminar configuraciones
        if (!$keepConfig) {
            $io->section('Eliminando configuraciones');
            $this->removeServicesConfig($io);
            $this->removeRoutesConfig($io);
            $this->removeTwigConfig($io);
            $this->removeDoctrineConfig($io);
        } else {
            $io->note('Se ha omitido la eliminación de configuraciones según las opciones seleccionadas.');
        }
        
        // Eliminar registros de la base de datos
        $io->section('Eliminando registros del módulo en la base de datos');
        $this->removeModuleFromDatabase($io);
        $this->removeMenuItems($io);
        
        // Eliminar tablas de la base de datos
        if (!$keepTables) {
            $io->section('Eliminando tablas de la base de datos');
            
            if (!$force && !$io->confirm('¿Estás seguro de que deseas eliminar todas las tablas relacionadas con el chat? Esta acción es irreversible y eliminará todos los datos.', false)) {
                $io->warning('Se ha omitido la eliminación de tablas por elección del usuario.');
            } else {
                $success = $this->generateTableRemovalMigration($io);
                if ($success) {
                    $this->executeTableRemovalMigration($io, $force);
                }
            }
        } else {
            $io->note('Se ha omitido la eliminación de tablas según las opciones seleccionadas.');
        }
        
        $io->success('El módulo de chat ha sido desinstalado correctamente.');
        
        return Command::SUCCESS;
    }
    
    private function runCommand(string $command, SymfonyStyle $io, OutputInterface $output): bool
    {
        try {
            $application = $this->getApplication();
            if (!$application) {
                $io->error('No se pudo acceder a la aplicación para ejecutar el comando.');
                return false;
            }
            
            $command = $application->find($command);
            $args = new ArrayInput([]);
            $returnCode = $command->run($args, $output);
            
            return $returnCode === 0;
        } catch (\Exception $e) {
            $io->error('Error al ejecutar el comando: ' . $e->getMessage());
            return false;
        }
    }
    
    private function removeServicesConfig(SymfonyStyle $io): void
    {
        $servicesYamlPath = 'config/services.yaml';
        $servicesContent = file_get_contents($servicesYamlPath);
        
        // Patrón que coincide con toda la sección del ModuloChat
        $pattern = '/#START\s+----+\s+ModuloChat.*?\n.*?#END\s+----+\s+ModuloChat.*?\n/s';
        
        if (preg_match($pattern, $servicesContent, $matches)) {
            $newContent = preg_replace($pattern, '', $servicesContent);
            file_put_contents($servicesYamlPath, $newContent);
            $io->success('Configuración del módulo de chat eliminada de services.yaml.');
        } else {
            $io->note('No se encontró configuración para eliminar en services.yaml.');
        }
    }
    
    private function removeRoutesConfig(SymfonyStyle $io): void
    {
        $routesYamlPath = 'config/routes.yaml';
        $routesContent = file_get_contents($routesYamlPath);
        
        // Patrón para las rutas del módulo chat (activas o comentadas)
        $patterns = [
            '/\n\nmodulo_chat_controllers:.*?type: attribute\n/s',
            '/\n\n# modulo_chat_controllers:.*?# type: attribute\n/s'
        ];
        
        $removed = false;
        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $routesContent)) {
                $routesContent = preg_replace($pattern, '', $routesContent);
                $removed = true;
            }
        }
        
        if ($removed) {
            file_put_contents($routesYamlPath, $routesContent);
            $io->success('Rutas del módulo de chat eliminadas de routes.yaml.');
        } else {
            $io->note('No se encontraron rutas para eliminar en routes.yaml.');
        }
    }
    
    private function removeTwigConfig(SymfonyStyle $io): void
    {
        $twigYamlPath = 'config/packages/twig.yaml';
        $twigContent = file_get_contents($twigYamlPath);
        
        // Patrones para la configuración de Twig (activa o comentada)
        $patterns = [
            "/\n\s+'%kernel\.project_dir%\/src\/ModuloChat\/templates': ModuloChat/",
            "/\n\s+# '%kernel\.project_dir%\/src\/ModuloChat\/templates':.*?DESACTIVADO/"
        ];
        
        $removed = false;
        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $twigContent)) {
                $twigContent = preg_replace($pattern, '', $twigContent);
                $removed = true;
            }
        }
        
        if ($removed) {
            file_put_contents($twigYamlPath, $twigContent);
            $io->success('Configuración del módulo de chat eliminada de twig.yaml.');
        } else {
            $io->note('No se encontró configuración para eliminar en twig.yaml.');
        }
    }
    
    private function removeDoctrineConfig(SymfonyStyle $io): void
    {
        $doctrineYamlPath = 'config/packages/doctrine.yaml';
        $doctrineContent = file_get_contents($doctrineYamlPath);
        
        // Patrón para la sección ModuloChat completa
        $pattern = '/\n\s+ModuloChat:\n\s+type: attribute\n\s+is_bundle: false\n\s+dir:.*?\n\s+prefix:.*?\n\s+alias: ModuloChat/s';
        
        if (preg_match($pattern, $doctrineContent, $matches)) {
            $newContent = preg_replace($pattern, '', $doctrineContent);
            file_put_contents($doctrineYamlPath, $newContent);
            $io->success('Configuración del módulo de chat eliminada de doctrine.yaml.');
        } else {
            $io->note('No se encontró configuración para eliminar en doctrine.yaml.');
        }
    }
    
    private function removeModuleFromDatabase(SymfonyStyle $io): void
    {
        try {
            $moduloRepository = $this->entityManager->getRepository(Modulo::class);
            $chatModule = $moduloRepository->findOneBy(['nombre' => 'Chat']);
            
            if ($chatModule) {
                $this->entityManager->remove($chatModule);
                $this->entityManager->flush();
                $io->success('Módulo Chat eliminado de la base de datos.');
            } else {
                $io->note('No se encontró el módulo Chat en la base de datos.');
            }
        } catch (\Exception $e) {
            $io->error('Error al eliminar el módulo de la base de datos: ' . $e->getMessage());
        }
    }
    
    private function removeMenuItems(SymfonyStyle $io): void
    {
        try {
            $menuRepository = $this->entityManager->getRepository(MenuElement::class);
            $menuItems = $menuRepository->findBy(['nombre' => 'Chat']);
            
            if (!empty($menuItems)) {
                foreach ($menuItems as $menuItem) {
                    $this->entityManager->remove($menuItem);
                }
                $this->entityManager->flush();
                $io->success('Elementos de menú del módulo Chat eliminados de la base de datos.');
            } else {
                $io->note('No se encontraron elementos de menú para el módulo Chat.');
            }
        } catch (\Exception $e) {
            $io->error('Error al eliminar los elementos de menú: ' . $e->getMessage());
        }
    }
    
    private function generateTableRemovalMigration(SymfonyStyle $io): bool
    {
        // Crear un archivo de migración personalizado
        $migrationDir = 'migrations';
        if (!is_dir($migrationDir)) {
            mkdir($migrationDir, 0777, true);
        }
        
        $timestamp = date('YmdHis');
        $className = "RemoveChatTables{$timestamp}";
        $migrationFile = "{$migrationDir}/Version{$timestamp}.php";
        
        $migrationContent = $this->getMigrationTemplate($className);
        
        file_put_contents($migrationFile, $migrationContent);
        $io->success("Migración para eliminar tablas generada: {$migrationFile}");
        
        return true;
    }
    
    private function getMigrationTemplate(string $className): string
    {
        return <<<EOT
<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class {$className} extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Eliminar tablas del módulo Chat';
    }

    public function up(Schema \$schema): void
    {
        // Eliminar tablas en orden seguro (primero las que tienen claves foráneas)
        \$this->addSql('DROP TABLE IF EXISTS chat_message');
        \$this->addSql('DROP TABLE IF EXISTS chat_participant');
        \$this->addSql('DROP TABLE IF EXISTS chat');
    }

    public function down(Schema \$schema): void
    {
        // Este método puede quedarse vacío
        \$this->addSql('CREATE TABLE chat (id VARCHAR(255) NOT NULL, name VARCHAR(255) NOT NULL, created_at DATETIME NOT NULL --(DC2Type:datetime_immutable)
        , closed_at DATETIME DEFAULT NULL --(DC2Type:datetime_immutable)
        , type VARCHAR(20) NOT NULL, is_active BOOLEAN NOT NULL, PRIMARY KEY(id))');
        \$this->addSql('CREATE TABLE chat_participant (id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL, chat_id VARCHAR(255) NOT NULL, participant_identifier VARCHAR(255) NOT NULL, participant_name VARCHAR(255) DEFAULT NULL, joined_at DATETIME NOT NULL --(DC2Type:datetime_immutable)
        , left_at DATETIME DEFAULT NULL --(DC2Type:datetime_immutable)
        , is_active BOOLEAN NOT NULL, role VARCHAR(50) NOT NULL, CONSTRAINT FK_E8ED9C891A9A7125 FOREIGN KEY (chat_id) REFERENCES chat (id) NOT DEFERRABLE INITIALLY IMMEDIATE)');
        \$this->addSql('CREATE TABLE chat_message (id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL, chat_id VARCHAR(255) NOT NULL, sender_identifier VARCHAR(255) NOT NULL, sender_name VARCHAR(255) DEFAULT NULL, content CLOB NOT NULL, sent_at DATETIME NOT NULL --(DC2Type:datetime_immutable)
        , read_at DATETIME DEFAULT NULL --(DC2Type:datetime_immutable)
        , message_type VARCHAR(50) NOT NULL, metadata CLOB DEFAULT NULL --(DC2Type:json)
        , CONSTRAINT FK_FAB3FC161A9A7125 FOREIGN KEY (chat_id) REFERENCES chat (id) NOT DEFERRABLE INITIALLY IMMEDIATE)');
    }
}
EOT;
    }
    
    private function executeTableRemovalMigration(SymfonyStyle $io, bool $force): void
    {
        try {
            $command = ['php', 'bin/console', 'doctrine:migrations:migrate', '--no-interaction'];
            if ($force) {
                $command[] = '--allow-no-migration';
            }
            
            $process = new Process($command);
            $process->setTimeout(300); // 5 minutos
            
            $io->note('Ejecutando migración para eliminar tablas...');
            $process->run(function ($type, $buffer) use ($io) {
                $io->write($buffer);
            });
            
            if ($process->isSuccessful()) {
                $io->success('Las tablas del módulo Chat han sido eliminadas correctamente.');
            } else {
                $io->error('Error al ejecutar la migración. Detalles: ' . $process->getErrorOutput());
            }
        } catch (\Exception $e) {
            $io->error('Error durante la eliminación de tablas: ' . $e->getMessage());
        }
    }
}