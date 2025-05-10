<?php

namespace App\ModuloExplorador\Controller;

use App\ModuloCore\Entity\User;
use App\ModuloCore\Service\IpAuthService;
use App\ModuloCore\Service\JwtAuthService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\File\File;
use Symfony\Component\HttpFoundation\File\Exception\FileNotFoundException;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\ResponseHeaderBag;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\DependencyInjection\ParameterBag\ParameterBagInterface;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;

#[Route('/kc/explorador')]
class ExplorerController extends AbstractController
{
    private $projectDir;
    private $workspaceDir;
    private $ipAuthService;
    private $jwtAuthService;

    public function __construct(
        ParameterBagInterface $parameterBag,
        IpAuthService $ipAuthService,
        JwtAuthService $jwtAuthService
    ) {
        $this->projectDir = $parameterBag->get('kernel.project_dir');
        $this->workspaceDir = $this->projectDir . '/var/workspace';
        $this->ipAuthService = $ipAuthService;
        $this->jwtAuthService = $jwtAuthService;

        if (!is_dir($this->workspaceDir)) {
            mkdir($this->workspaceDir, 0777, true);
        }
    }

    private function verifyAuthentication(Request $request): ?User
    {
        $user = $this->jwtAuthService->getAuthenticatedUser($request);

        if (!$user && $this->ipAuthService->isIpRegistered()) {
            $user = $this->ipAuthService->getCurrentUser();

            if ($user) {
                $response = new Response();
                $this->jwtAuthService->addTokenCookie($response, $user);
                $response->send();
            }
        }

        return $user;
    }

    #[Route('/archivos', name: 'explorer', methods: ['GET', 'POST'])]
    public function explorer(Request $request): Response 
    {
        $user = $this->verifyAuthentication($request);
        if (!$user) {
            return $this->redirectToRoute('app_login', [
                'redirect' => $request->getUri()
            ]);
        }

        $rootDir = $this->workspaceDir;

        $currentPath = $request->request->get('path', $rootDir);

        if (!$this->isPathWithinWorkspace($currentPath) || !is_dir($currentPath)) {
            $currentPath = $rootDir;
        }

        $fileCount = 0;
        $dirCount = 0;
        $directories = [];
        $files = [];

        if (is_dir($currentPath)) {
            $items = scandir($currentPath);
            $items = array_filter($items, function ($item) {
                return $item !== '.' && $item !== '..';
            });

            foreach ($items as $item) {
                $itemPath = $currentPath . '/' . $item;
                if (is_dir($itemPath)) {
                    $directories[] = $item;
                    $dirCount++;
                } elseif (is_file($itemPath)) {
                    $files[] = $item;
                    $fileCount++;
                }
            }
        }

        $parentDirectory = dirname($currentPath);

        if (!$this->isPathWithinWorkspace($parentDirectory)) {
            $parentDirectory = $rootDir;
        }

        return $this->render('@ModuloExplorador/gen/index.html.twig', [
            'file_count' => $fileCount,
            'dir_count' => $dirCount,
            'directory' => $currentPath,
            'parent_directory' => $parentDirectory,
            'dirsOnly' => $directories,
            'filesOnly' => $files,
            'rootDir' => $rootDir,
        ]);
    }

    #[Route('/archivos/abrir', name: 'abrir_archivo', methods: ['POST'])]
    public function abrirArchivo(Request $request): Response
    {
        $user = $this->verifyAuthentication($request);
        if (!$user) {
            throw new AccessDeniedException('Usuario no autenticado');
        }

        $directoryPath = $request->request->get('path');
        $filename = $request->request->get('filename');

        if (!$this->isPathWithinWorkspace($directoryPath)) {
            return new Response('Acceso denegado: directorio fuera del espacio de trabajo', 403);
        }

        if (file_exists($directoryPath . '/' . $filename)) {
            $filePath = $directoryPath . '/' . $filename;

            $fileMimeType = mime_content_type($filePath);
            $fileExtension = strtolower(pathinfo($filePath, PATHINFO_EXTENSION));
            $fileContent = null;
            $isImage = false;
            $isVideo = false;
            $isAudio = false;

            if (strpos($fileMimeType, 'image') === 0) {
                $isImage = true;
            } elseif (strpos($fileMimeType, 'video') === 0 || in_array($fileExtension, ['mp4', 'webm', 'ogg', 'mov', 'avi', 'mkv'])) {
                $isVideo = true;
            } elseif (strpos($fileMimeType, 'audio') === 0 || in_array($fileExtension, ['mp3', 'wav', 'ogg', 'flac'])) {
                $isAudio = true;
            } else {
                if (in_array($fileExtension, ['txt', 'md', 'json', 'xml', 'html', 'css', 'js', 'php', 'log']) && filesize($filePath) < 1024 * 1024) {
                    $fileContent = file_get_contents($filePath);
                } else {
                    $fileContent = "El archivo no se puede previsualizar.";
                }
            }

            $fileInfo = [
                'size' => filesize($filePath),
                'modified' => filemtime($filePath),
                'mime' => $fileMimeType
            ];

            return $this->render('@ModuloExplorador/gen/abrir.html.twig', [
                'filename' => $filename,
                'fileInfo' => $fileInfo,
                'fileContent' => $fileContent,
                'directory' => $directoryPath,
                'isImage' => $isImage,
                'isVideo' => $isVideo,
                'isAudio' => $isAudio,
                'fileExtension' => $fileExtension
            ]);
        } else {
            return new Response('El archivo no existe', 404);
        }
    }

    #[Route('/archivos/ver-media', name: 'ver_media', methods: ['GET'])]
    public function verMedia(Request $request): Response
    {
        $user = $this->verifyAuthentication($request);
        if (!$user) {
            throw new AccessDeniedException('Usuario no autenticado');
        }

        $directoryPath = $request->query->get('path');
        $filename = $request->query->get('filename');

        if (!$this->isPathWithinWorkspace($directoryPath)) {
            return new Response('Acceso denegado: directorio fuera del espacio de trabajo', 403);
        }

        $filePath = $directoryPath . '/' . $filename;
        if (!file_exists($filePath)) {
            throw $this->createNotFoundException('El archivo no existe.');
        }

        $response = new BinaryFileResponse($filePath);
        $response->headers->set('Content-Type', mime_content_type($filePath));
        $response->setContentDisposition(ResponseHeaderBag::DISPOSITION_INLINE, $filename);

        return $response;
    }

    #[Route('/archivos/descargar', name: 'descargar_archivo', methods: ['POST'])]
    public function descargarArchivo(Request $request): Response
    {
        $user = $this->verifyAuthentication($request);
        if (!$user) {
            throw new AccessDeniedException('Usuario no autenticado');
        }

        $filePath = $request->request->get('file_path');

        if (!$this->isPathWithinWorkspace($filePath)) {
            return new Response('Acceso denegado: archivo fuera del espacio de trabajo', 403);
        }

        $response = new BinaryFileResponse($filePath);
        $response->setContentDisposition(
            ResponseHeaderBag::DISPOSITION_ATTACHMENT,
            basename($filePath)
        );

        return $response;
    }

    #[Route('/archivos/crear-carpeta', name: 'crear_carpeta', methods: ['POST'])]
    public function crearCarpeta(Request $request): RedirectResponse
    {
        $user = $this->verifyAuthentication($request);
        if (!$user) {
            throw new AccessDeniedException('Usuario no autenticado');
        }

        $folderName = $request->request->get('folder_name');
        $directoryPath = $request->request->get('path');

        if (!$this->isPathWithinWorkspace($directoryPath)) {
            $this->addFlash('error', 'Acceso denegado: directorio fuera del espacio de trabajo');
            return $this->redirectToRoute('explorer');
        }

        if (empty($folderName) || empty($directoryPath)) {
            $this->addFlash('error', 'Faltan datos para crear la carpeta.');
            return $this->redirectToRoute('explorer', ['path' => $directoryPath]);
        }

        $newFolderPath = rtrim($directoryPath, '/') . '/' . $folderName;

        $filesystem = new Filesystem();
        if (!$filesystem->exists($newFolderPath)) {
            $filesystem->mkdir($newFolderPath);
            $this->addFlash('success', 'Carpeta creada exitosamente.');
        } else {
            $this->addFlash('error', 'La carpeta ya existe.');
        }

        return $this->redirectToRoute('explorer', ['path' => $directoryPath]);
    }

    #[Route('/archivos/eliminar-carpeta', name: 'eliminar_carpeta', methods: ['POST'])]
    public function eliminarCarpeta(Request $request): RedirectResponse
    {
        $user = $this->verifyAuthentication($request);
        if (!$user) {
            throw new AccessDeniedException('Usuario no autenticado');
        }

        $directoryPath = $request->request->get('path');

        if (!$this->isPathWithinWorkspace($directoryPath)) {
            $this->addFlash('error', 'Acceso denegado: directorio fuera del espacio de trabajo');
            return $this->redirectToRoute('explorer');
        }

        if (empty($directoryPath) || !is_dir($directoryPath)) {
            $this->addFlash('error', 'La carpeta no existe o la ruta es inválida.');
            return $this->redirectToRoute('explorer');
        }

        if ($directoryPath === $this->workspaceDir) {
            $this->addFlash('error', 'No se puede eliminar el directorio raíz del workspace.');
            return $this->redirectToRoute('explorer');
        }

        $filesystem = new Filesystem();
        try {
            $filesystem->remove($directoryPath);
            $this->addFlash('success', 'Carpeta eliminada correctamente.');
        } catch (\Exception $e) {
            $this->addFlash('error', 'No se pudo eliminar la carpeta: ' . $e->getMessage());
        }

        return $this->redirectToRoute('explorer', ['path' => dirname($directoryPath)]);
    }

    #[Route('/archivos/eliminar-archivo', name: 'eliminar_archivo', methods: ['POST'])]
    public function eliminarArchivo(Request $request): RedirectResponse
    {
        $user = $this->verifyAuthentication($request);
        if (!$user) {
            throw new AccessDeniedException('Usuario no autenticado');
        }

        $directoryPath = $request->request->get('path');
        $filename = $request->request->get('filename');
        $filePath = $directoryPath . '/' . $filename;

        if (!$this->isPathWithinWorkspace($directoryPath)) {
            $this->addFlash('error', 'Acceso denegado: directorio fuera del espacio de trabajo');
            return $this->redirectToRoute('explorer');
        }

        if (empty($filePath) || !file_exists($filePath)) {
            $this->addFlash('error', 'El archivo no existe o la ruta es inválida.');
            return $this->redirectToRoute('explorer');
        }

        $filesystem = new Filesystem();
        try {
            $filesystem->remove($filePath);
            $this->addFlash('success', 'Archivo eliminado correctamente.');
        } catch (\Exception $e) {
            $this->addFlash('error', 'No se pudo eliminar el archivo: ' . $e->getMessage());
        }

        return $this->redirectToRoute('explorer', ['path' => $directoryPath]);
    }

    #[Route('/archivos/subir-archivo', name: 'subir_archivo', methods: ['POST'])]
    public function subirArchivo(Request $request): RedirectResponse
    {
        $user = $this->verifyAuthentication($request);
        if (!$user) {
            throw new AccessDeniedException('Usuario no autenticado');
        }

        $directoryPath = $request->request->get('path');

        if (!$this->isPathWithinWorkspace($directoryPath)) {
            $this->addFlash('error', 'Acceso denegado: directorio fuera del espacio de trabajo');
            return $this->redirectToRoute('explorer');
        }

        $file = $request->files->get('file');

        if ($file) {
            if (!is_dir($directoryPath)) {
                $this->addFlash('error', 'El directorio no existe.');
                return $this->redirectToRoute('explorer');
            }

            try {
                $file->move($directoryPath, $file->getClientOriginalName());
                $this->addFlash('success', 'Archivo subido correctamente.');
            } catch (\Exception $e) {
                $this->addFlash('error', 'Hubo un problema al subir el archivo: ' . $e->getMessage());
            }
        } else {
            $this->addFlash('error', 'No se seleccionó ningún archivo.');
        }

        return $this->redirectToRoute('explorer', ['path' => $directoryPath]);
    }

    #[Route('/archivos/subir-carpeta', name: 'subir_carpeta', methods: ['POST'])]
    public function subirCarpeta(Request $request): RedirectResponse
    {
        $user = $this->verifyAuthentication($request);
        if (!$user) {
            throw new AccessDeniedException('Usuario no autenticado');
        }

        $directoryPath = $request->request->get('path');

        if (!$this->isPathWithinWorkspace($directoryPath)) {
            $this->addFlash('error', 'Acceso denegado: directorio fuera del espacio de trabajo');
            return $this->redirectToRoute('explorer');
        }

        $folderName = $request->request->get('folder_name');
        $files = $request->files->get('folder');

        if ($files) {
            if (!is_dir($directoryPath)) {
                $this->addFlash('error', 'El directorio no existe.');
                return $this->redirectToRoute('explorer');
            }

            if (!$folderName) {
                $folderName = 'Nueva_Carpeta_' . date('YmdHis');
            }

            $newFolderPath = $directoryPath . '/' . $folderName;
            if (!is_dir($newFolderPath)) {
                mkdir($newFolderPath, 0777, true);
            }

            try {
                foreach ($files as $file) {
                    if ($file) {
                        $file->move($newFolderPath, $file->getClientOriginalName());
                    }
                }
                $this->addFlash('success', 'Carpeta subida correctamente con el nombre: ' . $folderName);
            } catch (\Exception $e) {
                $this->addFlash('error', 'Hubo un problema al subir la carpeta: ' . $e->getMessage());
            }
        } else {
            $this->addFlash('error', 'No se seleccionó ninguna carpeta.');
        }

        return $this->redirectToRoute('explorer', ['path' => $directoryPath]);
    }

    #[Route('/archivos/inicializar', name: 'inicializar_workspace')]
    public function inicializarWorkspace(Request $request): Response
    {
        $user = $this->verifyAuthentication($request);

        if (!$user || !in_array('ROLE_ADMIN', $user->getRoles())) {
            throw new AccessDeniedException('Se requiere permisos de administrador para esta acción');
        }

        $filesystem = new Filesystem();

        $directories = [
            $this->workspaceDir . '/documentos',
            $this->workspaceDir . '/imagenes',
            $this->workspaceDir . '/videos',
            $this->workspaceDir . '/musica',
            $this->workspaceDir . '/compartido'
        ];

        foreach ($directories as $dir) {
            if (!$filesystem->exists($dir)) {
                $filesystem->mkdir($dir);
            }
        }

        $welcomeFile = $this->workspaceDir . '/bienvenido.txt';
        if (!$filesystem->exists($welcomeFile)) {
            $filesystem->dumpFile(
                $welcomeFile,
                "Bienvenido al Explorador de Archivos de Exnet.\n\n" .
                "Este es el espacio compartido para almacenar y gestionar archivos.\n" .
                "Fecha de creación: " . date('Y-m-d H:i:s') . "\n"
            );
        }

        $filesystem->dumpFile(
            $this->workspaceDir . '/info.txt',
            "Workspace inicializado por: {$user->getNombre()} {$user->getApellidos()} ({$user->getEmail()})\n" .
            "Fecha: " . date('Y-m-d H:i:s') . "\n"
        );

        $this->addFlash('success', 'Workspace inicializado correctamente.');
        return $this->redirectToRoute('explorer');
    }

    private function isPathWithinWorkspace($path): bool
    {
        $realWorkspace = realpath($this->workspaceDir);
        $realPath = realpath($path);

        if ($realPath === false) {
            return false;
        }

        return strpos($realPath, $realWorkspace) === 0;
    }
}