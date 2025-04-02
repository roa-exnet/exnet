<?php

namespace App\ModuloMusica\Command;

use App\ModuloCore\Entity\Modulo;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Console\Question\ConfirmationQuestion;
use Symfony\Component\Process\Process;

#[AsCommand(
    name: 'musica:uninstall',
    description: 'Desinstala el módulo de música'
)]
class MusicaUninstallCommand extends Command
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
        $io->title('Desinstalación del Módulo de Música');

        $helper = $this->getHelper('question');
        $question = new ConfirmationQuestion(
            '⚠️  ADVERTENCIA: Esta acción eliminará todos los datos del módulo de música (canciones, géneros, playlists, etc.). ¿Deseas continuar? (s/N) ',
            false
        );

        if (!$helper->ask($input, $output, $question)) {
            $io->info('Operación cancelada.');
            return Command::SUCCESS;
        }

        try {
            $moduloRepository = $this->entityManager->getRepository(Modulo::class);
            $modulo = $moduloRepository->findOneBy(['nombre' => 'Música']);

            if (!$modulo) {
                $io->warning('El módulo de Música no está instalado o ya fue desinstalado.');
                return Command::SUCCESS;
            }

            $io->section('Actualizando estado del módulo');
            
            $modulo->setEstado(false);
            $modulo->setUninstallDate(new \DateTimeImmutable());
            
            $this->entityManager->flush();
            
            $io->success('Estado del módulo actualizado.');

            $eliminarTablas = $helper->ask($input, $output, new ConfirmationQuestion(
                '¿Deseas eliminar completamente las tablas del módulo? (s/N) ',
                false
            ));

            if ($eliminarTablas) {
                $io->section('Eliminando tablas de la base de datos');
                
                $conn = $this->entityManager->getConnection();
                
                $tablas = ['musica_playlist_cancion', 'musica_playlist', 'musica_cancion', 'musica_genero'];
                
                foreach ($tablas as $tabla) {
                    try {
                        $conn->executeStatement("DROP TABLE IF EXISTS $tabla");
                        $io->text("Tabla '$tabla' eliminada.");
                    } catch (\Exception $e) {
                        $io->warning("Error al eliminar la tabla '$tabla': " . $e->getMessage());
                    }
                }
                
                $io->success('Tablas eliminadas correctamente.');
            }

            $eliminarRegistro = $helper->ask($input, $output, new ConfirmationQuestion(
                '¿Deseas eliminar completamente el registro del módulo? (s/N) ',
                false
            ));

            if ($eliminarRegistro) {
                $io->section('Eliminando registro del módulo');
                
                $this->entityManager->remove($modulo);
                $this->entityManager->flush();
                
                $io->success('Registro del módulo eliminado.');
            }

            $io->section('Limpiando caché');
            
            $process = new Process(['php', 'bin/console', 'cache:clear']);
            $process->run();
            
            if (!$process->isSuccessful()) {
                $io->warning('Error al limpiar la caché: ' . $process->getErrorOutput());
            } else {
                $io->success('Caché limpiada correctamente.');
            }

            $io->success([
                'Módulo de Música desinstalado correctamente.',
                $eliminarTablas ? 'Los datos han sido eliminados.' : 'Los datos se han mantenido, pero el módulo está desactivado.'
            ]);

            return Command::SUCCESS;
        } catch (\Exception $e) {
            $io->error('Error durante la desinstalación: ' . $e->getMessage());
            return Command::FAILURE;
        }
    }
}