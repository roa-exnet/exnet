<?php

namespace App\ModuloExplorador\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\File\File;
use Symfony\Component\HttpFoundation\File\Exception\FileNotFoundException;
use Symfony\Component\HttpFoundation\RedirectResponse;




class GenController extends AbstractController
{
    #[Route('/gen', name: 'app_gen')]
    public function index(): Response
    {
        return $this->render('gen/index.html.twig', [
            'controller_name' => 'GenController',
        ]);
    }
    #[Route('/archivos', name: 'explorer',methods:['GET','POST'])]
    public function explorer(Request $request): Response {
        $lastDir='/home/a/prueba2/templates/gen/prueba';
        $path = $request->query->get('path', '/home/a/prueba/templates/gen/prueba');
    

        $directoryPath = $request->request->get('path', '/home/a/prueba2/templates/gen/prueba');
    

        if (!is_dir($directoryPath)) {
            $directoryPath = '/home/a/prueba2/templates/gen/prueba';
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
    
        if (file_exists($directoryPath . '/' . $filename)) {
            $filePath = $directoryPath . '' . $filename;
    
            // Detectar tipo de archivo
            $fileMimeType = mime_content_type($filePath);
            $fileContent = null;
            $isImage = false;
            $fileUrl = null;
    
            // Si el archivo es una imagen
            if (strpos($fileMimeType, 'image') === 0) {
                $isImage = true;
                $fileUrl = $filePath;////."/".basename($filePath);  // Asumimos que las imágenes se encuentran en el directorio /public/uploads/
            } else {
                // Si no es una imagen, obtener el contenido del archivo
                $fileContent = file_get_contents($filePath);
            }
    
            // Preparar los datos para pasarlos a Twig
            return $this->render('gen/abrir.html.twig', [
                'filename' => $filename,
                'fileContent' => $fileContent,
                'directory' =>  substr($directoryPath, 0, -1),
                'isImage' => $isImage,
                'fileUrl' => $fileUrl,
                
            ]);
        } else {
            return new Response('El archivo no existe', 404);
        }
    }
    

    #[Route('/descargar', name:'download',methods:['GET','POST'])]
    public function descargar(Request $request){
        $filePath = $request->request->get('file_path');
        return $this->file($filePath);
    }

    #[Route('/imagenes/{filename}', name:'ver_imagen', methods:['POST'])]
public function verImagen(Request $request, string $filename): Response
{
    // Obtener el directorio desde el cuerpo de la solicitud (POST)
    $directoryPath = $request->request->get('path');  // Recupera 'directory' de los datos POST

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

}
