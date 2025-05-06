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
        SluggerInterface $slugger,
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

    #[Route('/nueva-cancion', name: 'musica_admin_nueva_cancion', methods: ['GET', 'POST'])]
    public function nuevaCancion(Request $request): Response
    {
        $checkResult = $this->checkAdmin();
        if ($checkResult) {
            return $checkResult;
        }

        $cancion = new Cancion();
        $generos = $this->generoRepository->findAllOrdered();

        if ($request->isMethod('POST')) {
            // Log request data for debugging
            error_log('Request data: ' . print_r($request->request->all(), true));

            // Verify CSRF token
            if (!$this->isCsrfTokenValid('nueva-cancion', $request->request->get('_token'))) {
                return $this->handleError('Token CSRF inválido.', $request);
            }

            // Get form data
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

            // Validate required fields
            if (empty($titulo)) {
                return $this->handleError('El título es obligatorio.', $request);
            }
            if (empty($audioBase64)) {
                return $this->handleError('No se recibió el archivo de audio.', $request);
            }

            // Set song properties
            $cancion->setTitulo($titulo);
            $cancion->setArtista($artista);
            $cancion->setAlbum($album);
            $cancion->setDescripcion($descripcion);
            $cancion->setAnio($anio ? (int)$anio : null);
            $cancion->setDuracion($duracion ? (int)$duracion : null);
            $cancion->setEsPublico($esPublico);

            // Handle Base64 audio file
            try {
                // Decode Base64 string
                $audioData = base64_decode($audioBase64);
                if ($audioData === false) {
                    throw new \Exception('No se pudo decodificar el archivo de audio.');
                }

                // Validate file size (20MB)
                $fileSize = strlen($audioData);
                if ($fileSize > 20 * 1024 * 1024) {
                    throw new \Exception('El archivo excede el tamaño máximo de 20MB.');
                }

                // Determine file extension based on MIME type or default to mp3
                $finfo = new \finfo(FILEINFO_MIME_TYPE);
                $mimeType = $finfo->buffer($audioData);
                $extension = $this->getExtensionFromMimeType($mimeType);

                // Generate a safe filename
                $safeFilename = $this->slugger->slug($titulo);
                $newFilename = $safeFilename . '-' . uniqid() . '.' . $extension;

                // Save the file
                $musicPath = $this->getUploadsPath('music');
                $filePath = $musicPath . '/' . $newFilename;
                if (!file_put_contents($filePath, $audioData)) {
                    throw new \Exception('No se pudo guardar el archivo de audio.');
                }

                // Set the URL in the entity
                $cancion->setUrl('/uploads/music/' . $newFilename);
            } catch (\Exception $e) {
                error_log('Error al procesar el archivo de audio: ' . $e->getMessage());
                return $this->handleError('Error al procesar el archivo de audio: ' . $e->getMessage(), $request);
            }
            
            // Procesar imagen si se proporcionó
            if (!empty($imagenBase64)) {
                try {
                    // Decodificar Base64
                    $imageData = base64_decode($imagenBase64);
                    if ($imageData === false) {
                        throw new \Exception('No se pudo decodificar la imagen.');
                    }
                    
                    // Validar tamaño (2MB)
                    $imageSize = strlen($imageData);
                    if ($imageSize > 2 * 1024 * 1024) {
                        throw new \Exception('La imagen excede el tamaño máximo de 2MB.');
                    }
                    
                    // Determinar extensión basada en MIME
                    $finfo = new \finfo(FILEINFO_MIME_TYPE);
                    $imageMimeType = $finfo->buffer($imageData);
                    $imageExtension = $this->getImageExtensionFromMimeType($imageMimeType);
                    
                    if (!$imageExtension) {
                        throw new \Exception('Formato de imagen no soportado. Use PNG, JPG o WEBP.');
                    }
                    
                    // Generar nombre seguro
                    $safeImagename = $this->slugger->slug($titulo . '-cover');
                    $newImagename = $safeImagename . '-' . uniqid() . '.' . $imageExtension;
                    
                    // Guardar el archivo
                    $imagesPath = $this->getUploadsPath('images');
                    $imagePath = $imagesPath . '/' . $newImagename;
                    if (!file_put_contents($imagePath, $imageData)) {
                        throw new \Exception('No se pudo guardar la imagen.');
                    }
                    
                    // Establecer URL en la entidad
                    $cancion->setImagen('/uploads/images/' . $newImagename);
                } catch (\Exception $e) {
                    error_log('Error al procesar la imagen: ' . $e->getMessage());
                    // Continuar aunque haya error en la imagen, solo mostrar advertencia
                    if ($request->isXmlHttpRequest()) {
                        // En caso de AJAX, ignoramos el error de imagen y continuamos
                    } else {
                        $this->addFlash('warning', 'Error al procesar la imagen: ' . $e->getMessage());
                    }
                }
            }

            // Set genre if provided
            if ($generoId) {
                $genero = $this->generoRepository->find($generoId);
                if ($genero) {
                    $cancion->setGenero($genero);
                }
            }

            // Persist the song
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

    private function getUploadsPath(string $type = 'music'): string
    {
        $basePath = $this->getParameter('kernel.project_dir') . '/public/uploads';
        $typePath = $basePath . '/' . $type;
        
        // Crear directorio base si no existe
        if (!is_dir($basePath)) {
            if (!mkdir($basePath, 0755, true)) {
                throw new \RuntimeException('No se pudo crear el directorio de uploads: ' . $basePath);
            }
        }
        
        // Crear directorio específico si no existe
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

            // Handle audio file if provided
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

                    // Delete old file if exists
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
            
            // Procesar imagen nueva si se proporcionó
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
                    
                    // Eliminar imagen anterior si existe
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
                // Eliminar imagen actual si no se quiere mantener
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

        // Eliminar archivo de audio
        $url = $cancion->getUrl();
        if ($url && strpos($url, '/uploads/music/') === 0) {
            $filename = basename($url);
            $filePath = $this->getUploadsPath('music') . '/' . $filename;
            if (file_exists($filePath)) {
                unlink($filePath);
            }
        }
        
        // Eliminar archivo de imagen
        $imagenUrl = $cancion->getImagen();
        if ($imagenUrl && strpos($imagenUrl, '/uploads/images/') === 0) {
            $imagenFilename = basename($imagenUrl);
            $imagenFilePath = $this->getUploadsPath('images') . '/' . $imagenFilename;
            if (file_exists($imagenFilePath)) {
                unlink($imagenFilePath);
            }
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