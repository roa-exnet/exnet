<?php

namespace App\ModuloStreaming\Command;

use App\ModuloCore\Entity\Modulo;
use App\ModuloStreaming\Entity\Categoria;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Process\Process;

#[AsCommand(
    name: 'streaming:install',
    description: 'Instala el módulo de streaming'
)]
class StreamingInstallCommand extends Command
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
        $io->title('Instalación del Módulo de Streaming');

        try {
            // Paso 1: Verificar si el módulo ya está instalado
            $moduloRepository = $this->entityManager->getRepository(Modulo::class);
            $moduloExistente = $moduloRepository->findOneBy(['nombre' => 'Streaming']);

            if ($moduloExistente) {
                $io->warning('El módulo de Streaming ya está instalado.');
                return Command::SUCCESS;
            }

            // Paso 2: Ejecutar las migraciones para crear las tablas
            $io->section('Creando tablas de la base de datos');
            
            // Generar la migración
            $process = new Process(['php', 'bin/console', 'make:migration']);
            $process->run();
            
            if (!$process->isSuccessful()) {
                $io->error('Error al generar la migración: ' . $process->getErrorOutput());
                return Command::FAILURE;
            }
            
            $io->text('Migración generada correctamente.');
            
            // Ejecutar la migración
            $process = new Process(['php', 'bin/console', 'doctrine:migrations:migrate', '--no-interaction']);
            $process->run();
            
            if (!$process->isSuccessful()) {
                $io->error('Error al ejecutar la migración: ' . $process->getErrorOutput());
                return Command::FAILURE;
            }
            
            $io->success('Tablas creadas correctamente.');

            // Paso 3: Registrar el módulo en la tabla de módulos
            $io->section('Registrando el módulo en el sistema');
            
            $modulo = new Modulo();
            $modulo->setNombre('Streaming');
            $modulo->setDescripcion('Módulo para gestionar series y películas');
            $modulo->setIcon('fas fa-film');
            $modulo->setRuta('/streaming');
            $modulo->setEstado(true);
            $modulo->setInstallDate(new \DateTimeImmutable());
            
            $this->entityManager->persist($modulo);
            $this->entityManager->flush();
            
            $io->success('Módulo registrado correctamente.');

            // Paso 4: Crear categorías por defecto
            $io->section('Creando categorías predeterminadas');
            
            $categoriasDefault = [
                ['nombre' => 'Acción', 'icono' => 'fas fa-running', 'descripcion' => 'Películas y series de acción'],
                ['nombre' => 'Comedia', 'icono' => 'fas fa-laugh', 'descripcion' => 'Películas y series de comedia'],
                ['nombre' => 'Drama', 'icono' => 'fas fa-theater-masks', 'descripcion' => 'Películas y series dramáticas'],
                ['nombre' => 'Ciencia Ficción', 'icono' => 'fas fa-rocket', 'descripcion' => 'Películas y series de ciencia ficción'],
                ['nombre' => 'Terror', 'icono' => 'fas fa-ghost', 'descripcion' => 'Películas y series de terror'],
                ['nombre' => 'Documental', 'icono' => 'fas fa-film', 'descripcion' => 'Documentales y series documentales'],
            ];
            
            foreach ($categoriasDefault as $categoriaData) {
                $categoria = new Categoria();
                $categoria->setNombre($categoriaData['nombre']);
                $categoria->setIcono($categoriaData['icono']);
                $categoria->setDescripcion($categoriaData['descripcion']);
                
                $this->entityManager->persist($categoria);
            }
            
            $this->entityManager->flush();
            
            $io->success('Categorías predeterminadas creadas correctamente.');

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
                'Módulo de Streaming instalado correctamente.',
                'Puedes acceder a él en: /streaming'
            ]);

            return Command::SUCCESS;
        } catch (\Exception $e) {
            $io->error('Error durante la instalación: ' . $e->getMessage());
            return Command::FAILURE;
        }
    }
}