<?php

namespace App\ModuloStreaming\Repository;

use App\ModuloStreaming\Entity\Video;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Video>
 */
class VideoRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Video::class);
    }

    public function findByCategoriaAndType(int $categoriaId = null, string $tipo = null)
    {
        $qb = $this->createQueryBuilder('v')
            ->orderBy('v.creadoEn', 'DESC');

        if ($categoriaId) {
            $qb->andWhere('v.categoria = :categoriaId')
               ->setParameter('categoriaId', $categoriaId);
        }

        if ($tipo) {
            $qb->andWhere('v.tipo = :tipo')
               ->setParameter('tipo', $tipo);
        }

        return $qb->getQuery()->getResult();
    }

    public function findAllSeries()
    {
        return $this->createQueryBuilder('v')
            ->andWhere('v.tipo = :tipo')
            ->setParameter('tipo', 'serie')
            ->orderBy('v.titulo', 'ASC')
            ->getQuery()
            ->getResult();
    }

    public function findAllPeliculas()
    {
        return $this->createQueryBuilder('v')
            ->andWhere('v.tipo = :tipo')
            ->setParameter('tipo', 'pelicula')
            ->orderBy('v.titulo', 'ASC')
            ->getQuery()
            ->getResult();
    }

    public function findBySearch(string $query)
    {
        return $this->createQueryBuilder('v')
            ->andWhere('v.titulo LIKE :query OR v.descripcion LIKE :query')
            ->setParameter('query', '%' . $query . '%')
            ->orderBy('v.titulo', 'ASC')
            ->getQuery()
            ->getResult();
    }

    public function findSerieEpisodes(string $titulo, int $temporada = null)
    {
        $qb = $this->createQueryBuilder('v')
            ->andWhere('v.tipo = :tipo')
            ->andWhere('v.titulo = :titulo')
            ->setParameter('tipo', 'serie')
            ->setParameter('titulo', $titulo);

        if ($temporada) {
            $qb->andWhere('v.temporada = :temporada')
               ->setParameter('temporada', $temporada);
        }

        $qb->orderBy('v.temporada', 'ASC')
           ->addOrderBy('v.episodio', 'ASC');

        return $qb->getQuery()->getResult();
    }

    public function getSeriesTemporadas(string $titulo)
    {
        return $this->createQueryBuilder('v')
            ->select('DISTINCT v.temporada')
            ->andWhere('v.tipo = :tipo')
            ->andWhere('v.titulo = :titulo')
            ->setParameter('tipo', 'serie')
            ->setParameter('titulo', $titulo)
            ->orderBy('v.temporada', 'ASC')
            ->getQuery()
            ->getSingleColumnResult();
    }
}