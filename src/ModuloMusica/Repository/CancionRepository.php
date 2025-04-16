<?php

namespace App\ModuloMusica\Repository;

use App\ModuloMusica\Entity\Cancion;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Cancion>
 */
class CancionRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Cancion::class);
    }

    public function findByGeneroAndSearch(?int $generoId = null, ?string $busqueda = null)
    {
        $qb = $this->createQueryBuilder('c')
            ->orderBy('c.creadoEn', 'DESC');

        if ($generoId) {
            $qb->andWhere('c.genero = :generoId')
               ->setParameter('generoId', $generoId);
        }

        if ($busqueda) {
            $qb->andWhere(
                $qb->expr()->orX(
                    $qb->expr()->like('c.titulo', ':busqueda'),
                    $qb->expr()->like('c.artista', ':busqueda'),
                    $qb->expr()->like('c.album', ':busqueda')
                )
            )
            ->setParameter('busqueda', '%' . $busqueda . '%');
        }

        return $qb->getQuery()->getResult();
    }

    public function findByArtista(string $artista)
    {
        return $this->createQueryBuilder('c')
            ->andWhere('c.artista = :artista')
            ->setParameter('artista', $artista)
            ->orderBy('c.album', 'ASC')
            ->addOrderBy('c.anio', 'ASC')
            ->getQuery()
            ->getResult();
    }
    
    public function findByAlbum(string $album)
    {
        return $this->createQueryBuilder('c')
            ->andWhere('c.album = :album')
            ->setParameter('album', $album)
            ->orderBy('c.titulo', 'ASC')
            ->getQuery()
            ->getResult();
    }

    public function findTopArtistas(int $limit = 10)
    {
        $conn = $this->getEntityManager()->getConnection();
        $sql = '
            SELECT artista, COUNT(*) as canciones_count 
            FROM musica_cancion 
            WHERE artista IS NOT NULL AND artista != \'\' 
            GROUP BY artista 
            ORDER BY canciones_count DESC 
            LIMIT :limit
        ';
        
        $stmt = $conn->prepare($sql);
        $stmt->bindValue('limit', $limit, \PDO::PARAM_INT);
        $result = $stmt->executeQuery();
        
        return $result->fetchAllAssociative();
    }
    
    public function findTopAlbumes(int $limit = 10)
    {
        $conn = $this->getEntityManager()->getConnection();
        $sql = '
            SELECT album, artista, COUNT(*) as canciones_count 
            FROM musica_cancion 
            WHERE album IS NOT NULL AND album != \'\' 
            GROUP BY album, artista
            ORDER BY canciones_count DESC 
            LIMIT :limit
        ';
        
        $stmt = $conn->prepare($sql);
        $stmt->bindValue('limit', $limit, \PDO::PARAM_INT);
        $result = $stmt->executeQuery();
        
        return $result->fetchAllAssociative();
    }
    
    public function findRecentSongs(int $limit = 10)
    {
        return $this->createQueryBuilder('c')
            ->orderBy('c.creadoEn', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }
}