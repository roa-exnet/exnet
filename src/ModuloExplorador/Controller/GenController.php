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






class GenController extends AbstractController
{
    #[Route('/explorador', name: 'explorer_')]
    #[IsGranted('IS_AUTHENTICATED_REMEMBERED')]

    #[Route('/', name: 'index')]
    public function index(): Response
    {
        /** @var User $user */
        $user = $this->getUser();
        
        // Verificación adicional de seguridad
        if (!$user instanceof User) {
            throw new AccessDeniedException('Debes iniciar sesión para acceder al explorador de archivos.');
        }
        
        $userId = (string) $user->getId();
        $userName = $user->getNombre() . ' ' . $user->getApellidos();
        
        /*return $this->render('gen/index.html.twig', [
            'userId' => $userId,
            'userName' => $userName,
        ]);*/
        return $this->redirectToRoute('explorer');
    }
    #[Route('/archivos', name: 'explorer',methods:['GET','POST'])]
    public function explorer(Request $request): Response {
        $lastDir='/root/explorador';
        $path = $request->query->get('path', '/root/explorador');
        $user = $this->getUser();
        if (!$user instanceof User) {
            throw new AccessDeniedException('Usuario no autenticado');
        }

        $directoryPath = $request->request->get('path', '/root/explorador');
    

        if (!is_dir($directoryPath)) {
            $directoryPath = '/root/explorador';
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
    
        return $this->render('gen/index.html.twig', [
            'file_count' => $fileCount,
            'dir_count' => $dirCount,
            'directory' => $directoryPath,
            'parent_directory' => $parentDirectory,
            'dirsOnly' => $dirsOnly,
            'filesOnly' => $filesOnly,
            'lastDir'=>$lastDir,
        ]);
    }

    #[Route('/abrir', name: 'abrir_archivo', methods: ['GET', 'POST'])]
public function abrirArchivo(Request $request): Response
{
    $directoryPath = $request->request->get('path');
    $filename = $request->request->get('filename');
    $user = $this->getUser();
    if (!$user instanceof User) {
        throw new AccessDeniedException('Usuario no autenticado');
    }
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
        return $this->render('gen/abrir.html.twig', [
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
    $filename=$request->query->get('filename');
    $filePath = $directoryPath . '/' . $filename;
    $user = $this->getUser();
    if (!$user instanceof User) {
        throw new AccessDeniedException('Usuario no autenticado');
    }
    if (!file_exists($filePath)) {
        throw $this->createNotFoundException('El archivo de video no existe.');
    }

    $response = new BinaryFileResponse($filePath);
    $response->headers->set('Content-Type', mime_content_type($filePath));
    $response->setContentDisposition(ResponseHeaderBag::DISPOSITION_INLINE, $filename);

    return $response;
}

    #[Route('/descargar', name:'download',methods:['GET','POST'])]
    public function descargar(Request $request){
        $filePath = $request->request->get('file_path');
        return $this->file($filePath);
    }

    #[Route('/imagenes', name:'ver_imagen', methods:['POST'])]
public function verImagen(Request $request): Response
{
    // Obtener el directorio desde el cuerpo de la solicitud (POST)
    $directoryPath = $request->request->get('path');  // Recupera 'directory' de los datos POST
    $filename=$request->request->get('filename');
    $user = $this->getUser();
    if (!$user instanceof User) {
        throw new AccessDeniedException('Usuario no autenticado');
    }
    // Verificar que el directorio existe y es válido
    if (!$directoryPath) {
        throw new \Exception('El parámetro "directory" no está presente en la solicitud.');
    }

    // Construir el path completo de la imagen
    $filePath = $directoryPath . '/' . $filename;
    //dd($filename);
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
    $user = $this->getUser();
    $directoryPath = $request->request->get('path');
    if (!$user instanceof User) {
        throw new AccessDeniedException('Usuario no autenticado');
    }
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
    $user = $this->getUser();
    if (!$user instanceof User) {
        throw new AccessDeniedException('Usuario no autenticado');
    }
    if (empty($directoryPath) || !is_dir($directoryPath)) {
        $this->addFlash('error', 'La carpeta no existe o la ruta es inválida.');
        return $this->redirectToRoute('explorer', ['path' => dirname($directoryPath)]);
    }

    $filesystem = new Filesystem();

    try {
        $filesystem->remove($directoryPath);
        $this->addFlash('success', 'Carpeta eliminada correctamente.');
    } catch (\Exception $e) {
        $this->addFlash('error', 'No se pudo eliminar la carpeta.');
    }

    return $this->redirectToRoute('explorer', ['path' => dirname($directoryPath)]);
}

#[Route('/eliminar_archivo', name: 'eliminar_archivo', methods: ['POST'])]
public function eliminarArchivo(Request $request): RedirectResponse
{
    $filePath = $request->request->get('path') . '/' . $request->request->get('filename');
    $user = $this->getUser();
    if (!$user instanceof User) {
        throw new AccessDeniedException('Usuario no autenticado');
    }
    if (empty($filePath) || !file_exists($filePath)) {
        $this->addFlash('error', 'El archivo no existe o la ruta es inválida.');
        return $this->redirectToRoute('explorer', ['path' => dirname($filePath)]);
    }

    $filesystem = new Filesystem();

    try {
        $filesystem->remove($filePath);
        $this->addFlash('success', 'Archivo eliminado correctamente.');
    } catch (\Exception $e) {
        $this->addFlash('error', 'No se pudo eliminar el archivo.');
    }

    return $this->redirectToRoute('explorer', ['path' => dirname($filePath)]);
}

#[Route('/subir_archivo', name: 'subir_archivo', methods: ['POST'])]
public function subirArchivo(Request $request): RedirectResponse
{
    // Obtener el directorio de destino desde el formulario
    $directoryPath = $request->request->get('path');
    $file = $request->files->get('file');
    $user = $this->getUser();
    if (!$user instanceof User) {
        throw new AccessDeniedException('Usuario no autenticado');
    }
    //dd($file);
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
    $folderName = $request->request->get('folder_name'); // Obtener el nombre de la carpeta
    $files = $request->files->get('folder'); // Recibe todos los archivos de la carpeta seleccionada
    $user = $this->getUser();
    if (!$user instanceof User) {
        throw new AccessDeniedException('Usuario no autenticado');
    }
    // Agregar depuración para verificar que recibimos el valor de folder_name correctamente
    dump($folderName); // Para ver si recibimos el nombre correctamente
    
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
            $this->addFlash('error', 'Hubo un problema al subir la carpeta.');
        }
    } else {
        $this->addFlash('error', 'No se seleccionó ninguna carpeta.');
    }

    return $this->redirectToRoute('explorer', ['path' => $directoryPath]);
}


}
