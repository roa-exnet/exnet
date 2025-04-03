<?php

namespace App\ModuloMusica\Service;

use App\ModuloMusica\Entity\Cancion;
use App\ModuloMusica\Entity\Genero;
use App\ModuloMusica\Repository\CancionRepository;
use App\ModuloMusica\Repository\GeneroRepository;
use Doctrine\ORM\EntityManagerInterface;

class CancionService
{
    private EntityManagerInterface $entityManager;
    private CancionRepository $cancionRepository;
    private GeneroRepository $generoRepository;

    public function __construct(
        EntityManagerInterface $entityManager,
        CancionRepository $cancionRepository,
        GeneroRepository $generoRepository
    ) {
        $this->entityManager = $entityManager;
        $this->cancionRepository = $cancionRepository;
        $this->generoRepository = $generoRepository;
    }

    /**
     * Crea una nueva canción
     */
    public function createSong(
        string $titulo,
        ?string $artista,
        ?string $album,
        ?string $descripcion,
        ?string $url,
        ?string $imagen,
        ?int $generoId,
        ?int $anio,
        ?int $duracion,
        bool $esPublico
    ): Cancion {
        $cancion = new Cancion();
        $cancion->setTitulo($titulo);
        $cancion->setArtista($artista);
        $cancion->setAlbum($album);
        $cancion->setDescripcion($descripcion);
        $cancion->setUrl($url);
        $cancion->setImagen($imagen);
        $cancion->setAnio($anio);
        $cancion->setDuracion($duracion);
        $cancion->setEsPublico($esPublico);
        
        if ($generoId) {
            $genero = $this->generoRepository->find($generoId);
            if ($genero) {
                $cancion->setGenero($genero);
            }
        }
        
        $this->entityManager->persist($cancion);
        $this->entityManager->flush();
        
        return $cancion;
    }

    /**
     * Actualiza una canción existente
     */
    public function updateSong(
        Cancion $cancion,
        string $titulo,
        ?string $artista,
        ?string $album,
        ?string $descripcion,
        ?string $url,
        ?string $imagen,
        ?int $generoId,
        ?int $anio,
        ?int $duracion,
        bool $esPublico
    ): Cancion {
        $cancion->setTitulo($titulo);
        $cancion->setArtista($artista);
        $cancion->setAlbum($album);
        $cancion->setDescripcion($descripcion);
        $cancion->setUrl($url);
        $cancion->setImagen($imagen);
        $cancion->setAnio($anio);
        $cancion->setDuracion($duracion);
        $cancion->setEsPublico($esPublico);
        $cancion->setActualizadoEn(new \DateTimeImmutable());
        
        if ($generoId) {
            $genero = $this->generoRepository->find($generoId);
            if ($genero) {
                $cancion->setGenero($genero);
            }
        } else {
            $cancion->setGenero(null);
        }
        
        $this->entityManager->flush();
        
        return $cancion;
    }

    /**
     * Elimina una canción
     */
    public function deleteSong(Cancion $cancion): void
    {
        $this->entityManager->remove($cancion);
        $this->entityManager->flush();
    }

    /**
     * Obtiene una canción por su ID
     */
    public function getSongById(int $id): ?Cancion
    {
        return $this->cancionRepository->find($id);
    }

    /**
     * Obtiene canciones por artista
     */
    public function getSongsByArtist(string $artista): array
    {
        return $this->cancionRepository->findByArtista($artista);
    }

    /**
     * Obtiene canciones por álbum
     */
    public function getSongsByAlbum(string $album): array
    {
        return $this->cancionRepository->findByAlbum($album);
    }
}