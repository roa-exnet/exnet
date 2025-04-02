<?php

namespace App\ModuloMusica\Command;

use App\ModuloCore\Entity\MenuElement;
use App\ModuloCore\Entity\Modulo;
use App\ModuloMusica\Entity\Genero;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Process\Process;

#[AsCommand(
    name: 'musica:install',
    description: 'Instala el módulo de música'
)]
class MusicaInstallCommand extends Command
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
        $io->title('Instalación del Módulo de Música');

        try {
            $moduloRepository = $this->entityManager->getRepository(Modulo::class);
            $moduloExistente = $moduloRepository->findOneBy(['nombre' => 'Música']);

            if ($moduloExistente) {
                $io->warning('El módulo de Música ya está instalado.');
                return Command::SUCCESS;
            }

            $io->section('Creando tablas de la base de datos');
            
            $process = new Process(['php', 'bin/console', 'make:migration']);
            $process->run();
            
            if (!$process->isSuccessful()) {
                $io->error('Error al generar la migración: ' . $process->getErrorOutput());
                return Command::FAILURE;
            }
            
            $io->text('Migración generada correctamente.');
            
            $process = new Process(['php', 'bin/console', 'doctrine:migrations:migrate', '--no-interaction']);
            $process->run();
            
            if (!$process->isSuccessful()) {
                $io->error('Error al ejecutar la migración: ' . $process->getErrorOutput());
                return Command::FAILURE;
            }
            
            $io->success('Tablas creadas correctamente.');

            $io->section('Registrando el módulo en el sistema');
            
            $modulo = new Modulo();
            $modulo->setNombre('Música');
            $modulo->setDescripcion('Módulo para gestionar y reproducir música');
            $modulo->setIcon('fas fa-music');
            $modulo->setRuta('/musica');
            $modulo->setEstado(true);
            $modulo->setInstallDate(new \DateTimeImmutable());
            
            $this->entityManager->persist($modulo);
            $this->entityManager->flush();
            
            $io->success('Módulo registrado correctamente.');

            $io->section('Creando elemento de menú');

            $menuItem = new MenuElement();
            $menuItem->setNombre('Música');
            $menuItem->setIcon('fas fa-music');
            $menuItem->setType('menu');
            $menuItem->setParentId(0);
            $menuItem->setRuta('/musica');
            $menuItem->setEnabled(true);
            $menuItem->addModulo($modulo);

            $this->entityManager->persist($menuItem);
            $this->entityManager->flush();

            $io->success('Elemento de menú creado correctamente.');
            $io->section('Creando géneros musicales predeterminados');
            
            $generosDefault = [
                ['nombre' => 'Rock', 'icono' => 'fas fa-guitar', 'descripcion' => 'Rock clásico y contemporáneo'],
                ['nombre' => 'Pop', 'icono' => 'fas fa-music', 'descripcion' => 'Música popular y comercial'],
                ['nombre' => 'Hip Hop', 'icono' => 'fas fa-headphones', 'descripcion' => 'Rap y Hip Hop'],
                ['nombre' => 'Electrónica', 'icono' => 'fas fa-sliders-h', 'descripcion' => 'Música electrónica y dance'],
                ['nombre' => 'Jazz', 'icono' => 'fas fa-saxophone', 'descripcion' => 'Jazz y blues'],
                ['nombre' => 'Clásica', 'icono' => 'fas fa-violin', 'descripcion' => 'Música clásica y orquestal'],
                ['nombre' => 'Latina', 'icono' => 'fas fa-drum', 'descripcion' => 'Salsa, merengue, reggaeton y más'],
                ['nombre' => 'Country', 'icono' => 'fas fa-hat-cowboy', 'descripcion' => 'Música country y folk'],
            ];
            
            foreach ($generosDefault as $generoData) {
                $genero = new Genero();
                $genero->setNombre($generoData['nombre']);
                $genero->setIcono($generoData['icono']);
                $genero->setDescripcion($generoData['descripcion']);
                
                $this->entityManager->persist($genero);
            }
            
            $this->entityManager->flush();
            
            $io->success('Géneros musicales predeterminados creados correctamente.');

            $io->section('Limpiando caché');
            
            $process = new Process(['php', 'bin/console', 'cache:clear']);
            $process->run();
            
            if (!$process->isSuccessful()) {
                $io->warning('Error al limpiar la caché: ' . $process->getErrorOutput());
            } else {
                $io->success('Caché limpiada correctamente.');
            }

            $io->success([
                'Módulo de Música instalado correctamente.',
                'Puedes acceder a él en: /musica'
            ]);

            return Command::SUCCESS;
        } catch (\Exception $e) {
            $io->error('Error durante la instalación: ' . $e->getMessage());
            return Command::FAILURE;
        }
    }
}