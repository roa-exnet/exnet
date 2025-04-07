<?php

namespace App\ModuloCore\Controller;

use App\ModuloCore\Service\CdnService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Annotation\Route;
use Doctrine\ORM\EntityManagerInterface;
use App\ModuloCore\Entity\Modulo;
use Symfony\Component\Process\Process;
use Symfony\Component\Process\Exception\ProcessFailedException;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\DependencyInjection\ParameterBag\ParameterBagInterface;

class CdnController extends AbstractController
{
    use JwtAuthControllerTrait;
    
    private CdnService $cdnService;
    private EntityManagerInterface $entityManager;
    private string $projectDir;
    private Filesystem $filesystem;
    
    public function __construct(
        CdnService $cdnService,
        EntityManagerInterface $entityManager,
        ParameterBagInterface $parameterBag
    ) {
        $this->cdnService = $cdnService;
        $this->entityManager = $entityManager;
        $this->projectDir = $parameterBag->get('kernel.project_dir');
        $this->filesystem = new Filesystem();
    }

    #[Route('/modulos/marketplace', name: 'modulos_marketplace')]
    public function marketplace(Request $request): Response
    {
        $auth = $this->requireJwtAuthentication($request);
        if ($auth instanceof Response) {
            return $auth;
        }
        
        $marketplace = $this->cdnService->getAvailableModules();
        $error = isset($marketplace['error']) ? $marketplace['error'] : null;
        
        return $this->render('marketplace.html.twig', [
            'marketplace' => $marketplace,
            'error' => $error
        ]);
    }
    
    #[Route('/api/modulos/marketplace', name: 'api_modulos_marketplace')]
    public function apiMarketplace(Request $request): JsonResponse
    {
        $marketplace = $this->cdnService->getAvailableModules();
        
        if (isset($marketplace['error'])) {
            return $this->json(['success' => false, 'error' => $marketplace['error']], 500);
        }
        
        return $this->json(['success' => true, 'marketplace' => $marketplace]);
    }
    
    #[Route('/api/modulos/verify-license', name: 'api_verify_license', methods: ['POST'])]
    public function verifyLicense(Request $request): JsonResponse
    {
        $data = json_decode($request->getContent(), true);
        
        if (!isset($data['licenseKey']) || !isset($data['moduleFilename'])) {
            return $this->json(['valid' => false, 'message' => 'Datos incompletos'], 400);
        }
        
        $result = $this->cdnService->verifyLicense($data['licenseKey'], $data['moduleFilename']);
        
        return $this->json($result);
    }
    
    #[Route('/api/modulos/install', name: 'api_install_module', methods: ['POST'])]
    public function installModule(Request $request): JsonResponse
    {
        $auth = $this->requireJwtRoles($request, ['ROLE_ADMIN']);
        if ($auth instanceof JsonResponse) {
            return $auth;
        }
        
        $data = json_decode($request->getContent(), true);
        
        if (!isset($data['moduleType']) || !isset($data['filename'])) {
            return $this->json(['success' => false, 'message' => 'Datos incompletos'], 400);
        }
        
        $downloadToken = $data['downloadToken'] ?? null;
        
        $result = $this->cdnService->installModule(
            $data['moduleType'],
            $data['filename'],
            $downloadToken
        );
        
        return $this->json($result);
    }
    
    #[Route('/modulos/{id}/toggle', name: 'modulos_toggle_state', methods: ['POST'])]
    public function toggleModuleState(Request $request, int $id): Response
    {
        $auth = $this->requireJwtRoles($request, ['ROLE_ADMIN']);
        if ($auth instanceof Response) {
            return $auth;
        }
        
        $modulo = $this->entityManager->getRepository(Modulo::class)->find($id);
        
        if (!$modulo) {
            $this->addFlash('error', 'Módulo no encontrado');
            return $this->redirectToRoute('modulos_index');
        }
        
        $modulo->setEstado(!$modulo->isEstado());
        
        if (!$modulo->isEstado()) {
            $modulo->setUninstallDate(new \DateTimeImmutable());
        } else {
            $modulo->setInstallDate(new \DateTimeImmutable());
            $modulo->setUninstallDate(null);
        }
        
        $this->entityManager->flush();
        
        $message = $modulo->isEstado() ? 'Módulo activado correctamente' : 'Módulo desactivado correctamente';
        $this->addFlash('success', $message);
        
        return $this->redirectToRoute('modulos_index');
    }
    
    #[Route('/modulos/{id}/uninstall', name: 'modulos_uninstall', methods: ['POST'])]
    public function uninstallModule(Request $request, int $id): Response
    {
        $auth = $this->requireJwtRoles($request, ['ROLE_ADMIN']);
        if ($auth instanceof Response) {
            return $auth;
        }
        
        $modulo = $this->entityManager->getRepository(Modulo::class)->find($id);
        
        if (!$modulo) {
            $this->addFlash('error', 'Módulo no encontrado');
            return $this->redirectToRoute('modulos_index');
        }
        
        $modulo->setEstado(false);
        $modulo->setUninstallDate(new \DateTimeImmutable());
        
        $this->entityManager->flush();
        
        $this->addFlash('success', 'Módulo desinstalado correctamente');
        
        return $this->redirectToRoute('modulos_index');
    }
    
    #[Route('/api/modulos/{id}/uninstall', name: 'api_uninstall_module', methods: ['POST'])]
    public function apiUninstallModule(Request $request, int $id): JsonResponse
    {
        try {
            $auth = $this->requireJwtRoles($request, ['ROLE_ADMIN']);
            if ($auth instanceof JsonResponse) {
                return $auth;
            }
            
            $modulo = $this->entityManager->getRepository(Modulo::class)->find($id);
            
            if (!$modulo) {
                return $this->json([
                    'success' => false,
                    'message' => 'Módulo no encontrado'
                ], 404);
            }
            
            $data = json_decode($request->getContent(), true) ?? [];
            $completeRemoval = isset($data['completeRemoval']) && $data['completeRemoval'] === true;
            
            $moduleDirectory = $this->findModuleDirectory($modulo->getNombre());
            $commandsJsonPath = $moduleDirectory ? $moduleDirectory . '/commands.json' : null;
            $commandOutput = '';
            $commandSuccess = false;
                        
            if ($completeRemoval) {
                $this->limpiarRelacionesModulo($modulo);
                
                $this->entityManager->remove($modulo);
            } else {
                $modulo->setEstado(false);
                $modulo->setUninstallDate(new \DateTimeImmutable());
            }
            
            $this->entityManager->flush();
            
            return $this->json([
                'success' => true,
                'commandSuccess' => $commandSuccess,
                'message' => $completeRemoval 
                    ? 'Módulo eliminado completamente' 
                    : 'Módulo desinstalado correctamente',
                'commandOutput' => $commandOutput,
                'completeRemoval' => $completeRemoval
            ]);
        } catch (\Exception $e) {
            return $this->json([
                'success' => false,
                'message' => 'Error al procesar la solicitud: ' . $e->getMessage(),
                'stackTrace' => $e->getTraceAsString()
            ], 500);
        }
    }

    private function limpiarRelacionesModulo(Modulo $modulo): void
{
    $menuElements = $modulo->getMenuElements();
    if ($menuElements) {
        foreach ($menuElements as $menuElement) {
            $modulo->removeMenuElement($menuElement);
        }
    }
    
    
    $this->entityManager->flush();
}
    
    private function findModuleDirectory(string $moduleName): ?string
    {
        $srcDir = $this->projectDir . '/src';
        
        $directPath = $srcDir . '/ModuloMusica';
        if (is_dir($directPath)) {
            return $directPath;
        }
        
        $exactPath = $srcDir . '/' . $moduleName;
        if (is_dir($exactPath)) {
            return $exactPath;
        }
        
        $moduloPath = $srcDir . '/Modulo' . $moduleName;
        if (is_dir($moduloPath)) {
            return $moduloPath;
        }
        
        $normalizedName = $this->normalizeString($moduleName);
        
        $dirContents = scandir($srcDir);
        
        foreach ($dirContents as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            
            $itemPath = $srcDir . '/' . $item;
            
            if (is_dir($itemPath)) {
                $normalizedItemName = $this->normalizeString($item);
                
                if ($normalizedItemName === $normalizedName || 
                    $normalizedItemName === 'modulo' . $normalizedName ||
                    strpos($normalizedItemName, $normalizedName) !== false) {
                    return $itemPath;
                }
            }
        }
        
        return null;
    }
    
    private function normalizeString(string $string): string
    {
        $string = strtolower($string);
        
        $replacements = [
            'á' => 'a', 'é' => 'e', 'í' => 'i', 'ó' => 'o', 'ú' => 'u',
            'ä' => 'a', 'ë' => 'e', 'ï' => 'i', 'ö' => 'o', 'ü' => 'u',
            'à' => 'a', 'è' => 'e', 'ì' => 'i', 'ò' => 'o', 'ù' => 'u',
            'ñ' => 'n', 'ç' => 'c'
        ];
        
        return str_replace(array_keys($replacements), array_values($replacements), $string);
    }
}