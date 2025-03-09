<?php
namespace App\ModuloCore\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;
use Doctrine\ORM\EntityManagerInterface;
use App\ModuloCore\Entity\Modulo;

class ModulosController extends AbstractController
{
    #[Route('/modulos', name: 'modulos_index')]
    public function index(EntityManagerInterface $entityManager): Response
    {
        $modulos = $entityManager ->getRepository(Modulo::class)->findAll();
        return $this->render('modulos.html.twig', [
            'modulos' => $modulos
        ]);
    }
}
