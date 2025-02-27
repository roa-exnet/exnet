<?php
namespace App\ModuloCore\Controller;

use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;

class LandingController extends AbstractController
{
    #[Route('/', name: 'landing')]
    public function index(): Response
    {
        return $this->render('landing.html.twig');
    }
}
