<?php

namespace App\ModuloMusica\Repository;

use App\ModuloMusica\Entity\Playlist;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Playlist>
 */
class PlaylistRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Playlist::class);
    }

    public function findByUser(string $userId)
    {
        return $this->createQueryBuilder('p')
            ->andWhere('p.creadorId = :userId')
            ->setParameter('userId', $userId)
            ->orderBy('p.creadoEn', 'DESC')
            ->getQuery()
            ->getResult();
    }

    public function findPublicPlaylists(int $limit = 10)
    {
        return $this->createQueryBuilder('p')
            ->andWhere('p.esPublica = :esPublica')
            ->setParameter('esPublica', true)
            ->orderBy('p.creadoEn', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }
    
    public function findPlaylistsWithCancion(int $cancionId)
    {
        return $this->createQueryBuilder('p')
            ->innerJoin('p.canciones', 'c')
            ->andWhere('c.id = :cancionId')
            ->setParameter('cancionId', $cancionId)
            ->getQuery()
            ->getResult();
    }
}