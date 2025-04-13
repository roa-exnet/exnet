<?php

namespace App\ModuloCore\Command;

use App\ModuloCore\Entity\Modulo;
use App\ModuloCore\Entity\MenuElement;
use App\ModuloCore\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Question\ConfirmationQuestion;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Process\Process;

#[AsCommand(
    name: 'exnet:uninstall',
    description: 'Desinstala el ModuloCore y limpia la base de datos'
)]
class UninstallCommand extends Command
{
    private EntityManagerInterface $entityManager;
    private Filesystem $filesystem;
    private string $projectDir;

    public function __construct(
        EntityManagerInterface $entityManager,
        string $projectDir = null
    ) {
        $this->entityManager = $entityManager;
        $this->filesystem = new Filesystem();
        $this->projectDir = $projectDir ?? dirname(__DIR__, 3);
        
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->setHelp(<<<EOT
                El comando <info>exnet:uninstall</info> desinstala el ModuloCore y limpia la base de datos:

                1. Elimina todos los registros del módulo Core
                2. Elimina los elementos de menú asociados
                3. Opcionalmente, puede limpiar completamente la base de datos
                4. Opcionalmente, puede eliminar archivos de caché y logs

                Este comando es útil para realizar una reinstalación limpia del sistema o para resolver problemas.
                
                <comment>¡ADVERTENCIA! Este comando eliminará datos. Asegúrese de tener una copia de seguridad si los datos son importantes.</comment>
                EOT
            )
            ->addOption('full', 'f', InputOption::VALUE_NONE, 'Elimina completamente todas las tablas de la base de datos')
            ->addOption('clean-files', 'c', InputOption::VALUE_NONE, 'Limpia archivos de caché, logs y temporales')
            ->addOption('force', null, InputOption::VALUE_NONE, 'No pedir confirmación');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $io->title('Desinstalación de ModuloCore');

        $io->warning([
            'Está a punto de desinstalar el ModuloCore.',
            'Esta acción eliminará datos de la base de datos y puede afectar a todo el sistema.',
            'Asegúrese de tener una copia de seguridad si los datos son importantes.'
        ]);

        $fullUninstall = $input->getOption('full');
        $cleanFiles = $input->getOption('clean-files');

        if ($fullUninstall) {
            $io->caution('Ha seleccionado la desinstalación completa. Esto eliminará TODAS las tablas de la base de datos.');
        }

        if ($cleanFiles) {
            $io->caution('Ha seleccionado limpiar archivos. Esto eliminará los archivos de caché, logs y temporales.');
        }

        if (!$input->getOption('force')) {
            $helper = $this->getHelper('question');
            $confirmationText = $fullUninstall ? 
                '¿Está REALMENTE seguro de que desea proceder con la desinstalación completa? Esta acción NO SE PUEDE DESHACER [s/N]: ' :
                '¿Está seguro de que desea proceder con la desinstalación? [s/N]: ';
            
            $question = new ConfirmationQuestion($confirmationText, false);
            
            if (!$helper->ask($input, $output, $question)) {
                $io->note('Operación cancelada por el usuario.');
                return Command::SUCCESS;
            }
        }

        try {
            $connection = $this->entityManager->getConnection();
            if (!$connection->isConnected()) {
                $connection->connect();
            }
            $io->note('Conexión a la base de datos establecida.');
        } catch (\Exception $e) {
            $io->error(['Error al conectar con la base de datos:', $e->getMessage()]);
            return Command::FAILURE;
        }

        if ($fullUninstall) {
            if (!$this->dropAllTables($io)) {
                return Command::FAILURE;
            }
        } else {
            if (!$this->uninstallCoreModule($io)) {
                return Command::FAILURE;
            }
        }

        if ($cleanFiles) {
            $this->cleanFiles($io);
        }

        $io->success([
            'Desinstalación completada con éxito.',
            $fullUninstall 
                ? 'Todas las tablas de la base de datos han sido eliminadas.' 
                : 'El ModuloCore ha sido desinstalado.',
            'Para reinstalar, ejecute: php bin/console exnet:setup'
        ]);

        return Command::SUCCESS;
    }

    private function uninstallCoreModule(SymfonyStyle $io): bool
    {
        $io->section('Desinstalando ModuloCore');

        try {
            $io->note('Eliminando elementos de menú...');
            $coreModule = $this->entityManager->getRepository(Modulo::class)->findOneBy(['nombre' => 'Core']);
            
            if ($coreModule) {
                $menuElements = $coreModule->getMenuElements();
                foreach ($menuElements as $menuElement) {
                    $coreModule->removeMenuElement($menuElement);
                    $this->entityManager->remove($menuElement);
                }
                
                $this->entityManager->flush();
                $io->note('Elementos de menú eliminados: ' . $menuElements->count());
                
                $this->entityManager->remove($coreModule);
                $this->entityManager->flush();
                $io->note('Módulo Core eliminado de la base de datos.');
            } else {
                $io->note('No se encontró el módulo Core en la base de datos.');
                
                $menuElements = $this->entityManager->getRepository(MenuElement::class)->findAll();
                foreach ($menuElements as $menuElement) {
                    $this->entityManager->remove($menuElement);
                }
                $this->entityManager->flush();
                $io->note('Elementos de menú eliminados: ' . count($menuElements));
            }

            return true;
        } catch (\Exception $e) {
            $io->error(['Error al desinstalar el ModuloCore:', $e->getMessage()]);
            return false;
        }
    }

    private function dropAllTables(SymfonyStyle $io): bool
    {
        $io->section('Eliminando todas las tablas de la base de datos');

        try {
            $connection = $this->entityManager->getConnection();
            $platform = $connection->getDatabasePlatform();
            
            $tables = $connection->executeQuery("SELECT name FROM sqlite_master WHERE type='table' AND name NOT LIKE 'sqlite_%'")->fetchFirstColumn();
            
            if (empty($tables)) {
                $io->note('No se encontraron tablas para eliminar.');
                return true;
            }
            
            $io->note('Tablas encontradas para eliminar: ' . implode(', ', $tables));
            
            $connection->executeStatement('PRAGMA foreign_keys = OFF');
            
            foreach ($tables as $table) {
                $connection->executeStatement('DROP TABLE IF EXISTS ' . $connection->quoteIdentifier($table));
                $io->note('Tabla eliminada: ' . $table);
            }
            
            $connection->executeStatement('PRAGMA foreign_keys = ON');
            
            $io->success('Todas las tablas fueron eliminadas con éxito.');
            
            return true;
        } catch (\Exception $e) {
            $io->error(['Error al eliminar las tablas:', $e->getMessage()]);
            return false;
        }
    }

    private function cleanFiles(SymfonyStyle $io): bool
    {
        $io->section('Limpiando archivos de caché y logs');

        $directories = [
            $this->projectDir . '/var/cache',
            $this->projectDir . '/var/log'
        ];

        foreach ($directories as $dir) {
            if (is_dir($dir)) {
                $io->note('Limpiando directorio: ' . $dir);
                
                try {
                    if (strpos($dir, '/cache') !== false) {
                        $process = new Process(['php', 'bin/console', 'cache:clear']);
                        $process->setWorkingDirectory($this->projectDir);
                        $process->run();
                        
                        if ($process->isSuccessful()) {
                            $io->note('Caché limpiada mediante comando cache:clear');
                        } else {
                            $io->warning('No se pudo limpiar la caché mediante comando. Intentando limpieza manual...');
                            $this->recursiveRemoveDirectory($dir, true, $io);
                        }
                    } else {
                        $this->recursiveRemoveDirectory($dir, true, $io);
                    }
                } catch (\Exception $e) {
                    $io->warning('Error al limpiar ' . $dir . ': ' . $e->getMessage());
                }
            }
        }

        $io->success('Limpieza de archivos completada.');
        return true;
    }

    private function recursiveRemoveDirectory(string $directory, bool $preserveRoot = true, SymfonyStyle $io = null): void
    {
        if (!is_dir($directory)) {
            return;
        }

        $items = new \FilesystemIterator($directory);
        foreach ($items as $item) {
            if ($item->isDir() && !$item->isLink()) {
                $this->recursiveRemoveDirectory($item->getPathname(), false, $io);
            } else {
                try {
                    unlink($item->getPathname());
                } catch (\Exception $e) {
                    if ($io) {
                        $io->warning('No se pudo eliminar: ' . $item->getPathname() . ' - ' . $e->getMessage());
                    }
                }
            }
        }

        if (!$preserveRoot) {
            try {
                rmdir($directory);
            } catch (\Exception $e) {
                if ($io) {
                    $io->warning('No se pudo eliminar directorio: ' . $directory . ' - ' . $e->getMessage());
                }
            }
        }
    }
}