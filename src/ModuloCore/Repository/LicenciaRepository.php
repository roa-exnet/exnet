<?php

namespace App\ModuloCore\Repository;

use App\ModuloCore\Entity\Licencia;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Licencia>
 */
class LicenciaRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Licencia::class);
    }
    
    /**
     * Encuentra una licencia por su token
     */
    public function findByToken(string $token): ?Licencia
    {
        return $this->createQueryBuilder('l')
            ->andWhere('l.token = :token')
            ->setParameter('token', $token)
            ->getQuery()
            ->getOneOrNullResult();
    }
}