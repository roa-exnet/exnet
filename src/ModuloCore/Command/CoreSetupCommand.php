<?php

namespace App\ModuloCore\Command;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Process\Process;

#[AsCommand(
    name: 'modulocore:setup',
    description: 'Configura la base de datos SQLite: crea la carpeta, genera y ejecuta la migración.'
)]
class CoreSetupCommand extends Command
{
    protected function configure(): void
    {
        $this->setHelp(<<<EOT
            El comando <info>modulocore:setup</info> realiza los siguientes pasos automáticamente:

            1. Crea la carpeta <info>var/data</info> si no existe.
            2. Crea la base de datos SQLite.
            3. Genera una nueva migración de Doctrine.
            4. Ejecuta la migración para aplicar cambios en la base de datos.

            Ejemplo de uso:

            <info>php bin/console modulocore:setup</info>
            EOT
        );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        // Crear carpeta si no existe
        $dbPath = 'var/data';
        if (!is_dir($dbPath)) {
            mkdir($dbPath, 0777, true);
            $io->success("Carpeta '$dbPath' creada.");
        } else {
            $io->note("La carpeta '$dbPath' ya existe.");
        }

        // Crear la base de datos
        $process = new Process(['php', 'bin/console', 'doctrine:database:create']);
        $process->run();
        $io->success('Base de datos creada.');

        // Generar la migración
        $process = new Process(['php', 'bin/console', 'make:migration']);
        $process->run();
        $io->success('Migración generada.');

        // Ejecutar la migración
        $process = new Process(['php', 'bin/console', 'doctrine:migrations:migrate', '--no-interaction']);
        $process->run();
        $io->success('Migración ejecutada.');

        return Command::SUCCESS;
    }
}
