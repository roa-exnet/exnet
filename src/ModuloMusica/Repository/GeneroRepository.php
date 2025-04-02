<?php

namespace App\ModuloMusica\Repository;

use App\ModuloMusica\Entity\Genero;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Genero>
 */
class GeneroRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Genero::class);
    }

    public function findAllOrdered()
    {
        return $this->createQueryBuilder('g')
            ->orderBy('g.nombre', 'ASC')
            ->getQuery()
            ->getResult();
    }

    public function findWithCancionCount()
    {
        return $this->createQueryBuilder('g')
            ->select('g as genero', 'COUNT(c.id) as cancionCount')
            ->leftJoin('g.canciones', 'c')
            ->groupBy('g.id')
            ->orderBy('g.nombre', 'ASC')
            ->getQuery()
            ->getResult();
    }
}