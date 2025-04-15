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
                $this->dropTables($io);
            }
        } else {
            $io->note('Se ha omitido la eliminación de tablas según las opciones seleccionadas.');
        }

        $io->success('El módulo de chat ha sido desinstalado completamente.');

        return Command::SUCCESS;
    }

    private function removeServicesConfig(SymfonyStyle $io): void
    {
        $servicesYamlPath = 'config/services.yaml';
        $servicesContent = file_get_contents($servicesYamlPath);

        // Patrón para eliminar la sección añadida por ChatSetupCommand
        $pattern = '/\n# ----------- modulochat -------.*?# ----------- modulochat -------/s';

        if (preg_match($pattern, $servicesContent)) {
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

        // Patrón para eliminar la sección añadida por ChatSetupCommand
        $pattern = '/\n# ----------- modulochat -------.*?# ----------- modulochat -------/s';

        if (preg_match($pattern, $routesContent)) {
            $newContent = preg_replace($pattern, '', $routesContent);
            file_put_contents($routesYamlPath, $newContent);
            $io->success('Configuración del módulo de chat eliminada de routes.yaml.');
        } else {
            $io->note('No se encontró configuración para eliminar en routes.yaml.');
        }
    }

    private function removeTwigConfig(SymfonyStyle $io): void
    {
        $twigYamlPath = 'config/packages/twig.yaml';
        $twigContent = file_get_contents($twigYamlPath);

        // Patrón para eliminar la sección añadida por ChatSetupCommand
        $pattern = '/\n\s+# ----------- modulochat -------\n\s+\'%kernel\.project_dir%\/src\/ModuloChat\/templates\': ModuloChat\n\s+# ----------- modulochat -------/s';

        if (preg_match($pattern, $twigContent)) {
            $newContent = preg_replace($pattern, '', $twigContent);
            file_put_contents($twigYamlPath, $newContent);
            $io->success('Configuración del módulo de chat eliminada de twig.yaml.');
        } else {
            $io->note('No se encontró configuración para eliminar en twig.yaml.');
        }
    }

    private function removeDoctrineConfig(SymfonyStyle $io): void
    {
        $doctrineYamlPath = 'config/packages/doctrine.yaml';
        $doctrineContent = file_get_contents($doctrineYamlPath);

        // Patrón para eliminar la sección añadida por ChatSetupCommand
        $pattern = '/\n\s+# ----------- modulochat -------\n\s+ModuloChat:\n\s+type: attribute\n\s+is_bundle: false\n\s+dir: \'%kernel\.project_dir%\/src\/ModuloChat\/Entity\'\n\s+prefix: \'App\\\\ModuloChat\\\\Entity\'\n\s+alias: ModuloChat\n\s+# ----------- modulochat -------/s';

        if (preg_match($pattern, $doctrineContent)) {
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
}