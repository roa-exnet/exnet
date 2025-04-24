<?php

namespace App\ModuloExplorador\Command;

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
use Symfony\Component\DependencyInjection\ParameterBag\ParameterBagInterface;
use Symfony\Component\Filesystem\Filesystem;

#[AsCommand(
    name: 'explorador:uninstall',
    description: 'Desinstala completamente el módulo explorador de archivos'
)]
class ExplorerUninstallCommand extends Command
{
    private EntityManagerInterface $entityManager;
    private string $projectDir;
    private string $workspaceDir;

    public function __construct(EntityManagerInterface $entityManager, ParameterBagInterface $parameterBag)
    {
        $this->entityManager = $entityManager;
        $this->projectDir = $parameterBag->get('kernel.project_dir');
        $this->workspaceDir = $this->projectDir . '/src/ModuloExplorador/Workspace';
        parent::__construct();
    }
    
    protected function configure(): void
    {
        $this
            ->addOption('keep-files', null, InputOption::VALUE_NONE, 'Mantener los archivos del workspace')
            ->addOption('keep-config', null, InputOption::VALUE_NONE, 'Mantener los archivos de configuración')
            ->addOption('force', 'f', InputOption::VALUE_NONE, 'Forzar la desinstalación sin confirmaciones')
            ->setHelp(<<<EOT
                El comando <info>explorador:uninstall</info> realiza lo siguiente:

                1. Elimina las configuraciones del módulo explorador de los archivos:
                   - services.yaml
                   - routes.yaml
                   - twig.yaml
                   - doctrine.yaml
                2. Elimina el registro del módulo en la base de datos
                3. Elimina los elementos de menú asociados al módulo
                4. Elimina el directorio de trabajo del explorador (workspace)
                5. Limpia la caché del sistema

                Opciones:
                  --keep-files      No eliminar los archivos del workspace
                  --keep-config     No eliminar las configuraciones de los archivos
                  --force, -f       Forzar la desinstalación sin confirmaciones

                Ejemplo de uso:

                <info>php bin/console explorador:uninstall</info>
                <info>php bin/console explorador:uninstall --keep-files</info>
                <info>php bin/console explorador:uninstall --force</info>
                EOT
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $io->title('Desinstalación del Módulo Explorador de Archivos');
        
        $keepFiles = $input->getOption('keep-files');
        $keepConfig = $input->getOption('keep-config');
        $force = $input->getOption('force');
        
        if ($force) {
            $io->note('La opción --force implica confirmar automáticamente todas las preguntas.');
        }
        
        if (!$force && !$io->confirm('¿Estás seguro de que deseas desinstalar completamente el módulo explorador?', false)) {
            $io->warning('Operación cancelada por el usuario.');
            return Command::SUCCESS;
        }
        
        $io->section('Limpiando caché de metadatos de Doctrine');
        $this->clearDoctrineCache($io);
        
        $io->section('Desactivando el módulo de explorador');
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
        
        if (!$keepFiles) {
            $io->section('Eliminando directorio de trabajo (workspace)');
            
            if (!$force && !$io->confirm('¿Estás seguro de que deseas eliminar todos los archivos del workspace? Esta acción es irreversible y eliminará todos los archivos.', false)) {
                $io->warning('Se ha omitido la eliminación de archivos por elección del usuario.');
            } else {
                $this->removeWorkspaceDirectory($io);
            }
        } else {
            $io->note('Se ha omitido la eliminación de archivos según las opciones seleccionadas.');
        }
        
        $io->section('Limpiando caché');
        $this->clearCache($io);
        
        $io->success('El módulo explorador ha sido desinstalado correctamente.');
        
        return Command::SUCCESS;
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
    
    private function deactivateModule(SymfonyStyle $io): void
    {
        try {
            $moduloRepository = $this->entityManager->getRepository(Modulo::class);
            $exploradorModule = $moduloRepository->findOneBy(['nombre' => 'Explorador']);
            
            if ($exploradorModule) {
                $exploradorModule->setEstado(false);
                $exploradorModule->setUninstallDate(new \DateTimeImmutable());
                $this->entityManager->flush();
                $io->success('Módulo explorador desactivado correctamente.');
            } else {
                $io->note('No se encontró el módulo explorador para desactivar.');
            }
        } catch (\Exception $e) {
            $io->error('Error al desactivar el módulo explorador: ' . $e->getMessage() . "\nStack trace: " . $e->getTraceAsString());
        }
    }
    
    private function removeServicesConfig(SymfonyStyle $io): void
    {
        $path = $this->projectDir . '/config/services.yaml';
        $content = file_get_contents($path);
        $pattern = '/^[ \t]*#START\s*-+\s*ModuloExplorador.*?^[ \t]*#END\s*-+\s*ModuloExplorador.*(?:\r?\n)?/sm';
        if (preg_match($pattern, $content)) {
            $newContent = preg_replace($pattern, '', $content);
            file_put_contents($path, $newContent);
            $io->success('Configuración del módulo explorador eliminada de services.yaml.');
        } else {
            $io->note('No se encontró configuración para eliminar en services.yaml.');
        }
    }
    
    private function removeRoutesConfig(SymfonyStyle $io): void
    {
        $routesYamlPath = $this->projectDir . '/config/routes.yaml';
        $routesContent = file_get_contents($routesYamlPath);
        
        $exactPattern = '/\n\nmodulo_explorador_controllers:\n\s+resource:\n\s+path: \.\.\/src\/ModuloExplorador\/Controller\/\n\s+namespace: App\\\\ModuloExplorador\\\\Controller\n\s+type: attribute/s';
        
        if (preg_match($exactPattern, $routesContent)) {
            $routesContent = preg_replace($exactPattern, '', $routesContent);
            file_put_contents($routesYamlPath, $routesContent);
            $io->success('Configuración de controllers del módulo explorador eliminada de routes.yaml.');
        } else {
            $alternativePattern = '/\n\nmodulo_explorador_controllers:.*?type: attribute/s';
            if (preg_match($alternativePattern, $routesContent)) {
                $routesContent = preg_replace($alternativePattern, '', $routesContent);
                file_put_contents($routesYamlPath, $routesContent);
                $io->success('Configuración de controllers del módulo explorador eliminada de routes.yaml.');
            } else {
                $commentedPattern = '/\n\n# modulo_explorador_controllers:.*?# type: attribute/s';
                if (preg_match($commentedPattern, $routesContent)) {
                    $routesContent = preg_replace($commentedPattern, '', $routesContent);
                    file_put_contents($routesYamlPath, $routesContent);
                    $io->success('Configuración comentada de controllers del módulo explorador eliminada de routes.yaml.');
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
            "/\n\s+'%kernel\.project_dir%\/src\/ModuloExplorador\/templates': ModuloExplorador/",
            "/\n\s+\"%kernel\.project_dir%\/src\/ModuloExplorador\/templates\": ModuloExplorador/",
            "/\n\s+# '%kernel\.project_dir%\/src\/ModuloExplorador\/templates':.*?DESACTIVADO/"
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
            $io->success('Configuración del módulo explorador eliminada de twig.yaml.');
        } else {
            $io->note('No se encontró configuración para eliminar en twig.yaml.');
        }
    }
    
    private function removeDoctrineConfig(SymfonyStyle $io): void
    {
        $doctrineYamlPath = $this->projectDir . '/config/packages/doctrine.yaml';
        $doctrineContent = file_get_contents($doctrineYamlPath);
        
        $pattern = '/\n\s+ModuloExplorador:\n\s+type: attribute\n\s+is_bundle: false\n\s+dir:.*?\n\s+prefix:.*?\n\s+alias: ModuloExplorador/s';
        
        if (preg_match($pattern, $doctrineContent, $matches)) {
            $newContent = preg_replace($pattern, '', $doctrineContent);
            file_put_contents($doctrineYamlPath, $newContent);
            $io->success('Configuración del módulo explorador eliminada de doctrine.yaml.');
        } else {
            $io->note('No se encontró configuración para eliminar en doctrine.yaml.');
        }
    }
    
    private function removeModuleFromDatabase(SymfonyStyle $io): void
    {
        try {
            $moduloRepository = $this->entityManager->getRepository(Modulo::class);
            $exploradorModule = $moduloRepository->findOneBy(['nombre' => 'Explorador']);
            
            if ($exploradorModule) {
                $menuElements = $exploradorModule->getMenuElements();
                if (!empty($menuElements)) {
                    foreach ($menuElements as $menuElement) {
                        $exploradorModule->removeMenuElement($menuElement);
                    }
                    $this->entityManager->persist($exploradorModule);
                    $this->entityManager->flush();
                    $io->note('Relaciones del módulo Explorador con elementos de menú eliminadas.');
                }

                $this->entityManager->remove($exploradorModule);
                $this->entityManager->flush();
                $io->success('Módulo Explorador eliminado de la base de datos.');
            } else {
                $io->note('No se encontró el módulo Explorador en la base de datos.');
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
            
            $exploradorModule = $moduloRepository->findOneBy(['nombre' => 'Explorador']);
            if ($exploradorModule) {
                $moduloId = $exploradorModule->getId();

                $connection = $this->entityManager->getConnection();
                $connection->executeStatement('DELETE FROM menu_element_modulo WHERE modulo_id = :moduloId', ['moduloId' => $moduloId]);
                $io->success('Registros asociados en menu_element_modulo eliminados.');
            } else {
                $io->note('No se encontró el módulo Explorador para eliminar registros de menu_element_modulo.');
            }

            $mainMenuItem = $menuRepository->findOneBy(['nombre' => 'Explorador']);
            
            if ($mainMenuItem) {
                $this->entityManager->remove($mainMenuItem);
                $this->entityManager->flush();
                $io->success('Elemento de menú "Explorador" eliminado de la base de datos.');
            } else {
                $io->note('No se encontró el elemento de menú para el módulo Explorador.');
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
    
    private function removeWorkspaceDirectory(SymfonyStyle $io): void
    {
        $filesystem = new Filesystem();
        
        if ($filesystem->exists($this->workspaceDir)) {
            try {
                $filesystem->remove($this->workspaceDir);
                $io->success("Se ha eliminado correctamente el directorio de trabajo: {$this->workspaceDir}");
            } catch (\Exception $e) {
                $io->error("Error al eliminar el directorio de trabajo: " . $e->getMessage());
            }
        } else {
            $io->note("El directorio de trabajo no existe: {$this->workspaceDir}");
        }
        
        // Revisar y eliminar enlaces simbólicos en public/css
        $cssExplorerPath = $this->projectDir . '/public/css/explorer';
        if ($filesystem->exists($cssExplorerPath)) {
            try {
                $filesystem->remove($cssExplorerPath);
                $io->success("Se ha eliminado correctamente el enlace simbólico de los assets: {$cssExplorerPath}");
            } catch (\Exception $e) {
                $io->error("Error al eliminar el enlace simbólico de los assets: " . $e->getMessage());
            }
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