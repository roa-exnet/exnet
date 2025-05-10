<?php
namespace App\ModuloCore\Controller;

use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\Cookie;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Psr\Log\LoggerInterface;

class CookieController extends AbstractController
{
    private EntityManagerInterface $entityManager;
    private LoggerInterface $logger;

    public function __construct(EntityManagerInterface $entityManager, LoggerInterface $logger)
    {
        $this->entityManager = $entityManager;
        $this->logger = $logger;
    }

    #[Route('/generar-cookie/{modulo}', name: 'generar_cookie', methods: ['GET'])]
    public function generarCookie(string $modulo, Request $request): Response
    {

        $this->logger->info('Generando cookie para módulo: ' . $modulo);

        $redirect = $request->query->get('redirect');
        $this->logger->info('URL de redirección: ' . ($redirect ?: 'no definida'));

        $moduleParams = $this->extractModuleFromPath($redirect ?: ('/' . $modulo));
        $mainModule = $moduleParams['moduleName'];
        $this->logger->info('Módulo principal identificado: ' . $mainModule);

        $token = $this->findModuleLicense($mainModule);

        if (!$token && $mainModule !== $modulo) {
            $this->logger->info('Intentando con el módulo original: ' . $modulo);
            $token = $this->findModuleLicense($modulo);
        }

        if (!$token && $moduleParams['fullPath']) {
            $this->logger->info('Intentando con el path completo: ' . $moduleParams['fullPath']);
            $token = $this->findModuleLicense($moduleParams['fullPath']);
        }

        if (!$token) {
            $this->logger->error('No se encontró licencia válida para ninguna opción');
            return new Response('Licencia no encontrada o no válida para el módulo', 404);
        }

        $this->logger->info('Licencia encontrada, generando cookie');

        $response = $redirect ? new RedirectResponse($redirect) : new Response('Cookie generada');

        $cookie = Cookie::create('module_token')
            ->withValue($token)
            ->withPath('/')
            ->withSecure(false)
            ->withHttpOnly(true);

        $response->headers->setCookie($cookie);
        return $response;
    }

    private function extractModuleFromPath(?string $path): array
    {
        if (!$path) {
            return ['moduleName' => '', 'fullPath' => ''];
        }

        $urlPath = parse_url($path, PHP_URL_PATH) ?: $path;

        $cleanPath = ltrim($urlPath, '/');

        $segments = explode('/', $cleanPath);

        if (!empty($segments[0])) {

            if ($segments[0] === 'kc' && !empty($segments[1])) {
                $moduleName = $segments[1];

                $fullPath = implode('/', array_slice($segments, 1));
            } else {
                $moduleName = $segments[0];
                $fullPath = $cleanPath;
            }

            $this->logger->info("Ruta analizada: Path original=$path, Limpio=$cleanPath, Módulo=$moduleName, Path completo=$fullPath");
            return ['moduleName' => $moduleName, 'fullPath' => $fullPath];
        }

        return ['moduleName' => '', 'fullPath' => ''];
    }

    private function findModuleLicense(string $moduleName): ?string
    {
        if (empty($moduleName)) {
            return null;
        }

        $this->logger->info('Buscando licencia para módulo: ' . $moduleName);

        try {
            $conn = $this->entityManager->getConnection();
            $stmt = $conn->prepare('SELECT token FROM licencia WHERE nombre = :nombre');
            $result = $stmt->executeQuery(['nombre' => $moduleName])->fetchAssociative();

            if ($result && isset($result['token'])) {
                $this->logger->info('Licencia encontrada para módulo: ' . $moduleName);
                return $result['token'];
            }

            $this->logger->warning('No se encontró licencia para módulo: ' . $moduleName);
            return null;
        } catch (\Exception $e) {
            $this->logger->error('Error al buscar licencia: ' . $e->getMessage());
            return null;
        }
    }
}