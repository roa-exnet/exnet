<?php

namespace App\ModuloMusica\Controller;

use App\ModuloCore\Service\IpAuthService;
use App\ModuloMusica\Entity\Cancion;
use App\ModuloMusica\Entity\Genero;
use App\ModuloMusica\Entity\Playlist;
use App\ModuloMusica\Repository\CancionRepository;
use App\ModuloMusica\Repository\GeneroRepository;
use App\ModuloMusica\Repository\PlaylistRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Annotation\Route;

#[Route('/musica')]
class MusicaController extends AbstractController
{
    private EntityManagerInterface $entityManager;
    private IpAuthService $ipAuthService;
    private CancionRepository $cancionRepository;
    private GeneroRepository $generoRepository;
    private PlaylistRepository $playlistRepository;

    public function __construct(
        EntityManagerInterface $entityManager,
        IpAuthService $ipAuthService,
        CancionRepository $cancionRepository,
        GeneroRepository $generoRepository,
        PlaylistRepository $playlistRepository
    ) {
        $this->entityManager = $entityManager;
        $this->ipAuthService = $ipAuthService;
        $this->cancionRepository = $cancionRepository;
        $this->generoRepository = $generoRepository;
        $this->playlistRepository = $playlistRepository;
    }

    #[Route('', name: 'musica_index')]
    public function index(Request $request): Response
    {
        $user = $this->ipAuthService->getCurrentUser();
        if (!$user) {
            return $this->redirectToRoute('app_register_ip', [
                'redirect' => $this->generateUrl('musica_index')
            ]);
        }

        $generoId = $request->query->get('genero');
        $busqueda = $request->query->get('q');

        $canciones = $this->cancionRepository->findByGeneroAndSearch($generoId, $busqueda);

        $generos = $this->generoRepository->findAllOrdered();
        
        $playlists = $this->playlistRepository->findByUser($user->getId());
        
        $topArtistas = $this->cancionRepository->findTopArtistas(5);
        $topAlbumes = $this->cancionRepository->findTopAlbumes(5);

        return $this->render('@ModuloMusica/index.html.twig', [
            'canciones' => $canciones,
            'generos' => $generos,
            'playlists' => $playlists,
            'generoActual' => $generoId,
            'busqueda' => $busqueda,
            'topArtistas' => $topArtistas,
            'topAlbumes' => $topAlbumes,
            'user' => $user
        ]);
    }

    #[Route('/cancion/{id}', name: 'musica_cancion_detalle')]
    public function detalleCancion(int $id): Response
    {
        $user = $this->ipAuthService->getCurrentUser();
        if (!$user) {
            return $this->redirectToRoute('app_register_ip', [
                'redirect' => $this->generateUrl('musica_cancion_detalle', ['id' => $id])
            ]);
        }

        $cancion = $this->cancionRepository->find($id);
        if (!$cancion) {
            throw $this->createNotFoundException('La canción no existe');
        }
        
        $cancionesArtista = [];
        if ($cancion->getArtista()) {
            $cancionesArtista = $this->cancionRepository->findByArtista($cancion->getArtista());
            $cancionesArtista = array_filter($cancionesArtista, function($item) use ($cancion) {
                return $item->getId() !== $cancion->getId();
            });
        }
        
        $playlists = $this->playlistRepository->findByUser($user->getId());
        
        $playlistsConCancion = $this->playlistRepository->findPlaylistsWithCancion($cancion->getId());

        return $this->render('@ModuloMusica/detalle_cancion.html.twig', [
            'cancion' => $cancion,
            'cancionesArtista' => $cancionesArtista,
            'playlists' => $playlists,
            'playlistsConCancion' => $playlistsConCancion,
            'user' => $user
        ]);
    }

    #[Route('/artista/{artista}', name: 'musica_artista_detalle')]
    public function detalleArtista(string $artista): Response
    {
        $user = $this->ipAuthService->getCurrentUser();
        if (!$user) {
            return $this->redirectToRoute('app_register_ip', [
                'redirect' => $this->generateUrl('musica_artista_detalle', ['artista' => $artista])
            ]);
        }

        $canciones = $this->cancionRepository->findByArtista($artista);
        if (empty($canciones)) {
            throw $this->createNotFoundException('No se encontraron canciones para este artista');
        }
        
        $albumes = [];
        foreach ($canciones as $cancion) {
            $album = $cancion->getAlbum() ?: 'Sin álbum';
            if (!isset($albumes[$album])) {
                $albumes[$album] = [
                    'titulo' => $album,
                    'anio' => $cancion->getAnio(),
                    'imagen' => $cancion->getImagen(),
                    'canciones' => []
                ];
            }
            $albumes[$album]['canciones'][] = $cancion;
        }

        return $this->render('@ModuloMusica/detalle_artista.html.twig', [
            'artista' => $artista,
            'albumes' => $albumes,
            'canciones' => $canciones,
            'user' => $user
        ]);
    }

    #[Route('/album/{artista}/{album}', name: 'musica_album_detalle')]
    public function detalleAlbum(string $artista, string $album): Response
    {
        $user = $this->ipAuthService->getCurrentUser();
        if (!$user) {
            return $this->redirectToRoute('app_register_ip', [
                'redirect' => $this->generateUrl('musica_album_detalle', ['artista' => $artista, 'album' => $album])
            ]);
        }

        $canciones = $this->cancionRepository->createQueryBuilder('c')
            ->andWhere('c.artista = :artista')
            ->andWhere('c.album = :album')
            ->setParameter('artista', $artista)
            ->setParameter('album', $album)
            ->orderBy('c.titulo', 'ASC')
            ->getQuery()
            ->getResult();

        if (empty($canciones)) {
            throw $this->createNotFoundException('No se encontró el álbum especificado');
        }
        
        $playlists = $this->playlistRepository->findByUser($user->getId());

        return $this->render('@ModuloMusica/detalle_album.html.twig', [
            'artista' => $artista,
            'album' => $album,
            'canciones' => $canciones,
            'playlists' => $playlists,
            'primeraCancion' => $canciones[0] ?? null,
            'user' => $user
        ]);
    }

    #[Route('/genero/{id}', name: 'musica_genero_detalle')]
    public function detalleGenero(int $id): Response
    {
        $user = $this->ipAuthService->getCurrentUser();
        if (!$user) {
            return $this->redirectToRoute('app_register_ip', [
                'redirect' => $this->generateUrl('musica_genero_detalle', ['id' => $id])
            ]);
        }

        $genero = $this->generoRepository->find($id);
        if (!$genero) {
            throw $this->createNotFoundException('El género no existe');
        }

        $canciones = $this->cancionRepository->findByGeneroAndSearch($id);
        
        $playlists = $this->playlistRepository->findByUser($user->getId());

        return $this->render('@ModuloMusica/detalle_genero.html.twig', [
            'genero' => $genero,
            'canciones' => $canciones,
            'playlists' => $playlists,
            'user' => $user
        ]);
    }

    #[Route('/playlist/{id<\d+>}', name: 'musica_playlist_detalle')]
    public function detallePlaylist(int $id): Response
    {
        $id = (int)$id;

        $user = $this->ipAuthService->getCurrentUser();
        if (!$user) {
            return $this->redirectToRoute('app_register_ip', [
                'redirect' => $this->generateUrl('musica_playlist_detalle', ['id' => $id])
            ]);
        }

        $playlist = $this->playlistRepository->find($id);
        if (!$playlist) {
            throw $this->createNotFoundException('La playlist no existe');
        }

        if (!$playlist->isEsPublica() && $playlist->getCreadorId() !== $user->getId()) {
            throw $this->createAccessDeniedException('No tienes permisos para ver esta playlist');
        }

        return $this->render('@ModuloMusica/detalle_playlist.html.twig', [
            'playlist' => $playlist,
            'canciones' => $playlist->getCanciones(),
            'user' => $user
        ]);
    }

    #[Route('/playlist/nueva', name: 'musica_playlist_nueva')]
    public function nuevaPlaylist(Request $request): Response
    {
        $user = $this->ipAuthService->getCurrentUser();
        if (!$user) {
            return $this->redirectToRoute('app_register_ip', [
                'redirect' => $this->generateUrl('musica_playlist_nueva')
            ]);
        }

        if ($request->isMethod('POST')) {
            $nombre = $request->request->get('nombre');
            $descripcion = $request->request->get('descripcion');
            $esPublica = $request->request->has('esPublica');
            $imagen = $request->request->get('imagen');

            $playlist = new Playlist();
            $playlist->setNombre($nombre);
            $playlist->setDescripcion($descripcion);
            $playlist->setEsPublica($esPublica);
            $playlist->setImagen($imagen);
            $playlist->setCreadorId($user->getId());
            $playlist->setCreadorNombre($user->getNombre() . ' ' . $user->getApellidos());

            $this->entityManager->persist($playlist);
            $this->entityManager->flush();

            $this->addFlash('success', 'Playlist creada correctamente');
            return $this->redirectToRoute('musica_playlist_detalle', ['id' => $playlist->getId()]);
        }

        return $this->render('@ModuloMusica/nueva_playlist.html.twig', [
            'user' => $user
        ]);
    }

    #[Route('/playlist/{id}/agregar-cancion', name: 'musica_playlist_agregar_cancion', methods: ['POST'])]
    public function agregarCancionPlaylist(int $id, Request $request): Response
    {
        $user = $this->ipAuthService->getCurrentUser();
        if (!$user) {
            return $this->redirectToRoute('app_register_ip');
        }

        $playlist = $this->playlistRepository->find($id);
        if (!$playlist) {
            throw $this->createNotFoundException('La playlist no existe');
        }

        if ($playlist->getCreadorId() !== $user->getId()) {
            throw $this->createAccessDeniedException('No tienes permisos para modificar esta playlist');
        }

        $cancionId = $request->request->get('cancion_id');
        $cancion = $this->cancionRepository->find($cancionId);
        
        if (!$cancion) {
            throw $this->createNotFoundException('La canción no existe');
        }

        if (!$playlist->getCanciones()->contains($cancion)) {
            $playlist->addCancion($cancion);
            $playlist->setActualizadoEn(new \DateTimeImmutable());
            $this->entityManager->flush();
            $this->addFlash('success', 'Canción agregada a la playlist');
        } else {
            $this->addFlash('info', 'La canción ya estaba en la playlist');
        }
        
        $referer = $request->headers->get('referer');
        return $this->redirect($referer ?: $this->generateUrl('musica_playlist_detalle', ['id' => $id]));
    }

    #[Route('/playlist/{id}/eliminar-cancion/{cancionId}', name: 'musica_playlist_eliminar_cancion', methods: ['POST'])]
    public function eliminarCancionPlaylist(int $id, int $cancionId): Response
    {
        $user = $this->ipAuthService->getCurrentUser();
        if (!$user) {
            return $this->redirectToRoute('app_register_ip');
        }

        $playlist = $this->playlistRepository->find($id);
        if (!$playlist) {
            throw $this->createNotFoundException('La playlist no existe');
        }

        if ($playlist->getCreadorId() !== $user->getId()) {
            throw $this->createAccessDeniedException('No tienes permisos para modificar esta playlist');
        }

        $cancion = $this->cancionRepository->find($cancionId);
        if (!$cancion) {
            throw $this->createNotFoundException('La canción no existe');
        }

        $playlist->removeCancion($cancion);
        $playlist->setActualizadoEn(new \DateTimeImmutable());
        $this->entityManager->flush();

        $this->addFlash('success', 'Canción eliminada de la playlist');
        return $this->redirectToRoute('musica_playlist_detalle', ['id' => $id]);
    }

    #[Route('/playlist/{id}/editar', name: 'musica_playlist_editar')]
    public function editarPlaylist(int $id, Request $request): Response
    {
        $user = $this->ipAuthService->getCurrentUser();
        if (!$user) {
            return $this->redirectToRoute('app_register_ip', [
                'redirect' => $this->generateUrl('musica_playlist_editar', ['id' => $id])
            ]);
        }

        $playlist = $this->playlistRepository->find($id);
        if (!$playlist) {
            throw $this->createNotFoundException('La playlist no existe');
        }

        if ($playlist->getCreadorId() !== $user->getId()) {
            throw $this->createAccessDeniedException('No tienes permisos para modificar esta playlist');
        }

        if ($request->isMethod('POST')) {
            $nombre = $request->request->get('nombre');
            $descripcion = $request->request->get('descripcion');
            $esPublica = $request->request->has('esPublica');
            $imagen = $request->request->get('imagen');

            $playlist->setNombre($nombre);
            $playlist->setDescripcion($descripcion);
            $playlist->setEsPublica($esPublica);
            $playlist->setImagen($imagen);
            $playlist->setActualizadoEn(new \DateTimeImmutable());

            $this->entityManager->flush();

            $this->addFlash('success', 'Playlist actualizada correctamente');
            return $this->redirectToRoute('musica_playlist_detalle', ['id' => $playlist->getId()]);
        }

        return $this->render('@ModuloMusica/editar_playlist.html.twig', [
            'playlist' => $playlist,
            'user' => $user
        ]);
    }

    #[Route('/playlist/{id}/eliminar', name: 'musica_playlist_eliminar', methods: ['POST'])]
    public function eliminarPlaylist(int $id): Response
    {
        $user = $this->ipAuthService->getCurrentUser();
        if (!$user) {
            return $this->redirectToRoute('app_register_ip');
        }

        $playlist = $this->playlistRepository->find($id);
        if (!$playlist) {
            throw $this->createNotFoundException('La playlist no existe');
        }

        if ($playlist->getCreadorId() !== $user->getId()) {
            throw $this->createAccessDeniedException('No tienes permisos para eliminar esta playlist');
        }

        $this->entityManager->remove($playlist);
        $this->entityManager->flush();

        $this->addFlash('success', 'Playlist eliminada correctamente');
        return $this->redirectToRoute('musica_index');
    }

    #[Route('/reproductor/{id}', name: 'musica_reproductor')]
    public function reproductor(int $id): Response
    {
        $user = $this->ipAuthService->getCurrentUser();
        if (!$user) {
            return $this->redirectToRoute('app_register_ip', [
                'redirect' => $this->generateUrl('musica_reproductor', ['id' => $id])
            ]);
        }

        $cancion = $this->cancionRepository->find($id);
        if (!$cancion) {
            throw $this->createNotFoundException('La canción no existe');
        }

        return $this->render('@ModuloMusica/reproductor.html.twig', [
            'cancion' => $cancion,
            'user' => $user
        ]);
    }

    #[Route('/admin', name: 'musica_admin')]
    public function admin(): Response
    {
        $user = $this->ipAuthService->getCurrentUser();
        if (!$user) {
            return $this->redirectToRoute('app_register_ip', [
                'redirect' => $this->generateUrl('musica_admin')
            ]);
        }

        if (!in_array('ROLE_ADMIN', $user->getRoles())) {
            throw $this->createAccessDeniedException('No tienes permisos para acceder a esta sección');
        }

        $canciones = $this->cancionRepository->findAll();
        $generos = $this->generoRepository->findWithCancionCount();

        return $this->render('@ModuloMusica/admin.html.twig', [
            'canciones' => $canciones,
            'generos' => $generos,
            'user' => $user
        ]);
    }
}