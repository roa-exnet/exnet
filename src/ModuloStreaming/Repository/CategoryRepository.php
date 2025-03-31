<?php

namespace App\ModuloStreaming\Repository;

use App\ModuloStreaming\Entity\Categoria;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Categoria>
 */
class CategoryRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Categoria::class);
    }

    public function findAllOrdered()
    {
        return $this->createQueryBuilder('c')
            ->orderBy('c.nombre', 'ASC')
            ->getQuery()
            ->getResult();
    }

    public function findWithVideoCount()
    {
        return $this->createQueryBuilder('c')
            ->select('c as categoria', 'COUNT(v.id) as videoCount')
            ->leftJoin('c.videos', 'v')
            ->groupBy('c.id')
            ->orderBy('c.nombre', 'ASC')
            ->getQuery()
            ->getResult();
    }
}