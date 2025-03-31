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
use Symfony\Component\String\Slugger\SluggerInterface;

#[Route('/streaming/admin')]
class AdminStreamingController extends AbstractController
{
    private EntityManagerInterface $entityManager;
    private IpAuthService $ipAuthService;
    private VideoRepository $videoRepository;
    private CategoryRepository $categoryRepository;
    private SluggerInterface $slugger;

    public function __construct(
        EntityManagerInterface $entityManager,
        IpAuthService $ipAuthService,
        VideoRepository $videoRepository,
        CategoryRepository $categoryRepository,
        SluggerInterface $slugger
    ) {
        $this->entityManager = $entityManager;
        $this->ipAuthService = $ipAuthService;
        $this->videoRepository = $videoRepository;
        $this->categoryRepository = $categoryRepository;
        $this->slugger = $slugger;
    }

    private function checkAdmin(): ?Response
    {
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

        return null;
    }

    #[Route('/nuevo-video', name: 'streaming_admin_nuevo_video')]
    public function nuevoVideo(Request $request): Response
    {
        $checkResult = $this->checkAdmin();
        if ($checkResult) {
            return $checkResult;
        }

        $video = new Video();
        $categorias = $this->categoryRepository->findAllOrdered();

        if ($request->isMethod('POST')) {
            $titulo = $request->request->get('titulo');
            $descripcion = $request->request->get('descripcion');
            $tipo = $request->request->get('tipo');
            $url = $request->request->get('url');
            $imagen = $request->request->get('imagen');
            $categoriaId = $request->request->get('categoria');
            $anio = $request->request->get('anio');
            $temporada = $request->request->get('temporada');
            $episodio = $request->request->get('episodio');
            $esPublico = $request->request->has('esPublico');

            $video->setTitulo($titulo);
            $video->setDescripcion($descripcion);
            $video->setTipo($tipo);
            $video->setUrl($url);
            $video->setImagen($imagen);
            $video->setAnio($anio ? (int)$anio : null);
            $video->setEsPublico($esPublico);

            if ($tipo === 'serie') {
                $video->setTemporada($temporada ? (int)$temporada : null);
                $video->setEpisodio($episodio ? (int)$episodio : null);
            }

            if ($categoriaId) {
                $categoria = $this->categoryRepository->find($categoriaId);
                if ($categoria) {
                    $video->setCategoria($categoria);
                }
            }

            $this->entityManager->persist($video);
            $this->entityManager->flush();

            $this->addFlash('success', 'Video creado correctamente.');
            return $this->redirectToRoute('streaming_admin');
        }

        return $this->render('@ModuloStreaming/admin/nuevo_video.html.twig', [
            'video' => $video,
            'categorias' => $categorias,
            'user' => $this->ipAuthService->getCurrentUser()
        ]);
    }

    #[Route('/editar-video/{id}', name: 'streaming_admin_editar_video')]
    public function editarVideo(int $id, Request $request): Response
    {
        $checkResult = $this->checkAdmin();
        if ($checkResult) {
            return $checkResult;
        }

        $video = $this->videoRepository->find($id);
        if (!$video) {
            throw $this->createNotFoundException('El video no existe');
        }

        $categorias = $this->categoryRepository->findAllOrdered();

        if ($request->isMethod('POST')) {
            $titulo = $request->request->get('titulo');
            $descripcion = $request->request->get('descripcion');
            $tipo = $request->request->get('tipo');
            $url = $request->request->get('url');
            $imagen = $request->request->get('imagen');
            $categoriaId = $request->request->get('categoria');
            $anio = $request->request->get('anio');
            $temporada = $request->request->get('temporada');
            $episodio = $request->request->get('episodio');
            $esPublico = $request->request->has('esPublico');

            $video->setTitulo($titulo);
            $video->setDescripcion($descripcion);
            $video->setTipo($tipo);
            $video->setUrl($url);
            $video->setImagen($imagen);
            $video->setAnio($anio ? (int)$anio : null);
            $video->setEsPublico($esPublico);
            $video->setActualizadoEn(new \DateTimeImmutable());

            if ($tipo === 'serie') {
                $video->setTemporada($temporada ? (int)$temporada : null);
                $video->setEpisodio($episodio ? (int)$episodio : null);
            } else {
                $video->setTemporada(null);
                $video->setEpisodio(null);
            }

            if ($categoriaId) {
                $categoria = $this->categoryRepository->find($categoriaId);
                if ($categoria) {
                    $video->setCategoria($categoria);
                }
            } else {
                $video->setCategoria(null);
            }

            $this->entityManager->flush();

            $this->addFlash('success', 'Video actualizado correctamente.');
            return $this->redirectToRoute('streaming_admin');
        }

        return $this->render('@ModuloStreaming/admin/editar_video.html.twig', [
            'video' => $video,
            'categorias' => $categorias,
            'user' => $this->ipAuthService->getCurrentUser()
        ]);
    }

    #[Route('/eliminar-video/{id}', name: 'streaming_admin_eliminar_video', methods: ['POST'])]
    public function eliminarVideo(int $id): Response
    {
        $checkResult = $this->checkAdmin();
        if ($checkResult) {
            return $checkResult;
        }

        $video = $this->videoRepository->find($id);
        if (!$video) {
            throw $this->createNotFoundException('El video no existe');
        }

        $this->entityManager->remove($video);
        $this->entityManager->flush();

        $this->addFlash('success', 'Video eliminado correctamente.');
        return $this->redirectToRoute('streaming_admin');
    }

    #[Route('/nueva-categoria', name: 'streaming_admin_nueva_categoria')]
    public function nuevaCategoria(Request $request): Response
    {
        $checkResult = $this->checkAdmin();
        if ($checkResult) {
            return $checkResult;
        }

        if ($request->isMethod('POST')) {
            $nombre = $request->request->get('nombre');
            $descripcion = $request->request->get('descripcion');
            $icono = $request->request->get('icono');

            $categoria = new Categoria();
            $categoria->setNombre($nombre);
            $categoria->setDescripcion($descripcion);
            $categoria->setIcono($icono);

            $this->entityManager->persist($categoria);
            $this->entityManager->flush();

            $this->addFlash('success', 'Categoría creada correctamente.');
            return $this->redirectToRoute('streaming_admin');
        }

        return $this->render('@ModuloStreaming/admin/nueva_categoria.html.twig', [
            'user' => $this->ipAuthService->getCurrentUser()
        ]);
    }

    #[Route('/editar-categoria/{id}', name: 'streaming_admin_editar_categoria')]
    public function editarCategoria(int $id, Request $request): Response
    {
        $checkResult = $this->checkAdmin();
        if ($checkResult) {
            return $checkResult;
        }

        $categoria = $this->categoryRepository->find($id);
        if (!$categoria) {
            throw $this->createNotFoundException('La categoría no existe');
        }

        if ($request->isMethod('POST')) {
            $nombre = $request->request->get('nombre');
            $descripcion = $request->request->get('descripcion');
            $icono = $request->request->get('icono');

            $categoria->setNombre($nombre);
            $categoria->setDescripcion($descripcion);
            $categoria->setIcono($icono);

            $this->entityManager->flush();

            $this->addFlash('success', 'Categoría actualizada correctamente.');
            return $this->redirectToRoute('streaming_admin');
        }

        return $this->render('@ModuloStreaming/admin/editar_categoria.html.twig', [
            'categoria' => $categoria,
            'user' => $this->ipAuthService->getCurrentUser()
        ]);
    }

    #[Route('/eliminar-categoria/{id}', name: 'streaming_admin_eliminar_categoria', methods: ['POST'])]
    public function eliminarCategoria(int $id): Response
    {
        $checkResult = $this->checkAdmin();
        if ($checkResult) {
            return $checkResult;
        }

        $categoria = $this->categoryRepository->find($id);
        if (!$categoria) {
            throw $this->createNotFoundException('La categoría no existe');
        }

        // Verificar si la categoría tiene videos
        if (!$categoria->getVideos()->isEmpty()) {
            $this->addFlash('error', 'No se puede eliminar la categoría porque tiene videos asociados.');
            return $this->redirectToRoute('streaming_admin');
        }

        $this->entityManager->remove($categoria);
        $this->entityManager->flush();

        $this->addFlash('success', 'Categoría eliminada correctamente.');
        return $this->redirectToRoute('streaming_admin');
    }
}