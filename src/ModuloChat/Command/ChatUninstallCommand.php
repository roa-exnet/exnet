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
use Symfony\Component\DependencyInjection\ParameterBag\ParameterBagInterface;
use Symfony\Component\Filesystem\Filesystem;

#[AsCommand(
    name: 'chat:uninstall',
    description: 'Desinstala completamente el módulo de chat'
)]
class ChatUninstallCommand extends Command
{
    private EntityManagerInterface $entityManager;
    private string $projectDir;
    private Filesystem $filesystem;

    public function __construct(
        EntityManagerInterface $entityManager,
        ParameterBagInterface $parameterBag
    ) {
        $this->entityManager = $entityManager;
        $this->projectDir = $parameterBag->get('kernel.project_dir');
        $this->filesystem = new Filesystem();
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('keep-tables', null, InputOption::VALUE_NONE, 'Mantener las tablas en la base de datos')
            ->addOption('keep-config', null, InputOption::VALUE_NONE, 'Mantener los archivos de configuración')
            ->addOption('force', 'f', InputOption::VALUE_NONE, 'Forzar la desinstalación sin confirmaciones')
            ->setHelp(<<<EOT
                El comando <info>chat:uninstall</info> realiza lo siguiente:

                1. Elimina las configuraciones del módulo de chat de los archivos:
                   - services.yaml
                   - routes.yaml
                   - twig.yaml
                   - doctrine.yaml
                2. Elimina el registro del módulo en la base de datos
                3. Elimina los elementos de menú asociados
                4. Elimina las tablas de la base de datos relacionadas con el chat
                5. Limpia la caché del sistema

                Opciones:
                  --keep-tables     No eliminar las tablas de la base de datos
                  --keep-config     No eliminar las configuraciones de los archivos
                  --force, -f       Forzar la desinstalación sin confirmaciones

                Ejemplo de uso:

                <info>php bin/console chat:uninstall</info>
                <info>php bin/console chat:uninstall --keep-tables</info>
                <info>php bin/console chat:uninstall --force</info>
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
        $path = $this->projectDir . '/config/services.yaml';
        
        if (!$this->filesystem->exists($path)) {
            $io->note('No se encontró el archivo services.yaml.');
            return;
        }
        
        $content = file_get_contents($path);
        
        // Buscar inicio y fin de la sección del módulo de Chat
        $startPattern = '/^#START -+\s+ModuloChat\s+-+/m';
        $endPattern = '/^#END -+\s+ModuloChat\s+-+/m';
        
        // Intentar encontrar sección delimitada por comentarios
        if (preg_match($startPattern, $content) && preg_match($endPattern, $content)) {
            // Remover toda la sección del módulo incluyendo los delimitadores
            $content = preg_replace('/' . $startPattern . '.*?' . $endPattern . '/s', '', $content);
            
            file_put_contents($path, $content);
            $io->success('Sección completa del módulo Chat eliminada de services.yaml.');
            return;
        }
        
        // Si no se encontraron delimitadores, buscar servicios individuales
        $patterns = [
            // Controladores
            '/\n\s*App\\\\ModuloChat\\\\Controller\\\\:.*?\n\s*resource:.*?ModuloChat\/Controller\/.*?\n(\s*tags:.*?\n)?/s',
            
            // Comandos
            '/\n\s*App\\\\ModuloChat\\\\Command\\\\:.*?\n\s*resource:.*?ModuloChat\/Command\/.*?\n(\s*tags:.*?\n)?/s',
            
            // Servicios
            '/\n\s*App\\\\ModuloChat\\\\Service\\\\:.*?\n\s*resource:.*?ModuloChat\/Service\/.*?\n(\s*autowire:.*?\n\s*autoconfigure:.*?\n\s*public:.*?\n)?/s',
            
            // Cualquier otra configuración relacionada con ModuloChat
            '/\n\s*App\\\\ModuloChat\\\\.*?:.*?\n\s*resource:.*?ModuloChat\/.*?\n(\s*.*?\n)*?(\s*public:.*?\n)?/s'
        ];
        
        $modified = false;
        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $content)) {
                $content = preg_replace($pattern, "\n", $content);
                $modified = true;
            }
        }
        
        // Patrón específico para las entradas individuales como las que se ven en la captura
        $individualPatterns = [
            '/\n\s*App\\\\ModuloChat\\\\Controller\\\\:.*?\n/s',
            '/\n\s*App\\\\ModuloChat\\\\Command\\\\:.*?\n/s',
            '/\n\s*App\\\\ModuloChat\\\\Service\\\\:.*?\n/s',
            '/\n\s*resource:\s*\'.*?\/ModuloChat\/.*?\'\n/s',
            '/\n\s*tags:\s*\[\'console\.command\'\]\n/s',
            '/\n\s*tags:\s*\[\'controller\.service_arguments\'\]\n/s',
            '/\n\s*autowire:\s*true\n\s*autoconfigure:\s*true\n\s*public:\s*true\n/s'
        ];
        
        foreach ($individualPatterns as $pattern) {
            if (preg_match($pattern, $content)) {
                $content = preg_replace($pattern, "\n", $content);
                $modified = true;
            }
        }
        
        // Eliminar líneas vacías consecutivas
        $content = preg_replace('/\n{3,}/', "\n\n", $content);
        
        if ($modified) {
            file_put_contents($path, $content);
            $io->success('Configuraciones del módulo Chat eliminadas de services.yaml.');
        } else {
            $io->note('No se encontraron configuraciones específicas del módulo Chat en services.yaml.');
        }
    }

    private function removeRoutesConfig(SymfonyStyle $io): void
    {
        $path = $this->projectDir . '/config/routes.yaml';
        
        if (!$this->filesystem->exists($path)) {
            $io->note('No se encontró el archivo routes.yaml.');
            return;
        }
        
        $content = file_get_contents($path);
        
        // Buscar y eliminar directamente entradas relacionadas con ModuloChat
        $patterns = [
            '/\n\nmodulo_chat_controllers:\n\s+resource:\n\s+path: \.\.\/src\/ModuloChat\/Controller\/\n\s+namespace: App\\\\ModuloChat\\\\Controller\n\s+type: attribute/s',
            '/\n\nmodulo_chat_controllers:.*?type: attribute/s'
        ];
        
        $modified = false;
        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $content)) {
                $content = preg_replace($pattern, '', $content);
                $modified = true;
            }
        }
        
        if ($modified) {
            file_put_contents($path, $content);
            $io->success('Configuración de rutas del módulo Chat eliminada de routes.yaml.');
        } else {
            $io->note('No se encontraron configuraciones de rutas específicas del módulo Chat en routes.yaml.');
        }
    }

    private function removeTwigConfig(SymfonyStyle $io): void
    {
        $path = $this->projectDir . '/config/packages/twig.yaml';
        
        if (!$this->filesystem->exists($path)) {
            $io->note('No se encontró el archivo twig.yaml.');
            return;
        }
        
        $content = file_get_contents($path);
        
        // Patrones para eliminar referencias a ModuloChat en twig.yaml
        $patterns = [
            "/\n\s+'%kernel\.project_dir%\/src\/ModuloChat\/templates': ModuloChat/",
            "/\n\s+\"%kernel\.project_dir%\/src\/ModuloChat\/templates\": ModuloChat/"
        ];
        
        $modified = false;
        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $content)) {
                $content = preg_replace($pattern, '', $content);
                $modified = true;
            }
        }
        
        if ($modified) {
            file_put_contents($path, $content);
            $io->success('Configuración de plantillas del módulo Chat eliminada de twig.yaml.');
        } else {
            $io->note('No se encontraron configuraciones de plantillas del módulo Chat en twig.yaml.');
        }
    }

    private function removeDoctrineConfig(SymfonyStyle $io): void
    {
        $path = $this->projectDir . '/config/packages/doctrine.yaml';
        
        if (!$this->filesystem->exists($path)) {
            $io->note('No se encontró el archivo doctrine.yaml.');
            return;
        }
        
        $content = file_get_contents($path);
        
        // Patrón para eliminar la configuración de ModuloChat en doctrine.yaml
        $pattern = '/\n\s+ModuloChat:\n\s+type: attribute\n\s+is_bundle: false\n\s+dir:.*?\n\s+prefix:.*?\n\s+alias: ModuloChat/s';
        
        if (preg_match($pattern, $content)) {
            $newContent = preg_replace($pattern, '', $content);
            file_put_contents($path, $newContent);
            $io->success('Configuración de entidades del módulo Chat eliminada de doctrine.yaml.');
        } else {
            $io->note('No se encontró configuración de entidades del módulo Chat en doctrine.yaml.');
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

            $mainMenuItem = $menuRepository->findOneBy(['nombre' => 'Chat']);
            
            if ($mainMenuItem) {
                $mainMenuId = $mainMenuItem->getId();
                
                // Buscar y eliminar submenús si existen
                $subMenuItems = $menuRepository->findBy(['parentId' => $mainMenuId]);
                foreach ($subMenuItems as $subMenuItem) {
                    $this->entityManager->remove($subMenuItem);
                }
                
                if (count($subMenuItems) > 0) {
                    $io->success('Submenús del módulo Chat eliminados de la base de datos.');
                }
                
                $this->entityManager->remove($mainMenuItem);
                $this->entityManager->flush();
                $io->success('Elemento de menú "Chat" eliminado de la base de datos.');
            } else {
                $io->note('No se encontró el elemento de menú para el módulo Chat.');
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
            
            // Lista de tablas a eliminar en orden para evitar problemas de dependencias
            $tables = [
                'chat_message',
                'chat_participant',
                'chat'
            ];
            
            foreach ($tables as $table) {
                try {
                    $connection->executeStatement("DROP TABLE IF EXISTS $table");
                    $io->success("Tabla $table eliminada.");
                } catch (\Exception $e) {
                    $io->error("Error al eliminar la tabla $table: " . $e->getMessage());
                }
            }
        } catch (\Exception $e) {
            $io->error('Error al eliminar las tablas: ' . $e->getMessage());
        }
    }
    
    private function clearCache(SymfonyStyle $io): void
    {
        try {
            $process = new Process(['php', 'bin/console', 'cache:clear']);
            $process->setWorkingDirectory($this->projectDir);
            $process->setTimeout(300);
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