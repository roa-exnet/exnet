<?php

namespace App\ModuloMusica\Service;

use App\ModuloMusica\Entity\Genero;
use App\ModuloMusica\Repository\GeneroRepository;
use Doctrine\ORM\EntityManagerInterface;

class GeneroService
{
    private EntityManagerInterface $entityManager;
    private GeneroRepository $generoRepository;

    public function __construct(
        EntityManagerInterface $entityManager,
        GeneroRepository $generoRepository
    ) {
        $this->entityManager = $entityManager;
        $this->generoRepository = $generoRepository;
    }

    /**
     * Crea un nuevo género musical
     */
    public function createGenre(
        string $nombre,
        ?string $descripcion,
        ?string $icono
    ): Genero {
        $genero = new Genero();
        $genero->setNombre($nombre);
        $genero->setDescripcion($descripcion);
        $genero->setIcono($icono);
        
        $this->entityManager->persist($genero);
        $this->entityManager->flush();
        
        return $genero;
    }

    /**
     * Actualiza un género existente
     */
    public function updateGenre(
        Genero $genero,
        string $nombre,
        ?string $descripcion,
        ?string $icono
    ): Genero {
        $genero->setNombre($nombre);
        $genero->setDescripcion($descripcion);
        $genero->setIcono($icono);
        
        $this->entityManager->flush();
        
        return $genero;
    }

    /**
     * Elimina un género
     */
    public function deleteGenre(Genero $genero): bool
    {
        // Verificar si hay canciones que usan este género
        if (!$genero->getCanciones()->isEmpty()) {
            return false;
        }
        
        $this->entityManager->remove($genero);
        $this->entityManager->flush();
        
        return true;
    }

    /**
     * Obtiene un género por su ID
     */
    public function getGenreById(int $id): ?Genero
    {
        return $this->generoRepository->find($id);
    }

    /**
     * Obtiene todos los géneros ordenados alfabéticamente
     */
    public function getAllGenres(): array
    {
        return $this->generoRepository->findAllOrdered();
    }

    /**
     * Obtiene géneros con conteo de canciones
     */
    public function getGenresWithSongCount(): array
    {
        return $this->generoRepository->findWithCancionCount();
    }
}