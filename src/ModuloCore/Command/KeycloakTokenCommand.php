<?php

namespace App\ModuloCore\Command;

use App\ModuloCore\Service\KeycloakTokenService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'keycloak:get-token',
    description: 'Obtiene un nuevo token de Keycloak y lo muestra en la consola',
)]
class KeycloakTokenCommand extends Command
{
    private KeycloakTokenService $keycloakTokenService;

    public function __construct(KeycloakTokenService $keycloakTokenService)
    {
        parent::__construct();
        $this->keycloakTokenService = $keycloakTokenService;
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        try {
            $token = $this->keycloakTokenService->getToken();
            $io->success("Token obtenido correctamente:\n$token");
        } catch (\Exception $e) {
            $io->error('Error al obtener el token: ' . $e->getMessage());
            return Command::FAILURE;
        }

        return Command::SUCCESS;
    }
}
