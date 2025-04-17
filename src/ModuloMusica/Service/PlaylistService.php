<?php

namespace App\ModuloMusica\Service;

use App\ModuloMusica\Entity\Playlist;
use App\ModuloMusica\Entity\Cancion;
use App\ModuloMusica\Repository\PlaylistRepository;
use App\ModuloMusica\Repository\CancionRepository;
use Doctrine\ORM\EntityManagerInterface;

class PlaylistService
{
    private EntityManagerInterface $entityManager;
    private PlaylistRepository $playlistRepository;
    private CancionRepository $cancionRepository;

    public function __construct(
        EntityManagerInterface $entityManager,
        PlaylistRepository $playlistRepository,
        CancionRepository $cancionRepository
    ) {
        $this->entityManager = $entityManager;
        $this->playlistRepository = $playlistRepository;
        $this->cancionRepository = $cancionRepository;
    }

    /**
     * Crea una nueva playlist
     */
    public function createPlaylist(
        string $nombre,
        ?string $descripcion,
        ?string $imagen,
        bool $esPublica,
        string $creadorId,
        string $creadorNombre
    ): Playlist {
        $playlist = new Playlist();
        $playlist->setNombre($nombre);
        $playlist->setDescripcion($descripcion);
        $playlist->setImagen($imagen);
        $playlist->setEsPublica($esPublica);
        $playlist->setCreadorId($creadorId);
        $playlist->setCreadorNombre($creadorNombre);
        
        $this->entityManager->persist($playlist);
        $this->entityManager->flush();
        
        return $playlist;
    }

    /**
     * Agrega una canción a una playlist
     */
    public function addSongToPlaylist(Playlist $playlist, int $cancionId): bool
    {
        $cancion = $this->cancionRepository->find($cancionId);
        
        if (!$cancion) {
            return false;
        }
        
        if (!$playlist->getCanciones()->contains($cancion)) {
            $playlist->addCancion($cancion);
            $playlist->setActualizadoEn(new \DateTimeImmutable());
            $this->entityManager->flush();
            return true;
        }
        
        return false;
    }

    /**
     * Elimina una canción de una playlist
     */
    public function removeSongFromPlaylist(Playlist $playlist, int $cancionId): bool
    {
        $cancion = $this->cancionRepository->find($cancionId);
        
        if (!$cancion) {
            return false;
        }
        
        if ($playlist->getCanciones()->contains($cancion)) {
            $playlist->removeCancion($cancion);
            $playlist->setActualizadoEn(new \DateTimeImmutable());
            $this->entityManager->flush();
            return true;
        }
        
        return false;
    }

    /**
     * Obtiene una playlist por su ID
     */
    public function getPlaylistById(int $id): ?Playlist
    {
        return $this->playlistRepository->find($id);
    }

    /**
     * Verifica si un usuario es el propietario de una playlist
     */
    public function isPlaylistOwner(Playlist $playlist, string $userId): bool
    {
        return $playlist->getCreadorId() === $userId;
    }

    /**
     * Elimina una playlist
     */
    public function deletePlaylist(Playlist $playlist): void
    {
        $this->entityManager->remove($playlist);
        $this->entityManager->flush();
    }
}