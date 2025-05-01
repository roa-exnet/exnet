<?php

namespace App\ModuloCore\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;
use App\ModuloCore\Service\EncryptionService;
use Doctrine\ORM\EntityManagerInterface;
use App\ModuloCore\Entity\User;

class UserProfileController extends AbstractController
{
    use JwtAuthControllerTrait;
    
    private EntityManagerInterface $entityManager;
    private ?EncryptionService $encryptionService;
    
    public function __construct(
        EntityManagerInterface $entityManager,
        EncryptionService $encryptionService = null
    ) {
        $this->entityManager = $entityManager;
        $this->encryptionService = $encryptionService;
    }

    #[Route('/mi-perfil', name: 'user_profile')]
    public function profile(Request $request): Response
    {
        $authResult = $this->requireJwtAuthentication($request);
        if ($authResult instanceof Response) {
            return $authResult;
        }
        
        $user = $authResult;
        
        if ($this->encryptionService) {
            $user->setEncryptionService($this->encryptionService);
        }
        
        $storedUser = $this->entityManager->getRepository(User::class)->find($user->getId());
        if (!$storedUser) {
            throw $this->createNotFoundException('Usuario no encontrado');
        }
        
        if ($this->encryptionService) {
            $storedUser->setEncryptionService($this->encryptionService);
        }
        
        return $this->render('mi-perfil.html.twig', [
            'user' => $storedUser
        ]);
    }
}