<?php

namespace App\ModuloChat\Command;

use App\ModuloCore\Entity\Modulo;
use App\ModuloCore\Entity\MenuElement;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
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
                4. Elimina las tablas de la base de datos directamente mediante SQL
                5. Limpia la caché del sistema

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

        if ($force) {
            $io->note('La opción --force implica confirmar automáticamente todas las preguntas.');
        }

        // Confirmación si no se usa --force
        if (!$force && !$io->confirm('¿Estás seguro de que deseas desinstalar completamente el módulo de chat?', false)) {
            $io->warning('Operación cancelada por el usuario.');
            return Command::SUCCESS;
        }

        $io->section('Limpiando caché de metadatos de Doctrine');
        $this->clearDoctrineCache($io);

        $io->section('Desactivando el módulo de chat');
        $this->deactivateModule($io);

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
        $this->removeMenuItems($io);
        $this->removeModuleFromDatabase($io);
        $this->cleanOrphanMenuElementModuloRecords($io);

        // Eliminar tablas de la base de datos
        if (!$keepTables) {
            $io->section('Eliminando tablas de la base de datos');

            if (!$force && !$io->confirm('¿Estás seguro de que deseas eliminar todas las tablas relacionadas con el chat? Esta acción es irreversible y eliminará todos los datos.', false)) {
                $io->warning('Se ha omitido la eliminación de tablas por elección del usuario.');
            } else {
                $this->dropTables($io);
            }
        } else {
            $io->note('Se ha omitido la eliminación de tablas según las opciones seleccionadas.');
        }

        $io->section('Limpiando caché');
        $this->clearCache($io);

        $io->success('El módulo de chat ha sido desinstalado completamente.');

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
            $chatModule = $moduloRepository->findOneBy(['nombre' => 'Chat']);
            
            if ($chatModule) {
                $chatModule->setEstado(false);
                $chatModule->setUninstallDate(new \DateTimeImmutable());
                $this->entityManager->flush();
                $io->success('Módulo de chat desactivado correctamente.');
            } else {
                $io->note('No se encontró el módulo de chat para desactivar.');
            }
        } catch (\Exception $e) {
            $io->error('Error al desactivar el módulo de chat: ' . $e->getMessage() . "\nStack trace: " . $e->getTraceAsString());
        }
    }

    private function removeServicesConfig(SymfonyStyle $io): void
    {
        $servicesYamlPath = 'config/services.yaml';
        $servicesContent = file_get_contents($servicesYamlPath);

        // Patrón para eliminar la sección añadida por ChatSetupCommand
        $patterns = [
            '/\n# ----------- modulochat -------.*?# ----------- modulochat -------/s',
            '/#START\s+----+\s+ModuloChat.*?\n.*?#END\s+----+\s+ModuloChat.*?\n/s'
        ];
        
        $removed = false;
        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $servicesContent)) {
                $servicesContent = preg_replace($pattern, '', $servicesContent);
                $removed = true;
            }
        }
        
        if ($removed) {
            file_put_contents($servicesYamlPath, $servicesContent);
            $io->success('Configuración del módulo de chat eliminada de services.yaml.');
        } else {
            $io->note('No se encontró configuración para eliminar en services.yaml.');
        }
    }

    private function removeRoutesConfig(SymfonyStyle $io): void
    {
        $routesYamlPath = 'config/routes.yaml';
        $routesContent = file_get_contents($routesYamlPath);

        // Patrones para eliminar la sección añadida por ChatSetupCommand
        $patterns = [
            '/\n# ----------- modulochat -------.*?# ----------- modulochat -------/s',
            '/\n\nmodulo_chat_controllers:\n\s+resource:\n\s+path: \.\.\/src\/ModuloChat\/Controller\/\n\s+namespace: App\\\\ModuloChat\\\\Controller\n\s+type: attribute/s',
            '/\n\nmodulo_chat_controllers:.*?type: attribute/s',
            '/\n\n# modulo_chat_controllers:.*?# type: attribute/s'
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
            $io->success('Configuración del módulo de chat eliminada de routes.yaml.');
        } else {
            $io->note('No se encontró configuración para eliminar en routes.yaml.');
        }
    }

    private function removeTwigConfig(SymfonyStyle $io): void
    {
        $twigYamlPath = 'config/packages/twig.yaml';
        $twigContent = file_get_contents($twigYamlPath);

        // Patrones para eliminar la sección añadida por ChatSetupCommand
        $patterns = [
            "/\n\s+# ----------- modulochat -------\n\s+'%kernel\.project_dir%\/src\/ModuloChat\/templates': ModuloChat\n\s+# ----------- modulochat -------/s",
            "/\n\s+'%kernel\.project_dir%\/src\/ModuloChat\/templates': ModuloChat/",
            "/\n\s+\"%kernel\.project_dir%\/src\/ModuloChat\/templates\": ModuloChat/",
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

        // Patrones para eliminar la sección añadida por ChatSetupCommand
        $patterns = [
            "/\n\s+# ----------- modulochat -------\n\s+ModuloChat:.*?# ----------- modulochat -------/s",
            "/\n\s+ModuloChat:\n\s+type: attribute\n\s+is_bundle: false\n\s+dir: '%kernel\.project_dir%\/src\/ModuloChat\/Entity'\n\s+prefix: 'App\\\\ModuloChat\\\\Entity'\n\s+alias: ModuloChat/s"
        ];

        $removed = false;
        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $doctrineContent)) {
                $doctrineContent = preg_replace($pattern, '', $doctrineContent);
                $removed = true;
            }
        }

        if ($removed) {
            file_put_contents($doctrineYamlPath, $doctrineContent);
            $io->success('Configuración del módulo de chat eliminada de doctrine.yaml.');
        } else {
            $io->note('No se encontró configuración para eliminar en doctrine.yaml.');
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
            
            $chatModule = $moduloRepository->findOneBy(['nombre' => 'Chat']);
            if ($chatModule) {
                $moduloId = $chatModule->getId();

                $connection = $this->entityManager->getConnection();
                $connection->executeStatement('DELETE FROM menu_element_modulo WHERE modulo_id = :moduloId', ['moduloId' => $moduloId]);
                $io->success('Registros asociados en menu_element_modulo eliminados.');
            } else {
                $io->note('No se encontró el módulo Chat para eliminar registros de menu_element_modulo.');
            }

            $chatMenuItem = $menuRepository->findOneBy(['nombre' => 'Chat']);
            
            if ($chatMenuItem) {
                $mainMenuId = $chatMenuItem->getId();
                
                $subMenuItems = $menuRepository->findBy(['parentId' => $mainMenuId]);
                
                if (!empty($subMenuItems)) {
                    foreach ($subMenuItems as $subMenuItem) {
                        $this->entityManager->remove($subMenuItem);
                    }
                    $io->success('Submenús del módulo Chat eliminados de la base de datos.');
                } else {
                    $io->note('No se encontraron submenús para el módulo Chat.');
                }
                
                $this->entityManager->remove($chatMenuItem);
                $this->entityManager->flush();
                $io->success('Elemento de menú principal "Chat" eliminado de la base de datos.');
            } else {
                $io->note('No se encontraron elementos de menú para el módulo Chat.');
            }
        } catch (\Exception $e) {
            $io->error('Error al eliminar los elementos de menú: ' . $e->getMessage() . "\nStack trace: " . $e->getTraceAsString());
        }
    }

    private function removeModuleFromDatabase(SymfonyStyle $io): void
    {
        try {
            $moduloRepository = $this->entityManager->getRepository(Modulo::class);
            $chatModule = $moduloRepository->findOneBy(['nombre' => 'Chat']);
            
            if ($chatModule) {
                $menuElements = $chatModule->getMenuElements();
                if (!empty($menuElements)) {
                    foreach ($menuElements as $menuElement) {
                        $chatModule->removeMenuElement($menuElement);
                    }
                    $this->entityManager->persist($chatModule);
                    $this->entityManager->flush();
                    $io->note('Relaciones del módulo Chat con elementos de menú eliminadas.');
                }

                $this->entityManager->remove($chatModule);
                $this->entityManager->flush();
                $io->success('Módulo Chat eliminado de la base de datos.');
            } else {
                $io->note('No se encontró el módulo Chat en la base de datos.');
            }
        } catch (\Exception $e) {
            $io->error('Error al eliminar el módulo de la base de datos: ' . $e->getMessage() . "\nStack trace: " . $e->getTraceAsString());
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

    private function dropTables(SymfonyStyle $io): void
    {
        try {
            $connection = $this->entityManager->getConnection();
            
            // Drop tables in the correct order to avoid dependency issues
            $connection->executeStatement('DROP TABLE IF EXISTS chat_message');
            $io->success('Tabla chat_message eliminada.');

            $connection->executeStatement('DROP TABLE IF EXISTS chat_participant');
            $io->success('Tabla chat_participant eliminada.');

            $connection->executeStatement('DROP TABLE IF EXISTS chat');
            $io->success('Tabla chat eliminada.');

        } catch (\Exception $e) {
            $io->error('Error al eliminar las tablas: ' . $e->getMessage());
            throw $e;
        }
    }
    
    private function clearCache(SymfonyStyle $io): void
    {
        try {
            $process = new Process(['php', 'bin/console', 'cache:clear']);
            $process->setTimeout(120);
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