<?php

namespace App\ModuloStreaming\Command;

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
use Symfony\Component\DependencyInjection\ParameterBag\ParameterBagInterface;

#[AsCommand(
    name: 'streaming:uninstall',
    description: 'Desinstala completamente el módulo de streaming'
)]
class StreamingUninstallCommand extends Command
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
            ->addOption('keep-tables', null, InputOption::VALUE_NONE, 'Mantener las tablas en la base de datos')
            ->addOption('keep-config', null, InputOption::VALUE_NONE, 'Mantener los archivos de configuración')
            ->addOption('force', 'f', InputOption::VALUE_NONE, 'Forzar la desinstalación sin confirmaciones')
            ->setHelp(<<<EOT
                El comando <info>streaming:uninstall</info> realiza lo siguiente:

                1. Elimina las configuraciones del módulo de streaming de los archivos:
                   - services.yaml
                   - routes.yaml
                   - twig.yaml
                   - doctrine.yaml
                2. Elimina el registro del módulo en la base de datos
                3. Elimina los elementos de menú asociados (incluidos submenús)
                4. Genera una migración para eliminar las tablas de la base de datos
                5. Ejecuta la migración para eliminar las tablas
                6. Limpia la caché del sistema

                Opciones:
                  --keep-tables     No eliminar las tablas de la base de datos
                  --keep-config     No eliminar las configuraciones de los archivos
                  --force, -f       Forzar la desinstalación sin confirmaciones

                Ejemplo de uso:

                <info>php bin/console streaming:uninstall</info>
                <info>php bin/console streaming:uninstall --keep-tables</info>
                <info>php bin/console streaming:uninstall --force</info>
                EOT
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $io->title('Desinstalación del Módulo de Streaming');
        
        $keepTables = $input->getOption('keep-tables');
        $keepConfig = $input->getOption('keep-config');
        $force = $input->getOption('force');
        
        if ($force) {
            $io->note('La opción --force implica confirmar automáticamente todas las preguntas.');
        }
        
        if (!$force && !$io->confirm('¿Estás seguro de que deseas desinstalar completamente el módulo de streaming?', false)) {
            $io->warning('Operación cancelada por el usuario.');
            return Command::SUCCESS;
        }
        
        $io->section('Limpiando caché de metadatos de Doctrine');
        $this->clearDoctrineCache($io);
        
        $io->section('Desactivando el módulo de streaming');
        $this->deactivateModule($io);
        
        if (!$keepConfig) {
            $io->section('Eliminando configuraciones');
            $this->removeServicesConfig($io);
            $this->removeRoutesConfig($io);
            $this->removeTwigConfig($io);
            $this->removeDoctrineConfig($io);
        } else {
            $io->note('Se ha omitido la eliminación de configuraciones según las opciones seleccionadas.');
        }
        
        $io->section('Eliminando registros del módulo en la base de datos');
        $this->removeMenuItems($io);
        $this->removeModuleFromDatabase($io);
        $this->cleanOrphanMenuElementModuloRecords($io);
        $this->removeUploadedVideos($io);
        $this->removeAssetsSymlink($io);

        
        if (!$keepTables) {
            $io->section('Eliminando tablas de la base de datos');
            
            if (!$force && !$io->confirm('¿Estás seguro de que deseas eliminar todas las tablas relacionadas con el streaming? Esta acción es irreversible y eliminará todos los datos.', false)) {
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
        
        $io->section('Limpiando caché');
        $this->clearCache($io);
        
        $io->success('El módulo de streaming ha sido desinstalado correctamente.');
        
        return Command::SUCCESS;
    }
    
    private function clearDoctrineCache(SymfonyStyle $io): void
    {
        try {
            $this->entityManager->getConfiguration()->getMetadataCache()->clear();
            $io->success('Caché de metadatos de Doctrine limpiada correctamente.');
        } catch (\Exception $e) {
            $io->warning('Error al limpiar la caché de metadatos de Doctrine: ' . $e->getMessage());
        }
    }
    
    private function deactivateModule(SymfonyStyle $io): void
    {
        try {
            $moduloRepository = $this->entityManager->getRepository(Modulo::class);
            $streamingModule = $moduloRepository->findOneBy(['nombre' => 'Streaming']);
            
            if ($streamingModule) {
                $streamingModule->setEstado(false);
                $streamingModule->setUninstallDate(new \DateTimeImmutable());
                $this->entityManager->flush();
                $io->success('Módulo de streaming desactivado correctamente.');
            } else {
                $io->note('No se encontró el módulo de streaming para desactivar.');
            }
        } catch (\Exception $e) {
            $io->error('Error al desactivar el módulo de streaming: ' . $e->getMessage() . "\nStack trace: " . $e->getTraceAsString());
        }
    }
    
    private function removeServicesConfig(SymfonyStyle $io): void
    {
        $path = $this->projectDir . '/config/services.yaml';
        $content = file_get_contents($path);
        $pattern = '/^[ \t]*#START\s*-+\s*ModuloStreaming.*?^[ \t]*#END\s*-+\s*ModuloStreaming.*(?:\r?\n)?/sm';
        if (preg_match($pattern, $content)) {
            $newContent = preg_replace($pattern, '', $content);
            file_put_contents($path, $newContent);
            $io->success('Configuración del módulo de streaming eliminada de services.yaml.');
        } else {
            $io->note('No se encontró configuración para eliminar en services.yaml.');
        }
    }
    
    
    private function removeRoutesConfig(SymfonyStyle $io): void
    {
        $routesYamlPath = $this->projectDir . '/config/routes.yaml';
        $routesContent = file_get_contents($routesYamlPath);
        
        $exactPattern = '/\n\nmodulo_streaming_controllers:\n\s+resource:\n\s+path: \.\.\/src\/ModuloStreaming\/Controller\/\n\s+namespace: App\\\\ModuloStreaming\\\\Controller\n\s+type: attribute/s';
        
        if (preg_match($exactPattern, $routesContent)) {
            $routesContent = preg_replace($exactPattern, '', $routesContent);
            file_put_contents($routesYamlPath, $routesContent);
            $io->success('Configuración de controllers del módulo de streaming eliminada de routes.yaml.');
        } else {
            $alternativePattern = '/\n\nmodulo_streaming_controllers:.*?type: attribute/s';
            if (preg_match($alternativePattern, $routesContent)) {
                $routesContent = preg_replace($alternativePattern, '', $routesContent);
                file_put_contents($routesYamlPath, $routesContent);
                $io->success('Configuración de controllers del módulo de streaming eliminada de routes.yaml.');
            } else {
                $commentedPattern = '/\n\n# modulo_streaming_controllers:.*?# type: attribute/s';
                if (preg_match($commentedPattern, $routesContent)) {
                    $routesContent = preg_replace($commentedPattern, '', $routesContent);
                    file_put_contents($routesYamlPath, $routesContent);
                    $io->success('Configuración comentada de controllers del módulo de streaming eliminada de routes.yaml.');
                } else {
                    $io->note('No se encontró configuración de controllers para eliminar en routes.yaml.');
                }
            }
        }
    }
    
    private function removeTwigConfig(SymfonyStyle $io): void
    {
        $twigYamlPath = $this->projectDir . '/config/packages/twig.yaml';
        $twigContent = file_get_contents($twigYamlPath);
        
        $patterns = [
            "/\n\s+'%kernel\.project_dir%\/src\/ModuloStreaming\/templates': ModuloStreaming/",
            "/\n\s+\"%kernel\.project_dir%\/src\/ModuloStreaming\/templates\": ModuloStreaming/",
            "/\n\s+# '%kernel\.project_dir%\/src\/ModuloStreaming\/templates':.*?DESACTIVADO/"
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
            $io->success('Configuración del módulo de streaming eliminada de twig.yaml.');
        } else {
            $io->note('No se encontró configuración para eliminar en twig.yaml.');
        }
    }
    
    private function removeDoctrineConfig(SymfonyStyle $io): void
    {
        $doctrineYamlPath = $this->projectDir . '/config/packages/doctrine.yaml';
        $doctrineContent = file_get_contents($doctrineYamlPath);
        
        $pattern = '/\n\s+ModuloStreaming:\n\s+type: attribute\n\s+is_bundle: false\n\s+dir:.*?\n\s+prefix:.*?\n\s+alias: ModuloStreaming/s';
        
        if (preg_match($pattern, $doctrineContent, $matches)) {
            $newContent = preg_replace($pattern, '', $doctrineContent);
            file_put_contents($doctrineYamlPath, $newContent);
            $io->success('Configuración del módulo de streaming eliminada de doctrine.yaml.');
        } else {
            $io->note('No se encontró configuración para eliminar en doctrine.yaml.');
        }
    }
    
    private function removeModuleFromDatabase(SymfonyStyle $io): void
    {
        try {
            $moduloRepository = $this->entityManager->getRepository(Modulo::class);
            $streamingModule = $moduloRepository->findOneBy(['nombre' => 'Streaming']);
            
            if ($streamingModule) {
                $menuElements = $streamingModule->getMenuElements();
                if (!empty($menuElements)) {
                    foreach ($menuElements as $menuElement) {
                        $streamingModule->removeMenuElement($menuElement);
                    }
                    $this->entityManager->persist($streamingModule);
                    $this->entityManager->flush();
                    $io->note('Relaciones del módulo Streaming con elementos de menú eliminadas.');
                }

                $this->entityManager->remove($streamingModule);
                $this->entityManager->flush();
                $io->success('Módulo Streaming eliminado de la base de datos.');
            } else {
                $io->note('No se encontró el módulo Streaming en la base de datos.');
            }
        } catch (\Exception $e) {
            $io->error('Error al eliminar el módulo de la base de datos: ' . $e->getMessage() . "\nStack trace: " . $e->getTraceAsString());
        }
    }
    
    private function removeMenuItems(SymfonyStyle $io): void
    {
        try {
            if (!$this->entityManager->isOpen()) {
                $io->warning('No se pueden eliminar los elementos de menú porque el EntityManager está cerrado. Por favor, elimina los elementos de menú manualmente.');
                return;
            }

            $moduloRepository = $this->entityManager->getRepository(Modulo::class);
            $menuRepository = $this->entityManager->getRepository(MenuElement::class);
            
            $streamingModule = $moduloRepository->findOneBy(['nombre' => 'Streaming']);
            if ($streamingModule) {
                $moduloId = $streamingModule->getId();

                $connection = $this->entityManager->getConnection();
                $connection->executeStatement('DELETE FROM menu_element_modulo WHERE modulo_id = :moduloId', ['moduloId' => $moduloId]);
                $io->success('Registros asociados en menu_element_modulo eliminados.');
            } else {
                $io->note('No se encontró el módulo Streaming para eliminar registros de menu_element_modulo.');
            }

            $mainMenuItem = $menuRepository->findOneBy(['nombre' => 'Streaming']);
            
            if ($mainMenuItem) {
                $mainMenuId = $mainMenuItem->getId();
                
                $subMenuItems = $menuRepository->findBy(['parentId' => $mainMenuId]);
                
                if (!empty($subMenuItems)) {
                    foreach ($subMenuItems as $subMenuItem) {
                        $this->entityManager->remove($subMenuItem);
                    }
                    $io->success('Submenús del módulo Streaming eliminados de la base de datos.');
                } else {
                    $io->note('No se encontraron submenús para el módulo Streaming.');
                }
                
                $this->entityManager->remove($mainMenuItem);
                $this->entityManager->flush();
                $io->success('Elemento de menú principal "Streaming" eliminado de la base de datos.');
            } else {
                $io->note('No se encontraron elementos de menú para el módulo Streaming.');
            }
        } catch (\Exception $e) {
            $io->error('Error al eliminar los elementos de menú: ' . $e->getMessage() . "\nStack trace: " . $e->getTraceAsString());
        }
    }

    private function removeAssetsSymlink(SymfonyStyle $io): void
    {
        try {
            $symlinkPath = $this->projectDir . '/public/ModuloStreaming';
            if (is_link($symlinkPath) || is_dir($symlinkPath)) {
                if (is_link($symlinkPath)) {
                    unlink($symlinkPath);
                    $io->success('Enlace simbólico eliminado: /public/ModuloStreaming');
                } else {
                    $filesystem = new \Symfony\Component\Filesystem\Filesystem();
                    $filesystem->remove($symlinkPath);
                    $io->success('Directorio eliminado: /public/ModuloStreaming');
                }
            } else {
                $io->note('No se encontró el enlace simbólico o directorio /public/ModuloStreaming.');
            }

            $jsDir = $this->projectDir . '/public/js';
            if (is_dir($jsDir) && count(glob("$jsDir/*")) === 0) {
                rmdir($jsDir);
                $io->success('Directorio vacío /public/js eliminado.');
            }
        } catch (\Exception $e) {
            $io->error('Error al eliminar los assets del módulo: ' . $e->getMessage());
        }
    }

    
    private function removeUploadedVideos(SymfonyStyle $io): void
    {
        $videoDir = $this->projectDir . '/public/uploads/videos';

        if (is_dir($videoDir)) {
            $files = glob($videoDir . '/*');
            foreach ($files as $file) {
                if (is_file($file)) {
                    unlink($file);
                }
            }
            rmdir($videoDir);
            $io->success("Se eliminó la carpeta y los videos de: $videoDir");
        } else {
            $io->note("No se encontró la carpeta de videos: $videoDir");
        }
    }

    
    private function cleanOrphanMenuElementModuloRecords(SymfonyStyle $io): void
    {
        try {
            if (!$this->entityManager->isOpen()) {
                $io->warning('No se pueden limpiar los registros huérfanos de menu_element_modulo porque el EntityManager está cerrado.');
                return;
            }

            $connection = $this->entityManager->getConnection();
            $sql = "
                DELETE FROM menu_element_modulo
                WHERE menu_element_id NOT IN (SELECT id FROM menu_element)
                OR modulo_id NOT IN (SELECT id FROM modulo)
            ";
            $deletedRows = $connection->executeStatement($sql);
            
            if ($deletedRows > 0) {
                $io->success("Se eliminaron $deletedRows registros huérfanos de la tabla menu_element_modulo.");
            } else {
                $io->note('No se encontraron registros huérfanos en menu_element_modulo.');
            }
        } catch (\Exception $e) {
            $io->error('Error al limpiar registros huérfanos de menu_element_modulo: ' . $e->getMessage());
        }
    }
    
    private function generateTableRemovalMigration(SymfonyStyle $io): bool
    {
        $migrationDir = $this->projectDir . '/migrations';
        if (!is_dir($migrationDir)) {
            mkdir($migrationDir, 0777, true);
        }
        
        $timestamp = date('YmdHis');
        $className = "RemoveStreamingTables{$timestamp}";
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
        return 'Eliminar tablas del módulo Streaming';
    }

    public function up(Schema \$schema): void
    {
        \$this->addSql('DROP TABLE IF EXISTS video');
        \$this->addSql('DROP TABLE IF EXISTS categoria');
    }

    public function down(Schema \$schema): void
    {
        \$this->addSql('CREATE TABLE categoria (id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL, nombre VARCHAR(255) NOT NULL, descripcion VARCHAR(255) DEFAULT NULL, icono VARCHAR(255) DEFAULT NULL, creado_en DATETIME NOT NULL)');
        \$this->addSql('CREATE TABLE video (id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL, categoria_id INTEGER DEFAULT NULL, titulo VARCHAR(255) NOT NULL, descripcion CLOB DEFAULT NULL, imagen VARCHAR(255) DEFAULT NULL, url VARCHAR(255) DEFAULT NULL, es_publico BOOLEAN NOT NULL DEFAULT 1, tipo VARCHAR(20) NOT NULL, anio INTEGER DEFAULT NULL, temporada INTEGER DEFAULT NULL, episodio INTEGER DEFAULT NULL, creado_en DATETIME NOT NULL, actualizado_en DATETIME DEFAULT NULL, CONSTRAINT FK_VIDEO_CATEGORIA FOREIGN KEY (categoria_id) REFERENCES categoria (id) NOT DEFERRABLE INITIALLY IMMEDIATE)');
        \$this->addSql('CREATE INDEX IDX_VIDEO_CATEGORIA ON video (categoria_id)');
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
            $process->setWorkingDirectory($this->projectDir);
            $process->setTimeout(300);
            
            $io->note('Ejecutando migración para eliminar tablas...');
            $process->run(function ($type, $buffer) use ($io) {
                $io->write($buffer);
            });
            
            if ($process->isSuccessful()) {
                $io->success('Las tablas del módulo Streaming han sido eliminadas correctamente.');
            } else {
                $io->error('Error al ejecutar la migración. Detalles: ' . $process->getErrorOutput());
            }
        } catch (\Exception $e) {
            $io->error('Error durante la eliminación de tablas: ' . $e->getMessage());
        }
    }
    
    private function clearCache(SymfonyStyle $io): void
    {
        try {
            $process = new Process(['php', 'bin/console', 'cache:clear']);
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
}