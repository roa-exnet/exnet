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
use Symfony\Component\String\Slugger\SluggerInterface;

#[Route('kc/musica')]
class MusicaController extends AbstractController
{
    private EntityManagerInterface $entityManager;
    private IpAuthService $ipAuthService;
    private CancionRepository $cancionRepository;
    private GeneroRepository $generoRepository;
    private PlaylistRepository $playlistRepository;
    private SluggerInterface $slugger;

    public function __construct(
        EntityManagerInterface $entityManager,
        IpAuthService $ipAuthService,
        CancionRepository $cancionRepository,
        GeneroRepository $generoRepository,
        PlaylistRepository $playlistRepository,
        SluggerInterface $slugger
    ) {
        $this->entityManager = $entityManager;
        $this->ipAuthService = $ipAuthService;
        $this->cancionRepository = $cancionRepository;
        $this->generoRepository = $generoRepository;
        $this->playlistRepository = $playlistRepository;
        $this->slugger = $slugger;
    }

    private function checkAdmin(): ?Response
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

        return null;
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

    #[Route('/playlist/{id<\d+>}', name: 'musica_playlist_detalle', methods: ['GET'])]
    public function detallePlaylist(int $id): Response
    {

        $id = (int)$id;

        error_log('Accediendo a playlist con ID: ' . $id);

        $user = $this->ipAuthService->getCurrentUser();
        if (!$user) {
            error_log('Usuario no autenticado, redirigiendo a login');
            return $this->redirectToRoute('app_register_ip', [
                'redirect' => $this->generateUrl('musica_playlist_detalle', ['id' => $id])
            ]);
        }

        error_log('Usuario autenticado: ' . $user->getId() . ' - ' . $user->getNombre());
        error_log('Tipo de user->getId(): ' . gettype($user->getId()));

        $playlist = $this->playlistRepository->find($id);
        if (!$playlist) {
            error_log('Playlist no encontrada: ' . $id);
            throw $this->createNotFoundException('La playlist no existe');
        }

        error_log('Playlist encontrada: ' . $playlist->getId() . ' - ' . $playlist->getNombre());
        error_log('Creador de la playlist: ' . $playlist->getCreadorId());
        error_log('Tipo de playlist->getCreadorId(): ' . gettype($playlist->getCreadorId()));
        error_log('Es pública: ' . ($playlist->isEsPublica() ? 'Sí' : 'No'));

        $userId = (string) $user->getId();
        $playlistCreadorId = (string) $playlist->getCreadorId();

        error_log('User ID (convertido a string): ' . $userId);
        error_log('Playlist creador ID (convertido a string): ' . $playlistCreadorId);

        $userIsOwner = ($userId === $playlistCreadorId);

        if (!$userIsOwner) {
            $userIsOwner = ($user->getId() == $playlist->getCreadorId());
            error_log('Comparación no estricta: ' . ($userIsOwner ? 'Sí es propietario' : 'No es propietario'));
        }

        if (!$userIsOwner && !$playlist->isEsPublica()) {
            $playlists = $this->playlistRepository->findByUser($user->getId());
            foreach ($playlists as $userPlaylist) {
                if ($userPlaylist->getId() === $playlist->getId()) {
                    $userIsOwner = true;
                    error_log('Playlist encontrada en las playlists del usuario');
                    break;
                }
            }
        }

        $canView = $playlist->isEsPublica() || $userIsOwner;

        error_log('¿El usuario es propietario? ' . ($userIsOwner ? 'Sí' : 'No'));
        error_log('¿La playlist es pública? ' . ($playlist->isEsPublica() ? 'Sí' : 'No'));
        error_log('¿Puede ver la playlist? ' . ($canView ? 'Sí' : 'No'));

        if (!$canView) {
            error_log('Acceso denegado: Usuario ' . $user->getId() . ' no tiene permisos para ver la playlist ' . $id);
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
            $imagenBase64 = $request->request->get('imagenBase64');

            if (empty($nombre)) {
                $this->addFlash('error', 'El nombre de la playlist es obligatorio');
                return $this->render('@ModuloMusica/nueva_playlist.html.twig', [
                    'user' => $user
                ]);
            }

            $playlist = new Playlist();
            $playlist->setNombre($nombre);
            $playlist->setDescripcion($descripcion);
            $playlist->setEsPublica($esPublica);
            $playlist->setCreadorId($user->getId());
            $playlist->setCreadorNombre($user->getNombre() . ' ' . $user->getApellidos());

            if (!empty($imagenBase64)) {
                try {

                    $imageData = base64_decode($imagenBase64);
                    if ($imageData === false) {
                        throw new \Exception('No se pudo decodificar la imagen.');
                    }

                    $imageSize = strlen($imageData);
                    if ($imageSize > 2 * 1024 * 1024) {
                        throw new \Exception('La imagen excede el tamaño máximo de 2MB.');
                    }

                    $finfo = new \finfo(FILEINFO_MIME_TYPE);
                    $imageMimeType = $finfo->buffer($imageData);
                    $imageExtension = $this->getImageExtensionFromMimeType($imageMimeType);

                    if (!$imageExtension) {
                        throw new \Exception('Formato de imagen no soportado. Use PNG, JPG o WEBP.');
                    }

                    $safeImagename = $this->slugger->slug($nombre . '-playlist');
                    $newImagename = $safeImagename . '-' . uniqid() . '.' . $imageExtension;

                    $imagesPath = $this->getUploadsPath('images');
                    $imagePath = $imagesPath . '/' . $newImagename;
                    if (!file_put_contents($imagePath, $imageData)) {
                        throw new \Exception('No se pudo guardar la imagen.');
                    }

                    $playlist->setImagen('/uploads/images/' . $newImagename);
                } catch (\Exception $e) {
                    $this->addFlash('warning', 'Error al procesar la imagen: ' . $e->getMessage());
                }
            }

            $this->entityManager->persist($playlist);
            $this->entityManager->flush();

            if ($playlist->getId()) {
                $this->addFlash('success', 'Playlist creada correctamente');

                return $this->redirectToRoute('musica_playlist_detalle', ['id' => (int)$playlist->getId()]);
            } else {
                $this->addFlash('error', 'Error al crear la playlist');
                return $this->redirectToRoute('musica_index');
            }
        }

        return $this->render('@ModuloMusica/nueva_playlist.html.twig', [
            'user' => $user
        ]);
    }

    #[Route('/playlist/{id}/agregar-cancion', name: 'musica_playlist_agregar_cancion', methods: ['POST'])]
    public function agregarCancionPlaylist(int $id, Request $request): Response
    {
        $id = (int)$id;

        error_log('Intentando agregar canción a playlist con ID: ' . $id);

        $user = $this->ipAuthService->getCurrentUser();
        if (!$user) {
            error_log('Usuario no autenticado, redirigiendo a login');
            return $this->redirectToRoute('app_register_ip', [
                'redirect' => $this->generateUrl('musica_playlist_detalle', ['id' => $id])
            ]);
        }

        error_log('Usuario autenticado: ' . $user->getId() . ' - ' . $user->getNombre());

        $playlist = $this->playlistRepository->find($id);
        if (!$playlist) {
            error_log('Playlist no encontrada: ' . $id);
            throw $this->createNotFoundException('La playlist no existe');
        }

        error_log('Playlist encontrada: ' . $playlist->getId() . ' - ' . $playlist->getNombre());
        error_log('Creador de la playlist: ' . $playlist->getCreadorId());

        $userId = (string)$user->getId();
        $playlistCreadorId = (string)$playlist->getCreadorId();

        error_log('User ID (string): ' . $userId);
        error_log('Playlist creador ID (string): ' . $playlistCreadorId);

        $userIsOwner = ($userId === $playlistCreadorId);
        error_log('¿Es propietario? ' . ($userIsOwner ? 'Sí' : 'No'));

        if (!$userIsOwner) {
            error_log('Acceso denegado: El usuario no es propietario de la playlist');
            throw $this->createAccessDeniedException('No tienes permisos para modificar esta playlist');
        }

        $cancionId = $request->request->get('cancion_id');
        if (!$cancionId) {
            error_log('ID de canción no proporcionado');
            $this->addFlash('error', 'No se especificó ninguna canción');
            return $this->redirectToRoute('musica_playlist_detalle', ['id' => $id]);
        }

        error_log('ID de canción: ' . $cancionId);

        $cancion = $this->cancionRepository->find($cancionId);
        if (!$cancion) {
            error_log('Canción no encontrada: ' . $cancionId);
            throw $this->createNotFoundException('La canción no existe');
        }

        if (!$playlist->getCanciones()->contains($cancion)) {
            $playlist->addCancion($cancion);
            $playlist->setActualizadoEn(new \DateTimeImmutable());

            try {
                $this->entityManager->flush();
                error_log('Canción agregada correctamente a la playlist');
                $this->addFlash('success', 'Canción agregada a la playlist');
            } catch (\Exception $e) {
                error_log('Error al guardar: ' . $e->getMessage());
                $this->addFlash('error', 'Error al agregar la canción a la playlist');
                return $this->redirectToRoute('musica_playlist_detalle', ['id' => $id]);
            }
        } else {
            error_log('La canción ya existe en la playlist');
            $this->addFlash('info', 'La canción ya estaba en la playlist');
        }

        $referer = $request->headers->get('referer');
        error_log('URL de referencia: ' . ($referer ?: 'No disponible'));

        if (!$referer || $referer === '/' || strpos($referer, '/login') !== false) {
            error_log('Redirigiendo a detalle de playlist');
            return $this->redirectToRoute('musica_playlist_detalle', ['id' => $id]);
        }

        error_log('Redirigiendo a la página de origen: ' . $referer);
        return $this->redirect($referer);
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

        $id = (int)$id;

        error_log('Intentando editar playlist con ID: ' . $id);

        $user = $this->ipAuthService->getCurrentUser();
        if (!$user) {
            error_log('Usuario no autenticado, redirigiendo a login');
            return $this->redirectToRoute('app_register_ip', [
                'redirect' => $this->generateUrl('musica_playlist_editar', ['id' => $id])
            ]);
        }

        error_log('Usuario autenticado: ' . $user->getId() . ' - ' . $user->getNombre());

        $playlist = $this->playlistRepository->find($id);
        if (!$playlist) {
            error_log('Playlist no encontrada: ' . $id);
            throw $this->createNotFoundException('La playlist no existe');
        }

        error_log('Playlist encontrada: ' . $playlist->getId() . ' - ' . $playlist->getNombre());
        error_log('Creador de la playlist: ' . $playlist->getCreadorId());

        $userId = (string)$user->getId();
        $playlistCreadorId = (string)$playlist->getCreadorId();

        error_log('User ID (string): ' . $userId);
        error_log('Playlist creador ID (string): ' . $playlistCreadorId);

        $userIsOwner = ($userId === $playlistCreadorId);
        error_log('¿Es propietario? ' . ($userIsOwner ? 'Sí' : 'No'));

        if (!$userIsOwner) {
            error_log('Acceso denegado: El usuario no es propietario de la playlist');
            throw $this->createAccessDeniedException('No tienes permisos para modificar esta playlist');
        }

        if ($request->isMethod('POST')) {
            error_log('Recibido método POST para editar playlist');

            $nombre = $request->request->get('nombre');
            $descripcion = $request->request->get('descripcion');
            $esPublica = $request->request->has('esPublica');
            $imagenBase64 = $request->request->get('imagenBase64');
            $mantenerImagen = $request->request->has('mantenerImagen');

            error_log('Nombre recibido: ' . $nombre);
            error_log('¿Es pública? ' . ($esPublica ? 'Sí' : 'No'));
            error_log('¿Mantener imagen? ' . ($mantenerImagen ? 'Sí' : 'No'));

            if (empty($nombre)) {
                $this->addFlash('error', 'El nombre de la playlist es obligatorio');
                return $this->render('@ModuloMusica/editar_playlist.html.twig', [
                    'playlist' => $playlist,
                    'user' => $user
                ]);
            }

            $playlist->setNombre($nombre);
            $playlist->setDescripcion($descripcion);
            $playlist->setEsPublica($esPublica);
            $playlist->setActualizadoEn(new \DateTimeImmutable());

            if (!empty($imagenBase64)) {
                try {
                    error_log('Procesando nueva imagen');
                    $imageData = base64_decode($imagenBase64);
                    if ($imageData === false) {
                        throw new \Exception('No se pudo decodificar la imagen.');
                    }

                    $imageSize = strlen($imageData);
                    error_log('Tamaño de la imagen: ' . $imageSize . ' bytes');
                    if ($imageSize > 2 * 1024 * 1024) {
                        throw new \Exception('La imagen excede el tamaño máximo de 2MB.');
                    }

                    $finfo = new \finfo(FILEINFO_MIME_TYPE);
                    $imageMimeType = $finfo->buffer($imageData);
                    error_log('Tipo MIME: ' . $imageMimeType);
                    $imageExtension = $this->getImageExtensionFromMimeType($imageMimeType);

                    if (!$imageExtension) {
                        throw new \Exception('Formato de imagen no soportado. Use PNG, JPG o WEBP.');
                    }

                    $safeImagename = $this->slugger->slug($nombre . '-playlist');
                    $newImagename = $safeImagename . '-' . uniqid() . '.' . $imageExtension;

                    $imagesPath = $this->getUploadsPath('images');
                    $imagePath = $imagesPath . '/' . $newImagename;
                    error_log('Guardando imagen en: ' . $imagePath);
                    if (!file_put_contents($imagePath, $imageData)) {
                        throw new \Exception('No se pudo guardar la imagen.');
                    }

                    $oldImageUrl = $playlist->getImagen();
                    if ($oldImageUrl && strpos($oldImageUrl, '/uploads/images/') === 0) {
                        $oldImagename = basename($oldImageUrl);
                        $oldImagePath = $imagesPath . '/' . $oldImagename;
                        if (file_exists($oldImagePath)) {
                            unlink($oldImagePath);
                            error_log('Imagen anterior eliminada: ' . $oldImagePath);
                        }
                    }

                    $playlist->setImagen('/uploads/images/' . $newImagename);
                    error_log('Nueva URL de imagen establecida: /uploads/images/' . $newImagename);
                } catch (\Exception $e) {
                    error_log('Error al procesar la imagen: ' . $e->getMessage());
                    $this->addFlash('warning', 'Error al procesar la imagen: ' . $e->getMessage());
                }
            } elseif (!$mantenerImagen && $playlist->getImagen()) {

                $oldImageUrl = $playlist->getImagen();
                if ($oldImageUrl && strpos($oldImageUrl, '/uploads/images/') === 0) {
                    $oldImagename = basename($oldImageUrl);
                    $oldImagePath = $this->getUploadsPath('images') . '/' . $oldImagename;
                    if (file_exists($oldImagePath)) {
                        unlink($oldImagePath);
                        error_log('Imagen eliminada porque no se mantiene: ' . $oldImagePath);
                    }
                }
                $playlist->setImagen(null);
                error_log('URL de imagen establecida a null');
            }

            try {
                $this->entityManager->flush();
                error_log('Cambios guardados en la base de datos');
                $this->addFlash('success', 'Playlist actualizada correctamente');

                error_log('Redirigiendo a detalle de playlist: ' . $id);
                return $this->redirectToRoute('musica_playlist_detalle', ['id' => $id]);
            } catch (\Exception $e) {
                error_log('Error al guardar cambios: ' . $e->getMessage());
                $this->addFlash('error', 'Error al guardar los cambios: ' . $e->getMessage());
            }
        }

        error_log('Renderizando formulario de edición para playlist: ' . $id);
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

        $imagenUrl = $playlist->getImagen();
        if ($imagenUrl && strpos($imagenUrl, '/uploads/images/') === 0) {
            $imagenFilename = basename($imagenUrl);
            $imagenFilePath = $this->getUploadsPath('images') . '/' . $imagenFilename;
            if (file_exists($imagenFilePath)) {
                unlink($imagenFilePath);
            }
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

    #[Route('/admin/nueva-cancion', name: 'musica_admin_nueva_cancion', methods: ['GET', 'POST'])]
    public function nuevaCancion(Request $request): Response
    {
        $checkResult = $this->checkAdmin();
        if ($checkResult) {
            return $checkResult;
        }

        $cancion = new Cancion();
        $generos = $this->generoRepository->findAllOrdered();

        if ($request->isMethod('POST')) {

            error_log('Request data: ' . print_r($request->request->all(), true));

            if (!$this->isCsrfTokenValid('nueva-cancion', $request->request->get('_token'))) {
                return $this->handleError('Token CSRF inválido.', $request);
            }

            $titulo = $request->request->get('titulo', '');
            $artista = $request->request->get('artista', '');
            $album = $request->request->get('album', '');
            $descripcion = $request->request->get('descripcion', '');
            $generoId = $request->request->get('genero');
            $anio = $request->request->get('anio');
            $duracion = $request->request->get('duracion');
            $esPublico = $request->request->has('esPublico');
            $audioBase64 = $request->request->get('audioBase64');
            $imagenBase64 = $request->request->get('imagenBase64');

            if (empty($titulo)) {
                return $this->handleError('El título es obligatorio.', $request);
            }
            if (empty($audioBase64)) {
                return $this->handleError('No se recibió el archivo de audio.', $request);
            }

            $cancion->setTitulo($titulo);
            $cancion->setArtista($artista);
            $cancion->setAlbum($album);
            $cancion->setDescripcion($descripcion);
            $cancion->setAnio($anio ? (int)$anio : null);
            $cancion->setDuracion($duracion ? (int)$duracion : null);
            $cancion->setEsPublico($esPublico);

            try {

                $audioData = base64_decode($audioBase64);
                if ($audioData === false) {
                    throw new \Exception('No se pudo decodificar el archivo de audio.');
                }

                $fileSize = strlen($audioData);
                if ($fileSize > 20 * 1024 * 1024) {
                    throw new \Exception('El archivo excede el tamaño máximo de 20MB.');
                }

                $finfo = new \finfo(FILEINFO_MIME_TYPE);
                $mimeType = $finfo->buffer($audioData);
                $extension = $this->getExtensionFromMimeType($mimeType);

                $safeFilename = $this->slugger->slug($titulo);
                $newFilename = $safeFilename . '-' . uniqid() . '.' . $extension;

                $musicPath = $this->getUploadsPath('music');
                $filePath = $musicPath . '/' . $newFilename;
                if (!file_put_contents($filePath, $audioData)) {
                    throw new \Exception('No se pudo guardar el archivo de audio.');
                }

                $cancion->setUrl('/uploads/music/' . $newFilename);
            } catch (\Exception $e) {
                error_log('Error al procesar el archivo de audio: ' . $e->getMessage());
                return $this->handleError('Error al procesar el archivo de audio: ' . $e->getMessage(), $request);
            }

            if (!empty($imagenBase64)) {
                try {

                    $imageData = base64_decode($imagenBase64);
                    if ($imageData === false) {
                        throw new \Exception('No se pudo decodificar la imagen.');
                    }

                    $imageSize = strlen($imageData);
                    if ($imageSize > 2 * 1024 * 1024) {
                        throw new \Exception('La imagen excede el tamaño máximo de 2MB.');
                    }

                    $finfo = new \finfo(FILEINFO_MIME_TYPE);
                    $imageMimeType = $finfo->buffer($imageData);
                    $imageExtension = $this->getImageExtensionFromMimeType($imageMimeType);

                    if (!$imageExtension) {
                        throw new \Exception('Formato de imagen no soportado. Use PNG, JPG o WEBP.');
                    }

                    $safeImagename = $this->slugger->slug($titulo . '-cover');
                    $newImagename = $safeImagename . '-' . uniqid() . '.' . $imageExtension;

                    $imagesPath = $this->getUploadsPath('images');
                    $imagePath = $imagesPath . '/' . $newImagename;
                    if (!file_put_contents($imagePath, $imageData)) {
                        throw new \Exception('No se pudo guardar la imagen.');
                    }

                    $cancion->setImagen('/uploads/images/' . $newImagename);
                } catch (\Exception $e) {
                    error_log('Error al procesar la imagen: ' . $e->getMessage());

                    if ($request->isXmlHttpRequest()) {

                    } else {
                        $this->addFlash('warning', 'Error al procesar la imagen: ' . $e->getMessage());
                    }
                }
            }

            if ($generoId) {
                $genero = $this->generoRepository->find($generoId);
                if ($genero) {
                    $cancion->setGenero($genero);
                }
            }

            $this->entityManager->persist($cancion);
            $this->entityManager->flush();

            if ($request->isXmlHttpRequest()) {
                return $this->json([
                    'success' => true,
                    'message' => 'Canción creada correctamente.',
                    'redirect' => $this->generateUrl('musica_admin')
                ]);
            }

            $this->addFlash('success', 'Canción creada correctamente.');
            return $this->redirectToRoute('musica_admin');
        }

        return $this->render('@ModuloMusica/admin/nueva_cancion.html.twig', [
            'cancion' => $cancion,
            'generos' => $generos,
            'user' => $this->ipAuthService->getCurrentUser()
        ]);
    }

    private function handleError(string $message, Request $request): Response
    {
        if ($request->isXmlHttpRequest()) {
            return $this->json([
                'success' => false,
                'message' => $message
            ], 400);
        }

        $this->addFlash('error', $message);
        return $this->redirectToRoute('musica_admin_nueva_cancion');
    }

    #[Route('/admin/editar-cancion/{id}', name: 'musica_admin_editar_cancion')]
    public function editarCancion(int $id, Request $request): Response
    {
        $checkResult = $this->checkAdmin();
        if ($checkResult) {
            return $checkResult;
        }

        $cancion = $this->cancionRepository->find($id);
        if (!$cancion) {
            throw $this->createNotFoundException('La canción no existe');
        }

        $generos = $this->generoRepository->findAllOrdered();

        if ($request->isMethod('POST')) {
            $titulo = $request->request->get('titulo');
            $artista = $request->request->get('artista');
            $album = $request->request->get('album');
            $descripcion = $request->request->get('descripcion');
            $generoId = $request->request->get('genero');
            $anio = $request->request->get('anio');
            $duracion = $request->request->get('duracion');
            $esPublico = $request->request->has('esPublico');
            $mantenerAudio = $request->request->has('mantenerAudio');
            $audioBase64 = $request->request->get('audioBase64');
            $imagenBase64 = $request->request->get('imagenBase64');
            $mantenerImagen = $request->request->has('mantenerImagen');

            $cancion->setTitulo($titulo);
            $cancion->setArtista($artista);
            $cancion->setAlbum($album);
            $cancion->setDescripcion($descripcion);
            $cancion->setAnio($anio ? (int)$anio : null);
            $cancion->setDuracion($duracion ? (int)$duracion : null);
            $cancion->setEsPublico($esPublico);
            $cancion->setActualizadoEn(new \DateTimeImmutable());

            if ($audioBase64) {
                try {
                    $audioData = base64_decode($audioBase64);
                    if ($audioData === false) {
                        throw new \Exception('No se pudo decodificar el archivo de audio.');
                    }

                    $fileSize = strlen($audioData);
                    if ($fileSize > 20 * 1024 * 1024) {
                        throw new \Exception('El archivo excede el tamaño máximo de 20MB.');
                    }

                    $finfo = new \finfo(FILEINFO_MIME_TYPE);
                    $mimeType = $finfo->buffer($audioData);
                    $extension = $this->getExtensionFromMimeType($mimeType);

                    $safeFilename = $this->slugger->slug($titulo);
                    $newFilename = $safeFilename . '-' . uniqid() . '.' . $extension;

                    $musicPath = $this->getUploadsPath('music');
                    $filePath = $musicPath . '/' . $newFilename;
                    if (!file_put_contents($filePath, $audioData)) {
                        throw new \Exception('No se pudo guardar el archivo de audio.');
                    }

                    $oldUrl = $cancion->getUrl();
                    if ($oldUrl && strpos($oldUrl, '/uploads/music/') === 0) {
                        $oldFilename = basename($oldUrl);
                        $oldFilePath = $musicPath . '/' . $oldFilename;
                        if (file_exists($oldFilePath)) {
                            unlink($oldFilePath);
                        }
                    }

                    $cancion->setUrl('/uploads/music/' . $newFilename);
                } catch (\Exception $e) {
                    $this->addFlash('error', 'Error al procesar el archivo de audio: ' . $e->getMessage());
                    return $this->render('@ModuloMusica/admin/editar_cancion.html.twig', [
                        'cancion' => $cancion,
                        'generos' => $generos,
                        'user' => $this->ipAuthService->getCurrentUser()
                    ]);
                }
            } elseif (!$mantenerAudio) {
                $oldUrl = $cancion->getUrl();
                if ($oldUrl && strpos($oldUrl, '/uploads/music/') === 0) {
                    $oldFilename = basename($oldUrl);
                    $oldFilePath = $this->getUploadsPath('music') . '/' . $oldFilename;
                    if (file_exists($oldFilePath)) {
                        unlink($oldFilePath);
                    }
                }
                $cancion->setUrl(null);
            }

            if ($imagenBase64) {
                try {
                    $imageData = base64_decode($imagenBase64);
                    if ($imageData === false) {
                        throw new \Exception('No se pudo decodificar la imagen.');
                    }

                    $imageSize = strlen($imageData);
                    if ($imageSize > 2 * 1024 * 1024) {
                        throw new \Exception('La imagen excede el tamaño máximo de 2MB.');
                    }

                    $finfo = new \finfo(FILEINFO_MIME_TYPE);
                    $imageMimeType = $finfo->buffer($imageData);
                    $imageExtension = $this->getImageExtensionFromMimeType($imageMimeType);

                    if (!$imageExtension) {
                        throw new \Exception('Formato de imagen no soportado. Use PNG, JPG o WEBP.');
                    }

                    $safeImagename = $this->slugger->slug($titulo . '-cover');
                    $newImagename = $safeImagename . '-' . uniqid() . '.' . $imageExtension;

                    $imagesPath = $this->getUploadsPath('images');
                    $imagePath = $imagesPath . '/' . $newImagename;
                    if (!file_put_contents($imagePath, $imageData)) {
                        throw new \Exception('No se pudo guardar la imagen.');
                    }

                    $oldImageUrl = $cancion->getImagen();
                    if ($oldImageUrl && strpos($oldImageUrl, '/uploads/images/') === 0) {
                        $oldImagename = basename($oldImageUrl);
                        $oldImagePath = $imagesPath . '/' . $oldImagename;
                        if (file_exists($oldImagePath)) {
                            unlink($oldImagePath);
                        }
                    }

                    $cancion->setImagen('/uploads/images/' . $newImagename);
                } catch (\Exception $e) {
                    $this->addFlash('warning', 'Error al procesar la imagen: ' . $e->getMessage());
                }
            } elseif (!$mantenerImagen && $cancion->getImagen()) {

                $oldImageUrl = $cancion->getImagen();
                if ($oldImageUrl && strpos($oldImageUrl, '/uploads/images/') === 0) {
                    $oldImagename = basename($oldImageUrl);
                    $oldImagePath = $this->getUploadsPath('images') . '/' . $oldImagename;
                    if (file_exists($oldImagePath)) {
                        unlink($oldImagePath);
                    }
                }
                $cancion->setImagen(null);
            }

            if ($generoId) {
                $genero = $this->generoRepository->find($generoId);
                if ($genero) {
                    $cancion->setGenero($genero);
                }
            } else {
                $cancion->setGenero(null);
            }

            $this->entityManager->flush();

            $this->addFlash('success', 'Canción actualizada correctamente.');
            return $this->redirectToRoute('musica_admin');
        }

        return $this->render('@ModuloMusica/admin/editar_cancion.html.twig', [
            'cancion' => $cancion,
            'generos' => $generos,
            'user' => $this->ipAuthService->getCurrentUser()
        ]);
    }

    #[Route('/admin/nuevo-genero', name: 'musica_admin_nuevo_genero')]
    public function nuevoGenero(Request $request): Response
    {
        $checkResult = $this->checkAdmin();
        if ($checkResult) {
            return $checkResult;
        }

        if ($request->isMethod('POST')) {
            $nombre = $request->request->get('nombre');
            $descripcion = $request->request->get('descripcion');
            $icono = $request->request->get('icono');

            $genero = new Genero();
            $genero->setNombre($nombre);
            $genero->setDescripcion($descripcion);
            $genero->setIcono($icono);

            $this->entityManager->persist($genero);
            $this->entityManager->flush();

            $this->addFlash('success', 'Género creado correctamente.');
            return $this->redirectToRoute('musica_admin');
        }

        return $this->render('@ModuloMusica/admin/nuevo_genero.html.twig', [
            'user' => $this->ipAuthService->getCurrentUser()
        ]);
    }

    #[Route('/admin/editar-genero/{id}', name: 'musica_admin_editar_genero')]
    public function editarGenero(int $id, Request $request): Response
    {
        $checkResult = $this->checkAdmin();
        if ($checkResult) {
            return $checkResult;
        }

        $genero = $this->generoRepository->find($id);
        if (!$genero) {
            throw $this->createNotFoundException('El género no existe');
        }

        if ($request->isMethod('POST')) {
            $nombre = $request->request->get('nombre');
            $descripcion = $request->request->get('descripcion');
            $icono = $request->request->get('icono');

            $genero->setNombre($nombre);
            $genero->setDescripcion($descripcion);
            $genero->setIcono($icono);

            $this->entityManager->flush();

            $this->addFlash('success', 'Género actualizado correctamente.');
            return $this->redirectToRoute('musica_admin');
        }

        return $this->render('@ModuloMusica/admin/editar_genero.html.twig', [
            'genero' => $genero,
            'user' => $this->ipAuthService->getCurrentUser()
        ]);
    }

    #[Route('/admin/eliminar-cancion/{id}', name: 'musica_admin_eliminar_cancion', methods: ['POST'])]
    public function eliminarCancion(int $id): Response
    {
        $checkResult = $this->checkAdmin();
        if ($checkResult) {
            return $checkResult;
        }

        $cancion = $this->cancionRepository->find($id);
        if (!$cancion) {
            throw $this->createNotFoundException('La canción no existe');
        }

        $tituloCancion = $cancion->getTitulo();

        $this->eliminarArchivoCancion($cancion->getUrl());

        $this->eliminarArchivoCancion($cancion->getImagen());

        $this->entityManager->remove($cancion);
        $this->entityManager->flush();

        $this->addFlash('success', 'Canción "' . $tituloCancion . '" eliminada correctamente');
        return $this->redirectToRoute('musica_admin');
    }

    private function eliminarArchivoCancion(?string $ruta): void
    {
        if ($ruta && strpos($ruta, '/uploads/') === 0) {
            $rutaCompleta = $this->getParameter('kernel.project_dir') . '/public' . $ruta;
            if (file_exists($rutaCompleta)) {
                unlink($rutaCompleta);
            }
        }
    }

    #[Route('/admin/eliminar-genero/{id}', name: 'musica_admin_eliminar_genero', methods: ['POST'])]
    public function eliminarGenero(int $id): Response
    {
        $checkResult = $this->checkAdmin();
        if ($checkResult) {
            return $checkResult;
        }

        $genero = $this->generoRepository->find($id);
        if (!$genero) {
            throw $this->createNotFoundException('El género no existe');
        }

        if (!$genero->getCanciones()->isEmpty()) {
            $this->addFlash('error', 'No se puede eliminar el género porque tiene canciones asociadas.');
            return $this->redirectToRoute('musica_admin');
        }

        $this->entityManager->remove($genero);
        $this->entityManager->flush();

        $this->addFlash('success', 'Género eliminado correctamente.');
        return $this->redirectToRoute('musica_admin');
    }

    private function getExtensionFromMimeType(string $mimeType): string
    {
        $mimeToExtension = [
            'audio/mpeg' => 'mp3',
            'audio/mp4' => 'm4a',
            'audio/ogg' => 'ogg',
            'audio/wav' => 'wav',
        ];

        return $mimeToExtension[$mimeType] ?? 'mp3';
    }

    private function getImageExtensionFromMimeType(string $mimeType): ?string
    {
        $mimeToExtension = [
            'image/jpeg' => 'jpg',
            'image/jpg' => 'jpg',
            'image/png' => 'png',
            'image/webp' => 'webp',
        ];

        return $mimeToExtension[$mimeType] ?? null;
    }

    private function getUploadsPath(string $type = 'images'): string
    {
        $basePath = $this->getParameter('kernel.project_dir') . '/public/uploads';
        $typePath = $basePath . '/' . $type;

        if (!is_dir($basePath)) {
            if (!mkdir($basePath, 0755, true)) {
                throw new \RuntimeException('No se pudo crear el directorio de uploads: ' . $basePath);
            }
        }

        if (!is_dir($typePath)) {
            if (!mkdir($typePath, 0755, true)) {
                throw new \RuntimeException('No se pudo crear el directorio: ' . $typePath);
            }
        }

        if (!is_writable($typePath)) {
            if (!chmod($typePath, 0755)) {
                throw new \RuntimeException('No se pudieron establecer permisos de escritura en: ' . $typePath);
            }
        }

        return $typePath;
    }
}