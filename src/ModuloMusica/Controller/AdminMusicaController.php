<?php

namespace App\ModuloMusica\Controller;

use App\ModuloCore\Service\IpAuthService;
use App\ModuloMusica\Entity\Cancion;
use App\ModuloMusica\Entity\Genero;
use App\ModuloMusica\Repository\CancionRepository;
use App\ModuloMusica\Repository\GeneroRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\String\Slugger\SluggerInterface;

#[Route('/musica/admin')]
class AdminMusicaController extends AbstractController
{
    private EntityManagerInterface $entityManager;
    private IpAuthService $ipAuthService;
    private CancionRepository $cancionRepository;
    private GeneroRepository $generoRepository;
    private SluggerInterface $slugger;

    public function __construct(
        EntityManagerInterface $entityManager,
        IpAuthService $ipAuthService,
        CancionRepository $cancionRepository,
        GeneroRepository $generoRepository,
        SluggerInterface $slugger
    ) {
        $this->entityManager = $entityManager;
        $this->ipAuthService = $ipAuthService;
        $this->cancionRepository = $cancionRepository;
        $this->generoRepository = $generoRepository;
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

    #[Route('/nueva-cancion', name: 'musica_admin_nueva_cancion')]
    public function nuevaCancion(Request $request): Response
    {
        $checkResult = $this->checkAdmin();
        if ($checkResult) {
            return $checkResult;
        }

        $cancion = new Cancion();
        $generos = $this->generoRepository->findAllOrdered();

        if ($request->isMethod('POST')) {
            $titulo = $request->request->get('titulo');
            $artista = $request->request->get('artista');
            $album = $request->request->get('album');
            $descripcion = $request->request->get('descripcion');
            $url = $request->request->get('url');
            $imagen = $request->request->get('imagen');
            $generoId = $request->request->get('genero');
            $anio = $request->request->get('anio');
            $duracion = $request->request->get('duracion');
            $esPublico = $request->request->has('esPublico');

            $cancion->setTitulo($titulo);
            $cancion->setArtista($artista);
            $cancion->setAlbum($album);
            $cancion->setDescripcion($descripcion);
            $cancion->setUrl($url);
            $cancion->setImagen($imagen);
            $cancion->setAnio($anio ? (int)$anio : null);
            $cancion->setDuracion($duracion ? (int)$duracion : null);
            $cancion->setEsPublico($esPublico);

            if ($generoId) {
                $genero = $this->generoRepository->find($generoId);
                if ($genero) {
                    $cancion->setGenero($genero);
                }
            }

            $this->entityManager->persist($cancion);
            $this->entityManager->flush();

            $this->addFlash('success', 'Canción creada correctamente.');
            return $this->redirectToRoute('musica_admin');
        }

        return $this->render('@ModuloMusica/admin/nueva_cancion.html.twig', [
            'cancion' => $cancion,
            'generos' => $generos,
            'user' => $this->ipAuthService->getCurrentUser()
        ]);
    }

    #[Route('/editar-cancion/{id}', name: 'musica_admin_editar_cancion')]
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
            $url = $request->request->get('url');
            $imagen = $request->request->get('imagen');
            $generoId = $request->request->get('genero');
            $anio = $request->request->get('anio');
            $duracion = $request->request->get('duracion');
            $esPublico = $request->request->has('esPublico');

            $cancion->setTitulo($titulo);
            $cancion->setArtista($artista);
            $cancion->setAlbum($album);
            $cancion->setDescripcion($descripcion);
            $cancion->setUrl($url);
            $cancion->setImagen($imagen);
            $cancion->setAnio($anio ? (int)$anio : null);
            $cancion->setDuracion($duracion ? (int)$duracion : null);
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

            $this->addFlash('success', 'Canción actualizada correctamente.');
            return $this->redirectToRoute('musica_admin');
        }

        return $this->render('@ModuloMusica/admin/editar_cancion.html.twig', [
            'cancion' => $cancion,
            'generos' => $generos,
            'user' => $this->ipAuthService->getCurrentUser()
        ]);
    }

    #[Route('/eliminar-cancion/{id}', name: 'musica_admin_eliminar_cancion', methods: ['POST'])]
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

        $this->entityManager->remove($cancion);
        $this->entityManager->flush();

        $this->addFlash('success', 'Canción eliminada correctamente.');
        return $this->redirectToRoute('musica_admin');
    }

    #[Route('/nuevo-genero', name: 'musica_admin_nuevo_genero')]
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

    #[Route('/editar-genero/{id}', name: 'musica_admin_editar_genero')]
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

    #[Route('/eliminar-genero/{id}', name: 'musica_admin_eliminar_genero', methods: ['POST'])]
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
}