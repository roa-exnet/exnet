<?php

namespace App\ModuloStreaming\Controller;

use App\ModuloCore\Service\IpAuthService;
use App\ModuloStreaming\Entity\Video;
use App\ModuloStreaming\Entity\Categoria;
use App\ModuloStreaming\Repository\VideoRepository;
use App\ModuloStreaming\Repository\CategoryRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;

#[Route('/streaming')]
class StreamingController extends AbstractController
{
    private EntityManagerInterface $entityManager;
    private IpAuthService $ipAuthService;
    private VideoRepository $videoRepository;
    private CategoryRepository $categoryRepository;

    public function __construct(
        EntityManagerInterface $entityManager,
        IpAuthService $ipAuthService,
        VideoRepository $videoRepository,
        CategoryRepository $categoryRepository
    ) {
        $this->entityManager = $entityManager;
        $this->ipAuthService = $ipAuthService;
        $this->videoRepository = $videoRepository;
        $this->categoryRepository = $categoryRepository;
    }

    #[Route('', name: 'streaming_index')]
    public function index(Request $request): Response
    {
        // Verificar autenticación por IP
        $user = $this->ipAuthService->getCurrentUser();
        if (!$user) {
            return $this->redirectToRoute('app_register_ip', [
                'redirect' => $this->generateUrl('streaming_index')
            ]);
        }

        // Parámetros para filtrar
        $categoriaId = $request->query->get('categoria');
        $tipo = $request->query->get('tipo');
        $busqueda = $request->query->get('q');

        // Buscar videos según filtros
        if ($busqueda) {
            $videos = $this->videoRepository->findBySearch($busqueda);
        } else {
            $videos = $this->videoRepository->findByCategoriaAndType($categoriaId, $tipo);
        }

        // Obtener todas las categorías para el menú de filtros
        $categorias = $this->categoryRepository->findAllOrdered();

        return $this->render('@ModuloStreaming/index.html.twig', [
            'videos' => $videos,
            'categorias' => $categorias,
            'categoriaActual' => $categoriaId,
            'tipoActual' => $tipo,
            'busqueda' => $busqueda,
            'user' => $user
        ]);
    }

    #[Route('/video/{id}', name: 'streaming_video_detalle')]
    public function detalleVideo(int $id): Response
    {
        // Verificar autenticación por IP
        $user = $this->ipAuthService->getCurrentUser();
        if (!$user) {
            return $this->redirectToRoute('app_register_ip', [
                'redirect' => $this->generateUrl('streaming_video_detalle', ['id' => $id])
            ]);
        }

        $video = $this->videoRepository->find($id);
        if (!$video) {
            throw $this->createNotFoundException('El video no existe');
        }

        // Si es una serie, obtener todos los episodios
        $episodios = null;
        $temporadas = null;
        if ($video->getTipo() === 'serie') {
            $episodios = $this->videoRepository->findSerieEpisodes($video->getTitulo());
            try {
                $temporadas = $this->videoRepository->getSeriesTemporadas($video->getTitulo());
            } catch (\Exception $e) {
                $temporadas = [];
            }
        }

        return $this->render('@ModuloStreaming/detalle.html.twig', [
            'video' => $video,
            'episodios' => $episodios,
            'temporadas' => $temporadas,
            'user' => $user
        ]);
    }

    #[Route('/series', name: 'streaming_series')]
    public function series(): Response
    {
        // Verificar autenticación por IP
        $user = $this->ipAuthService->getCurrentUser();
        if (!$user) {
            return $this->redirectToRoute('app_register_ip', [
                'redirect' => $this->generateUrl('streaming_series')
            ]);
        }

        $series = $this->videoRepository->findAllSeries();
        $categorias = $this->categoryRepository->findAllOrdered();

        return $this->render('@ModuloStreaming/series.html.twig', [
            'series' => $series,
            'categorias' => $categorias,
            'user' => $user
        ]);
    }

    #[Route('/peliculas', name: 'streaming_peliculas')]
    public function peliculas(): Response
    {
        // Verificar autenticación por IP
        $user = $this->ipAuthService->getCurrentUser();
        if (!$user) {
            return $this->redirectToRoute('app_register_ip', [
                'redirect' => $this->generateUrl('streaming_peliculas')
            ]);
        }

        $peliculas = $this->videoRepository->findAllPeliculas();
        $categorias = $this->categoryRepository->findAllOrdered();

        return $this->render('@ModuloStreaming/peliculas.html.twig', [
            'peliculas' => $peliculas,
            'categorias' => $categorias,
            'user' => $user
        ]);
    }

    #[Route('/admin', name: 'streaming_admin')]
    public function admin(): Response
    {
        // Verificar autenticación por IP
        $user = $this->ipAuthService->getCurrentUser();
        if (!$user) {
            return $this->redirectToRoute('app_register_ip', [
                'redirect' => $this->generateUrl('streaming_admin')
            ]);
        }

        // Verificar si el usuario es administrador
        if (!in_array('ROLE_ADMIN', $user->getRoles())) {
            throw $this->createAccessDeniedException('No tienes permisos para acceder a esta sección');
        }

        $videos = $this->videoRepository->findAll();
        $categorias = $this->categoryRepository->findWithVideoCount();

        return $this->render('@ModuloStreaming/admin.html.twig', [
            'videos' => $videos,
            'categorias' => $categorias,
            'user' => $user
        ]);
    }

    #[Route('/reproducir/{id}', name: 'streaming_reproducir')]
    public function reproducir(int $id): Response
    {
        // Verificar autenticación por IP
        $user = $this->ipAuthService->getCurrentUser();
        if (!$user) {
            return $this->redirectToRoute('app_register_ip', [
                'redirect' => $this->generateUrl('streaming_reproducir', ['id' => $id])
            ]);
        }

        $video = $this->videoRepository->find($id);
        if (!$video) {
            throw $this->createNotFoundException('El video no existe');
        }

        return $this->render('@ModuloStreaming/reproducir.html.twig', [
            'video' => $video,
            'user' => $user
        ]);
    }
}