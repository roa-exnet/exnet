<?php

namespace App\ModuloMusica\Command;

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
    name: 'musica:uninstall',
    description: 'Desinstala completamente el módulo de música'
)]
class MusicaUninstallCommand extends Command
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
                El comando <info>musica:uninstall</info> realiza lo siguiente:

                1. Elimina las configuraciones del módulo de música de los archivos:
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

                <info>php bin/console musica:uninstall</info>
                <info>php bin/console musica:uninstall --keep-tables</info>
                <info>php bin/console musica:uninstall --force</info>
                EOT
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $io->title('Desinstalación del Módulo de Música');
        
        $keepTables = $input->getOption('keep-tables');
        $keepConfig = $input->getOption('keep-config');
        $force = $input->getOption('force');
        
        if ($force) {
            $io->note('La opción --force implica confirmar automáticamente todas las preguntas.');
        }
        
        if (!$force && !$io->confirm('¿Estás seguro de que deseas desinstalar completamente el módulo de música?', false)) {
            $io->warning('Operación cancelada por el usuario.');
            return Command::SUCCESS;
        }
        
        $io->section('Limpiando caché de metadatos de Doctrine');
        $this->clearDoctrineCache($io);
        
        $io->section('Desactivando el módulo de música');
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
        
        if (!$keepTables) {
            $io->section('Eliminando tablas de la base de datos');
            
            if (!$force && !$io->confirm('¿Estás seguro de que deseas eliminar todas las tablas relacionadas con la música? Esta acción es irreversible y eliminará todos los datos.', false)) {
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
        
        $io->success('El módulo de música ha sido desinstalado correctamente.');
        
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
            $musicaModule = $moduloRepository->findOneBy(['nombre' => 'Música']);
            
            if ($musicaModule) {
                $musicaModule->setEstado(false);
                $musicaModule->setUninstallDate(new \DateTimeImmutable());
                $this->entityManager->flush();
                $io->success('Módulo de música desactivado correctamente.');
            } else {
                $io->note('No se encontró el módulo de música para desactivar.');
            }
        } catch (\Exception $e) {
            $io->error('Error al desactivar el módulo de música: ' . $e->getMessage() . "\nStack trace: " . $e->getTraceAsString());
        }
    }
    
    private function removeServicesConfig(SymfonyStyle $io): void
    {
        $path = 'config/services.yaml';
        $content = file_get_contents($path);
        $pattern = '/^[ \t]*#START\s*-+\s*ModuloMusica.*?^[ \t]*#END\s*-+\s*ModuloMusica.*(?:\r?\n)?/sm';
        if (preg_match($pattern, $content)) {
            $newContent = preg_replace($pattern, '', $content);
            file_put_contents($path, $newContent);
            $io->success('Configuración del módulo de música eliminada de services.yaml.');
        } else {
            $io->note('No se encontró configuración para eliminar en services.yaml.');
        }
    }
    
    
    private function removeRoutesConfig(SymfonyStyle $io): void
    {
        $routesYamlPath = 'config/routes.yaml';
        $routesContent = file_get_contents($routesYamlPath);
        
        $exactPattern = '/\n\nmodulo_musica_controllers:\n\s+resource:\n\s+path: \.\.\/src\/ModuloMusica\/Controller\/\n\s+namespace: App\\\\ModuloMusica\\\\Controller\n\s+type: attribute/s';
        
        if (preg_match($exactPattern, $routesContent)) {
            $routesContent = preg_replace($exactPattern, '', $routesContent);
            file_put_contents($routesYamlPath, $routesContent);
            $io->success('Configuración de controllers del módulo de música eliminada de routes.yaml.');
        } else {
            $alternativePattern = '/\n\nmodulo_musica_controllers:.*?type: attribute/s';
            if (preg_match($alternativePattern, $routesContent)) {
                $routesContent = preg_replace($alternativePattern, '', $routesContent);
                file_put_contents($routesYamlPath, $routesContent);
                $io->success('Configuración de controllers del módulo de música eliminada de routes.yaml.');
            } else {
                $commentedPattern = '/\n\n# modulo_musica_controllers:.*?# type: attribute/s';
                if (preg_match($commentedPattern, $routesContent)) {
                    $routesContent = preg_replace($commentedPattern, '', $routesContent);
                    file_put_contents($routesYamlPath, $routesContent);
                    $io->success('Configuración comentada de controllers del módulo de música eliminada de routes.yaml.');
                } else {
                    $io->note('No se encontró configuración de controllers para eliminar en routes.yaml.');
                }
            }
        }
    }
    
    private function removeTwigConfig(SymfonyStyle $io): void
    {
        $twigYamlPath = 'config/packages/twig.yaml';
        $twigContent = file_get_contents($twigYamlPath);
        
        $patterns = [
            "/\n\s+'%kernel\.project_dir%\/src\/ModuloMusica\/templates': ModuloMusica/",
            "/\n\s+\"%kernel\.project_dir%\/src\/ModuloMusica\/templates\": ModuloMusica/",
            "/\n\s+# '%kernel\.project_dir%\/src\/ModuloMusica\/templates':.*?DESACTIVADO/"
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
            $io->success('Configuración del módulo de música eliminada de twig.yaml.');
        } else {
            $io->note('No se encontró configuración para eliminar en twig.yaml.');
        }
    }
    
    private function removeDoctrineConfig(SymfonyStyle $io): void
    {
        $doctrineYamlPath = 'config/packages/doctrine.yaml';
        $doctrineContent = file_get_contents($doctrineYamlPath);
        
        $pattern = '/\n\s+ModuloMusica:\n\s+type: attribute\n\s+is_bundle: false\n\s+dir:.*?\n\s+prefix:.*?\n\s+alias: ModuloMusica/s';
        
        if (preg_match($pattern, $doctrineContent, $matches)) {
            $newContent = preg_replace($pattern, '', $doctrineContent);
            file_put_contents($doctrineYamlPath, $newContent);
            $io->success('Configuración del módulo de música eliminada de doctrine.yaml.');
        } else {
            $io->note('No se encontró configuración para eliminar en doctrine.yaml.');
        }
    }
    
    private function removeModuleFromDatabase(SymfonyStyle $io): void
    {
        try {
            $moduloRepository = $this->entityManager->getRepository(Modulo::class);
            $musicaModule = $moduloRepository->findOneBy(['nombre' => 'Música']);
            
            if ($musicaModule) {
                $menuElements = $musicaModule->getMenuElements();
                if (!empty($menuElements)) {
                    foreach ($menuElements as $menuElement) {
                        $musicaModule->removeMenuElement($menuElement);
                    }
                    $this->entityManager->persist($musicaModule);
                    $this->entityManager->flush();
                    $io->note('Relaciones del módulo Música con elementos de menú eliminadas.');
                }

                $this->entityManager->remove($musicaModule);
                $this->entityManager->flush();
                $io->success('Módulo Música eliminado de la base de datos.');
            } else {
                $io->note('No se encontró el módulo Música en la base de datos.');
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
            
            $musicaModule = $moduloRepository->findOneBy(['nombre' => 'Música']);
            if ($musicaModule) {
                $moduloId = $musicaModule->getId();

                $connection = $this->entityManager->getConnection();
                $platform = $connection->getDatabasePlatform();
                $sql = $platform->getTruncateTableSQL('menu_element_modulo') . ' WHERE modulo_id = :moduloId';
                $connection->executeStatement('DELETE FROM menu_element_modulo WHERE modulo_id = :moduloId', ['moduloId' => $moduloId]);
                $io->success('Registros asociados en menu_element_modulo eliminados.');
            } else {
                $io->note('No se encontró el módulo Música para eliminar registros de menu_element_modulo.');
            }

            $mainMenuItem = $menuRepository->findOneBy(['nombre' => 'Música']);
            
            if ($mainMenuItem) {
                $mainMenuId = $mainMenuItem->getId();
                
                $subMenuItems = $menuRepository->findBy(['parentId' => $mainMenuId]);
                
                if (!empty($subMenuItems)) {
                    foreach ($subMenuItems as $subMenuItem) {
                        $this->entityManager->remove($subMenuItem);
                    }
                    $io->success('Submenús del módulo Música eliminados de la base de datos.');
                } else {
                    $io->note('No se encontraron submenús para el módulo Música.');
                }
                
                $this->entityManager->remove($mainMenuItem);
                $this->entityManager->flush();
                $io->success('Elemento de menú principal "Música" eliminado de la base de datos.');
            } else {
                $io->note('No se encontraron elementos de menú para el módulo Música.');
            }
        } catch (\Exception $e) {
            $io->error('Error al eliminar los elementos de menú: ' . $e->getMessage() . "\nStack trace: " . $e->getTraceAsString());
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
        $migrationDir = 'migrations';
        if (!is_dir($migrationDir)) {
            mkdir($migrationDir, 0777, true);
        }
        
        $timestamp = date('YmdHis');
        $className = "RemoveMusicaTables{$timestamp}";
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
        return 'Eliminar tablas del módulo Música';
    }

    public function up(Schema \$schema): void
    {
        \$this->addSql('DROP TABLE IF EXISTS musica_playlist_cancion');
        \$this->addSql('DROP TABLE IF EXISTS musica_playlist');
        \$this->addSql('DROP TABLE IF EXISTS musica_cancion');
        \$this->addSql('DROP TABLE IF EXISTS musica_genero');
    }

    public function down(Schema \$schema): void
    {
        \$this->addSql('CREATE TABLE musica_genero (id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL, nombre VARCHAR(255) NOT NULL, descripcion VARCHAR(255) DEFAULT NULL, icono VARCHAR(255) DEFAULT NULL, creado_en DATETIME NOT NULL --(DC2Type:datetime_immutable))');
        \$this->addSql('CREATE TABLE musica_cancion (id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL, genero_id INTEGER DEFAULT NULL, titulo VARCHAR(255) NOT NULL, artista VARCHAR(255) DEFAULT NULL, album VARCHAR(255) DEFAULT NULL, descripcion CLOB DEFAULT NULL, imagen VARCHAR(255) DEFAULT NULL, url VARCHAR(255) DEFAULT NULL, es_publico BOOLEAN NOT NULL, anio INTEGER DEFAULT NULL, duracion INTEGER DEFAULT NULL, creado_en DATETIME NOT NULL --(DC2Type:datetime_immutable), actualizado_en DATETIME DEFAULT NULL --(DC2Type:datetime_immutable), CONSTRAINT FK_GENERO_ID FOREIGN KEY (genero_id) REFERENCES musica_genero (id) NOT DEFERRABLE INITIALLY IMMEDIATE)');
        \$this->addSql('CREATE TABLE musica_playlist (id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL, nombre VARCHAR(255) NOT NULL, descripcion CLOB DEFAULT NULL, imagen VARCHAR(255) DEFAULT NULL, creador_id VARCHAR(255) NOT NULL, creador_nombre VARCHAR(255) DEFAULT NULL, es_publica BOOLEAN NOT NULL, creado_en DATETIME NOT NULL --(DC2Type:datetime_immutable), actualizado_en DATETIME DEFAULT NULL --(DC2Type:datetime_immutable))');
        \$this->addSql('CREATE TABLE musica_playlist_cancion (playlist_id INTEGER NOT NULL, cancion_id INTEGER NOT NULL, PRIMARY KEY(playlist_id, cancion_id), CONSTRAINT FK_PLAYLIST_ID FOREIGN KEY (playlist_id) REFERENCES musica_playlist (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE, CONSTRAINT FK_CANCION_ID FOREIGN KEY (cancion_id) REFERENCES musica_cancion (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE)');
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
            $process->setTimeout(300);
            
            $io->note('Ejecutando migración para eliminar tablas...');
            $process->run(function ($type, $buffer) use ($io) {
                $io->write($buffer);
            });
            
            if ($process->isSuccessful()) {
                $io->success('Las tablas del módulo Música han sido eliminadas correctamente.');
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