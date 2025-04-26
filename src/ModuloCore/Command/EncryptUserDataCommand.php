<?php

namespace App\ModuloCore\Command;

use App\ModuloCore\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'exnet:encrypt-user-data',
    description: 'Encrypt existing user data'
)]
class EncryptUserDataCommand extends Command
{
    private EntityManagerInterface $entityManager;

    public function __construct(EntityManagerInterface $entityManager)
    {
        $this->entityManager = $entityManager;
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $userRepository = $this->entityManager->getRepository(User::class);
        $users = $userRepository->findAll();

        $io->progressStart(count($users));
        
        foreach ($users as $user) {
            // Al obtener y establecer los valores, se aplicará el cifrado
            $email = $user->getEmail();
            $nombre = $user->getNombre();
            $apellidos = $user->getApellidos();
            
            $user->setEmail($email);
            $user->setNombre($nombre);
            $user->setApellidos($apellidos);
            
            $this->entityManager->persist($user);
            $io->progressAdvance();
        }
        
        $this->entityManager->flush();
        $io->progressFinish();
        
        $io->success('Todos los datos de usuarios han sido cifrados correctamente.');
        
        return Command::SUCCESS;
    }
}