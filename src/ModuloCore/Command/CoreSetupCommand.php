<?php

namespace App\ModuloCore\Command;

use App\ModuloCore\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Question\Question;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Process\Process;

#[AsCommand(
    name: 'modulocore:setup',
    description: 'Configura la base de datos SQLite: crea la carpeta, genera/ejecuta migraciones y configura usuarios.'
)]
class CoreSetupCommand extends Command
{
    private EntityManagerInterface $entityManager;
    private UserPasswordHasherInterface $passwordHasher;

    public function __construct(
        EntityManagerInterface $entityManager,
        UserPasswordHasherInterface $passwordHasher
    ) {
        $this->entityManager = $entityManager;
        $this->passwordHasher = $passwordHasher;
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->setHelp(<<<EOT
                El comando <info>modulocore:setup</info> realiza los siguientes pasos automáticamente:

                1. Crea la carpeta <info>var/data</info> si no existe.
                2. Crea la base de datos SQLite.
                3. Genera una nueva migración de Doctrine.
                4. Ejecuta la migración para aplicar cambios en la base de datos.
                5. Si se utiliza la opción --admin, crea un usuario administrador.

                Ejemplo de uso:

                <info>php bin/console modulocore:setup</info>
                <info>php bin/console modulocore:setup --admin</info>
                EOT
            )
            ->addOption('admin', null, InputOption::VALUE_NONE, 'Crear un usuario administrador');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $dbPath = 'var/data';
        if (!is_dir($dbPath)) {
            mkdir($dbPath, 0777, true);
            $io->success("Carpeta '$dbPath' creada.");
        } else {
            $io->note("La carpeta '$dbPath' ya existe.");
        }

        $process = new Process(['php', 'bin/console', 'doctrine:database:create']);
        $process->run();
        if ($process->isSuccessful()) {
            $io->success('Base de datos creada.');
        } else {
            if (str_contains($process->getErrorOutput(), 'already exists')) {
                $io->note('La base de datos ya existe.');
            } else {
                $io->error('Error al crear la base de datos: ' . $process->getErrorOutput());
                return Command::FAILURE;
            }
        }

        $process = new Process(['php', 'bin/console', 'make:migration']);
        $process->run();
        if ($process->isSuccessful()) {
            $io->success('Migración generada.');
        } else {
            $io->error('Error al generar la migración: ' . $process->getErrorOutput());
            return Command::FAILURE;
        }

        $process = new Process(['php', 'bin/console', 'doctrine:migrations:migrate', '--no-interaction']);
        $process->run();
        if ($process->isSuccessful()) {
            $io->success('Migración ejecutada.');
        } else {
            $io->error('Error al ejecutar la migración: ' . $process->getErrorOutput());
            return Command::FAILURE;
        }

        $userCount = $this->entityManager->getRepository(User::class)->count([]);
        $io->info("Número de usuarios en la base de datos: $userCount");
        
        if ($input->getOption('admin')) {
            $this->createAdminUser($io, $input, $output);
        }

        return Command::SUCCESS;
    }

    private function createAdminUser(SymfonyStyle $io, InputInterface $input, OutputInterface $output): void
    {
        $io->section('Creación de usuario administrador');
        
        $email = $io->ask('Email del administrador', 'admin@example.com', function ($email) {
            if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                throw new \RuntimeException('El email no es válido.');
            }
            
            $existingUser = $this->entityManager->getRepository(User::class)->findOneBy(['email' => $email]);
            if ($existingUser) {
                throw new \RuntimeException('Este email ya está en uso.');
            }
            
            return $email;
        });
        
        $nombre = $io->ask('Nombre', 'Admin');
        $apellidos = $io->ask('Apellidos', 'Usuario');
        
        $helper = $this->getHelper('question');
        $question = new Question('Contraseña (no se mostrará): ');
        $question->setHidden(true);
        $question->setHiddenFallback(false);
        $password = $helper->ask($input, $output, $question);
        
        $user = new User();
        $user->setEmail($email);
        $user->setNombre($nombre);
        $user->setApellidos($apellidos);
        $user->setRoles(['ROLE_ADMIN']);
        
        $hashedPassword = $this->passwordHasher->hashPassword($user, $password);
        $user->setPassword($hashedPassword);
        
        $this->entityManager->persist($user);
        $this->entityManager->flush();
        
        $io->success("Usuario administrador creado con éxito: $email");
    }
}