<?php

namespace App\ModuloExplorador\Controller;
use App\ModuloCore\Entity\User;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\File\File;
use Symfony\Component\HttpFoundation\File\Exception\FileNotFoundException;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\ResponseHeaderBag;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;
use Symfony\Component\DependencyInjection\ParameterBag\ParameterBagInterface;

class GenController extends AbstractController
{
    private $projectDir;
    private $workspaceDir;
    
    public function __construct(ParameterBagInterface $parameterBag)
    {
        // Obtiene el directorio del proyecto de Symfony
        $this->projectDir = $parameterBag->get('kernel.project_dir');
        // Define el directorio de trabajo como una subcarpeta "Workspace" dentro del módulo
        $this->workspaceDir = $this->projectDir . '/src/ModuloExplorador/Workspace';
        
        // Asegurarse de que el directorio Workspace exista
        if (!is_dir($this->workspaceDir)) {
            mkdir($this->workspaceDir, 0777, true);
        }
    }
   
    #[Route('/archivos', name: 'explorer', methods: ['GET', 'POST'])]
    public function explorer(Request $request): Response {
        // Definimos el directorio raíz del explorador como nuestra carpeta Workspace
        $rootDir = $this->workspaceDir;
        
        // Ahora usamos este directorio como el directorio por defecto 
        $path = $request->query->get('path', $rootDir);
        $user = $this->getUser();
        /*if (!$user instanceof User) {
            throw new AccessDeniedException('Usuario no autenticado');
        }*/

        $directoryPath = $request->request->get('path', $rootDir);
    
        // Nos aseguramos de que el directorio esté dentro de nuestro Workspace por seguridad
        if (!$this->isPathWithinWorkspace($directoryPath) || !is_dir($directoryPath)) {
            $directoryPath = $rootDir;
        }
    
        $fileCount = 0;
        $dirCount = 0;
        $dirsOnly = [];
        $filesOnly = [];
    
        if (is_dir($directoryPath)) {
            $filesAndDirs = scandir($directoryPath);
            $filesAndDirs = array_filter($filesAndDirs, function ($item) use ($directoryPath) {
                return $item !== '.' && $item !== '..';
            });
            $filesAndDirs = array_values($filesAndDirs);
    
            foreach ($filesAndDirs as $item) {
                $itemPath = $directoryPath . '/' . $item;
                if (is_dir($itemPath)) {
                    $dirsOnly[] = $item; 
                    $dirCount++;
                } elseif (is_file($itemPath)) {
                    $filesOnly[] = $item; 
                    $fileCount++;
                }
            }
        }
    
        $parentDirectory = dirname($directoryPath);
        
        // Si el directorio padre está fuera de nuestro Workspace, usamos el Workspace como padre
        if (!$this->isPathWithinWorkspace($parentDirectory)) {
            $parentDirectory = $rootDir;
        }
    
        return $this->render('@ModuloExplorador/gen/index.html.twig', [
            'file_count' => $fileCount,
            'dir_count' => $dirCount,
            'directory' => $directoryPath,
            'parent_directory' => $parentDirectory,
            'dirsOnly' => $dirsOnly,
            'filesOnly' => $filesOnly,
            'lastDir' => $rootDir,
        ]);
    }

    #[Route('/abrir', name: 'abrir_archivo', methods: ['GET', 'POST'])]
    public function abrirArchivo(Request $request): Response
    {
        $directoryPath = $request->request->get('path');
        $filename = $request->request->get('filename');
        
        // Verificar que el directorio está dentro de nuestro Workspace
        if (!$this->isPathWithinWorkspace($directoryPath)) {
            return new Response('Acceso denegado: directorio fuera del espacio de trabajo', 403);
        }
        
        $user = $this->getUser();
        /*if (!$user instanceof User) {
            throw new AccessDeniedException('Usuario no autenticado');
        }*/
        
        if (file_exists($directoryPath . '/' . $filename)) {
            $filePath = $directoryPath . '/' . $filename;

            // Detectar tipo de archivo y extensión
            $fileMimeType = mime_content_type($filePath);
            $fileExtension = strtolower(pathinfo($filePath, PATHINFO_EXTENSION)); // Obtener extensión del archivo
            $fileContent = null;
            $isImage = false;
            $isVideo = false;
            $fileUrl = null;

            // Lista de extensiones de video permitidas
            $videoExtensions = ['mp4', 'webm', 'ogg', 'mov', 'avi', 'mkv'];

            if (strpos($fileMimeType, 'image') === 0) {
                // Es una imagen
                $isImage = true;
                $fileUrl = $filePath; 
            } elseif (in_array($fileExtension, $videoExtensions)) {
                // Es un video si la extensión está en la lista
                $isVideo = true;
                $fileUrl = $filePath;
            } else {
                // Si no es imagen ni video, obtener el contenido del archivo
                $fileContent = file_get_contents($filePath);
            }

            // Renderizar la vista con los datos
            return $this->render('@ModuloExplorador/gen/abrir.html.twig', [
                'filename' => $filename,
                'fileContent' => $fileContent,
                'directory' => substr($directoryPath, 0, -1),
                'isImage' => $isImage,
                'isVideo' => $isVideo,
                'fileUrl' => $fileUrl,
                'fileExtension' => $fileExtension,
                'videoExtensions' => $videoExtensions, // Ahora pasamos la variable a Twig
            ]);
        } else {
            return new Response('El archivo no existe', 404);
        }
    }

    #[Route('/ver_video', name: 'ver_video', methods: ['GET', 'POST'])]
    public function verVideo(Request $request): Response
    {
        $directoryPath = $request->query->get('path');
        $filename = $request->query->get('filename');
        
        // Verificar que el directorio está dentro de nuestro Workspace
        if (!$this->isPathWithinWorkspace($directoryPath)) {
            return new Response('Acceso denegado: directorio fuera del espacio de trabajo', 403);
        }
        
        $filePath = $directoryPath . '/' . $filename;
        $user = $this->getUser();
        /*if (!$user instanceof User) {
            throw new AccessDeniedException('Usuario no autenticado');
        }*/
        if (!file_exists($filePath)) {
            throw $this->createNotFoundException('El archivo de video no existe.');
        }

        $response = new BinaryFileResponse($filePath);
        $response->headers->set('Content-Type', mime_content_type($filePath));
        $response->setContentDisposition(ResponseHeaderBag::DISPOSITION_INLINE, $filename);

        return $response;
    }

    #[Route('/descargar', name:'download', methods:['GET','POST'])]
    public function descargar(Request $request)
    {
        $filePath = $request->request->get('file_path');
        
        // Verificar que la ruta del archivo está dentro de nuestro Workspace
        if (!$this->isPathWithinWorkspace($filePath)) {
            return new Response('Acceso denegado: archivo fuera del espacio de trabajo', 403);
        }
        
        return $this->file($filePath);
    }

    #[Route('/imagenes', name:'ver_imagen', methods:['POST'])]
    public function verImagen(Request $request): Response
    {
        // Obtener el directorio desde el cuerpo de la solicitud (POST)
        $directoryPath = $request->request->get('path');  // Recupera 'directory' de los datos POST
        $filename = $request->request->get('filename');
        
        // Verificar que el directorio está dentro de nuestro Workspace
        if (!$this->isPathWithinWorkspace($directoryPath)) {
            return new Response('Acceso denegado: directorio fuera del espacio de trabajo', 403);
        }
        
        $user = $this->getUser();
        /*if (!$user instanceof User) {
            throw new AccessDeniedException('Usuario no autenticado');
        }*/
        
        // Verificar que el directorio existe y es válido
        if (!$directoryPath) {
            throw new \Exception('El parámetro "directory" no está presente en la solicitud.');
        }

        // Construir el path completo de la imagen
        $filePath = $directoryPath . '/' . $filename;
        
        // Verificar si el archivo existe
        if (!file_exists($filePath)) {
            throw new FileNotFoundException('La imagen no existe');
        }

        // Establecer el tipo MIME correcto según la extensión del archivo
        $mimeType = mime_content_type($filePath);

        // Leer el archivo
        $fileContent = file_get_contents($filePath);

        // Crear una respuesta que le indique al navegador que debe mostrar la imagen
        $response = new Response($fileContent);
        $response->headers->set('Content-Type', $mimeType); // Establecer el tipo MIME adecuado
        $response->headers->set('Cache-Control', 'public, max-age=3600'); // (Opcional) Para caché de imagen

        return $response;
    }

    #[Route('/crear_carpeta', name: 'crear_carpeta', methods: ['GET', 'POST'])]
    public function crearCarpeta(Request $request): RedirectResponse
    {
        // Obtener el nombre de la carpeta desde el formulario
        $folderName = $request->request->get('folder_name');
        
        // Obtener la ruta del directorio actual desde el formulario
        $directoryPath = $request->request->get('path');
        
        // Verificar que el directorio está dentro de nuestro Workspace
        if (!$this->isPathWithinWorkspace($directoryPath)) {
            $this->addFlash('error', 'Acceso denegado: directorio fuera del espacio de trabajo');
            return $this->redirectToRoute('explorer', ['path' => $this->workspaceDir]);
        }
        
        $user = $this->getUser();
        /*if (!$user instanceof User) {
            throw new AccessDeniedException('Usuario no autenticado');
        }*/
        
        // Validar si el directorio y el nombre de la carpeta son válidos
        if (empty($folderName) || empty($directoryPath)) {
            $this->addFlash('error', 'Faltan datos para crear la carpeta.');
            return $this->redirectToRoute('explorer', ['path' => $directoryPath]);
        }

        // Construir la ruta completa de la nueva carpeta
        $newFolderPath = rtrim($directoryPath, '/') . '/' . $folderName;

        // Crear una instancia del servicio Filesystem
        $filesystem = new Filesystem();

        // Verificar si la carpeta no existe
        if (!$filesystem->exists($newFolderPath)) {
            // Crear la carpeta si no existe
            $filesystem->mkdir($newFolderPath);
            $this->addFlash('success', 'Carpeta creada exitosamente.');
        } else {
            $this->addFlash('error', 'La carpeta ya existe.');
        }

        // Redirigir al explorador de archivos con la ruta actual
        return $this->redirectToRoute('explorer', ['path' => $directoryPath]);
    }
    
    #[Route('/eliminar_carpeta', name: 'eliminar_carpeta', methods: ['POST'])]
    public function eliminarCarpeta(Request $request): RedirectResponse
    {
        $directoryPath = $request->request->get('path');
        
        // Verificar que el directorio está dentro de nuestro Workspace
        if (!$this->isPathWithinWorkspace($directoryPath)) {
            $this->addFlash('error', 'Acceso denegado: directorio fuera del espacio de trabajo');
            return $this->redirectToRoute('explorer', ['path' => $this->workspaceDir]);
        }
        
        $user = $this->getUser();
        /*if (!$user instanceof User) {
            throw new AccessDeniedException('Usuario no autenticado');
        }*/
        
        if (empty($directoryPath) || !is_dir($directoryPath)) {
            $this->addFlash('error', 'La carpeta no existe o la ruta es inválida.');
            return $this->redirectToRoute('explorer', ['path' => dirname($directoryPath)]);
        }

        // Asegurarnos de que no intentamos eliminar el directorio raíz del workspace
        if ($directoryPath === $this->workspaceDir) {
            $this->addFlash('error', 'No se puede eliminar el directorio raíz del workspace.');
            return $this->redirectToRoute('explorer', ['path' => $this->workspaceDir]);
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

    #[Route('/eliminar_archivo', name: 'eliminar_archivo', methods: ['POST'])]
    public function eliminarArchivo(Request $request): RedirectResponse
    {
        $directoryPath = $request->request->get('path');
        $filename = $request->request->get('filename');
        $filePath = $directoryPath . '/' . $filename;
        
        // Verificar que el directorio está dentro de nuestro Workspace
        if (!$this->isPathWithinWorkspace($directoryPath)) {
            $this->addFlash('error', 'Acceso denegado: directorio fuera del espacio de trabajo');
            return $this->redirectToRoute('explorer', ['path' => $this->workspaceDir]);
        }
        
        $user = $this->getUser();
        /*if (!$user instanceof User) {
            throw new AccessDeniedException('Usuario no autenticado');
        }*/
        
        if (empty($filePath) || !file_exists($filePath)) {
            $this->addFlash('error', 'El archivo no existe o la ruta es inválida.');
            return $this->redirectToRoute('explorer', ['path' => dirname($filePath)]);
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

    #[Route('/subir_archivo', name: 'subir_archivo', methods: ['POST'])]
    public function subirArchivo(Request $request): RedirectResponse
    {
        // Obtener el directorio de destino desde el formulario
        $directoryPath = $request->request->get('path');
        
        // Verificar que el directorio está dentro de nuestro Workspace
        if (!$this->isPathWithinWorkspace($directoryPath)) {
            $this->addFlash('error', 'Acceso denegado: directorio fuera del espacio de trabajo');
            return $this->redirectToRoute('explorer', ['path' => $this->workspaceDir]);
        }
        
        $file = $request->files->get('file');
        $user = $this->getUser();
        /*if (!$user instanceof User) {
            throw new AccessDeniedException('Usuario no autenticado');
        }*/
        
        // Comprobar si se ha subido un archivo
        if ($file) {
            // Verificar que el directorio existe
            if (!is_dir($directoryPath)) {
                $this->addFlash('error', 'El directorio no existe.');
                return $this->redirectToRoute('explorer', ['path' => $directoryPath]);
            }

            // Define la ruta de destino para guardar el archivo
            $destination = $directoryPath;

            // Mover el archivo a la carpeta destino
            try {
                $file->move($destination, $file->getClientOriginalName());
                $this->addFlash('success', 'Archivo subido correctamente.');
            } catch (\Exception $e) {
                $this->addFlash('error', 'Hubo un problema al subir el archivo: ' . $e->getMessage());
            }
        } else {
            $this->addFlash('error', 'No se seleccionó ningún archivo.');
        }

        return $this->redirectToRoute('explorer', ['path' => $directoryPath]);
    }

    #[Route('/subir_carpeta', name: 'subir_carpeta', methods: ['POST'])]
    public function subirCarpeta(Request $request): RedirectResponse
    {
        // Obtener el directorio de destino desde el formulario
        $directoryPath = $request->request->get('path');
        
        // Verificar que el directorio está dentro de nuestro Workspace
        if (!$this->isPathWithinWorkspace($directoryPath)) {
            $this->addFlash('error', 'Acceso denegado: directorio fuera del espacio de trabajo');
            return $this->redirectToRoute('explorer', ['path' => $this->workspaceDir]);
        }
        
        $folderName = $request->request->get('folder_name'); // Obtener el nombre de la carpeta
        $files = $request->files->get('folder'); // Recibe todos los archivos de la carpeta seleccionada
        $user = $this->getUser();
        /*if (!$user instanceof User) {
            throw new AccessDeniedException('Usuario no autenticado');
        }*/
        
        if ($files) {
            // Verifica que el directorio existe
            if (!is_dir($directoryPath)) {
                $this->addFlash('error', 'El directorio no existe.');
                return $this->redirectToRoute('explorer', ['path' => $directoryPath]);
            }

            // Si no se proporciona un nombre de carpeta, se usa el nombre de la carpeta seleccionada
            if (!$folderName) {
                // Obtener el nombre de la carpeta desde el primer archivo (usando la ruta del archivo)
                $firstFilePath = $files[0]->getRealPath();
                $folderName = basename(dirname($firstFilePath));
            }

            // Crear una carpeta en el destino con el nombre proporcionado
            $newFolderPath = $directoryPath . '/' . $folderName;
            if (!is_dir($newFolderPath)) {
                mkdir($newFolderPath, 0777, true); // Crear la carpeta con permisos
            }

            try {
                // Mover los archivos dentro de la nueva carpeta
                foreach ($files as $file) {
                    if ($file) {
                        // Mover cada archivo a la nueva carpeta
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

    /**
     * Verifica si una ruta está dentro del directorio Workspace
     */
    private function isPathWithinWorkspace($path): bool
    {
        // Normalizar las rutas para comparación
        $realWorkspace = realpath($this->workspaceDir);
        $realPath = realpath($path);
        
        // Si la ruta no existe o es falsa, no está dentro del workspace
        if ($realPath === false) {
            return false;
        }
        
        // Verificar si la ruta comienza con la ruta del workspace
        return strpos($realPath, $realWorkspace) === 0;
    }
    
    /**
     * Método para inicializar el directorio Workspace con algunas carpetas básicas
     */
    #[Route('/init-workspace', name: 'init_workspace')]
    public function initWorkspace(): Response
    {
        $filesystem = new Filesystem();
        
        // Crear directorios básicos si no existen
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
        
        // Crear un archivo de bienvenida
        $welcomeFile = $this->workspaceDir . '/bienvenido.txt';
        if (!$filesystem->exists($welcomeFile)) {
            $filesystem->dumpFile(
                $welcomeFile,
                "Bienvenido al Explorador de Archivos de Exnet.\n\n" .
                "Este es tu espacio personal para almacenar y gestionar tus archivos.\n" .
                "Fecha de creación: " . date('Y-m-d H:i:s') . "\n"
            );
        }
        
        $this->addFlash('success', 'Workspace inicializado correctamente.');
        return $this->redirectToRoute('explorer', ['path' => $this->workspaceDir]);
    }
}