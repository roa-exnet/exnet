<?php

namespace App\ModuloStreaming\Command;

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
    name: 'streaming:uninstall',
    description: 'Desinstala el módulo de streaming'
)]
class StreamingUninstallCommand extends Command
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
        $io->title('Desinstalación del Módulo de Streaming');

        // Confirmar la desinstalación
        $helper = $this->getHelper('question');
        $question = new ConfirmationQuestion(
            '⚠️  ADVERTENCIA: Esta acción eliminará todos los datos del módulo de streaming (categorías, videos, etc.). ¿Deseas continuar? (s/N) ',
            false
        );

        if (!$helper->ask($input, $output, $question)) {
            $io->info('Operación cancelada.');
            return Command::SUCCESS;
        }

        try {
            // Paso 1: Verificar si el módulo existe
            $moduloRepository = $this->entityManager->getRepository(Modulo::class);
            $modulo = $moduloRepository->findOneBy(['nombre' => 'Streaming']);

            if (!$modulo) {
                $io->warning('El módulo de Streaming no está instalado o ya fue desinstalado.');
                return Command::SUCCESS;
            }

            // Paso 2: Marcar el módulo como desinstalado en la tabla de módulos
            $io->section('Actualizando estado del módulo');
            
            $modulo->setEstado(false);
            $modulo->setUninstallDate(new \DateTimeImmutable());
            
            $this->entityManager->flush();
            
            $io->success('Estado del módulo actualizado.');

            // Paso 3: Eliminar las tablas (opcionalmente)
            $eliminarTablas = $helper->ask($input, $output, new ConfirmationQuestion(
                '¿Deseas eliminar completamente las tablas del módulo? (s/N) ',
                false
            ));

            if ($eliminarTablas) {
                $io->section('Eliminando tablas de la base de datos');
                
                // Conexión directa a la base de datos para ejecutar DROP TABLE
                $conn = $this->entityManager->getConnection();
                
                // Lista de tablas a eliminar
                $tablas = ['streaming_video', 'streaming_categoria'];
                
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

            // Paso 4: Eliminar el registro del módulo (opcionalmente)
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

            // Paso 5: Limpiar la caché
            $io->section('Limpiando caché');
            
            $process = new Process(['php', 'bin/console', 'cache:clear']);
            $process->run();
            
            if (!$process->isSuccessful()) {
                $io->warning('Error al limpiar la caché: ' . $process->getErrorOutput());
            } else {
                $io->success('Caché limpiada correctamente.');
            }

            $io->success([
                'Módulo de Streaming desinstalado correctamente.',
                $eliminarTablas ? 'Los datos han sido eliminados.' : 'Los datos se han mantenido, pero el módulo está desactivado.'
            ]);

            return Command::SUCCESS;
        } catch (\Exception $e) {
            $io->error('Error durante la desinstalación: ' . $e->getMessage());
            return Command::FAILURE;
        }
    }
}