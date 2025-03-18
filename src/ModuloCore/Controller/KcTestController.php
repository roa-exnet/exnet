<?php

namespace App\ModuloCore\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;

class KcTestController extends AbstractController
{
    #[Route('/kc/test', name: 'kc_test')]
    public function index(): Response
    {
        return $this->render('test.html.twig');
    }
}
