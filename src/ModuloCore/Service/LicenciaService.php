<?php
namespace App\ModuloCore\Service;

use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\Cookie;

class LicenciaService
{
    public function __construct(private EntityManagerInterface $em) {}

    public function generarCookieParaModulo(string $modulo): ?Cookie
    {
        $conn = $this->em->getConnection();
        $stmt = $conn->prepare('SELECT token FROM licencia WHERE nombre = :nombre');
        $result = $stmt->executeQuery(['nombre' => $modulo])->fetchAssociative();

        if (!$result || !isset($result['token'])) {
            return null;
        }

        return Cookie::create('module_token')
            ->withValue($result['token'])
            ->withPath('/')
            ->withSecure(false)
            ->withHttpOnly(true);
    }
}