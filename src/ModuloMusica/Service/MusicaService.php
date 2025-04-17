<?php

namespace App\ModuloMusica\Service;

use App\ModuloCore\Service\IpAuthService;
use App\ModuloMusica\Repository\CancionRepository;
use App\ModuloMusica\Repository\GeneroRepository;
use App\ModuloMusica\Repository\PlaylistRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\File\UploadedFile;

class MusicaService
{
    private EntityManagerInterface $entityManager;
    private CancionRepository $cancionRepository;
    private GeneroRepository $generoRepository;
    private PlaylistRepository $playlistRepository;
    private IpAuthService $ipAuthService;

    public function __construct(
        EntityManagerInterface $entityManager,
        CancionRepository $cancionRepository,
        GeneroRepository $generoRepository,
        PlaylistRepository $playlistRepository,
        IpAuthService $ipAuthService
    ) {
        $this->entityManager = $entityManager;
        $this->cancionRepository = $cancionRepository;
        $this->generoRepository = $generoRepository;
        $this->playlistRepository = $playlistRepository;
        $this->ipAuthService = $ipAuthService;
    }

    /**
     * Obtiene todas las canciones, opcionalmente filtradas por género y/o búsqueda
     */
    public function getAllSongs(?int $generoId = null, ?string $search = null): array
    {
        return $this->cancionRepository->findByGeneroAndSearch($generoId, $search);
    }

    /**
     * Obtiene todos los géneros
     */
    public function getAllGenres(): array
    {
        return $this->generoRepository->findAllOrdered();
    }

    /**
     * Obtiene las playlists de un usuario
     */
    public function getUserPlaylists(string $userId): array
    {
        return $this->playlistRepository->findByUser($userId);
    }

    /**
     * Obtiene los artistas más populares
     */
    public function getTopArtists(int $limit = 5): array
    {
        return $this->cancionRepository->findTopArtistas($limit);
    }

    /**
     * Obtiene los álbumes más populares
     */
    public function getTopAlbums(int $limit = 5): array
    {
        return $this->cancionRepository->findTopAlbumes($limit);
    }

    /**
     * Obtiene el usuario actual
     */
    public function getCurrentUser()
    {
        return $this->ipAuthService->getCurrentUser();
    }
}