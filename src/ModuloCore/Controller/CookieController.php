<?php
namespace App\ModuloCore\Controller;

use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\Cookie;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;

class CookieController
{
    #[Route('/generar-cookie/{modulo}', name: 'generar_cookie', methods: ['GET'])]
    public function generarCookie(string $modulo, EntityManagerInterface $em): Response
    {
        $conn = $em->getConnection();

        $stmt = $conn->prepare('SELECT token FROM licencia WHERE nombre = :nombre');
        $result = $stmt->executeQuery(['nombre' => $modulo])->fetchAssociative();

        if (!$result || !isset($result['token'])) {
            return new Response('Licencia no encontrada o no valida para el módulo', 404);
        }

        $response = new Response('Cookie generada');
        $cookie = Cookie::create('module_token')
            ->withValue($result['token'])
            ->withPath('/')
            ->withSecure(false)
            ->withHttpOnly(true);

        $response->headers->setCookie($cookie);
        return $response;
    }
}