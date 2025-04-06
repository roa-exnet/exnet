<?php
namespace App\ModuloCore\Repository;

use App\ModuloCore\Entity\MenuElement;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

class MenuElementRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, MenuElement::class);
    }

    public function getMenuHierarchy(): array
    {
        $menus = $this->createQueryBuilder('m')
            ->where('m.enabled = :enabled')
            ->setParameter('enabled', true)
            ->orderBy('m.orden', 'ASC')
            ->getQuery()
            ->getResult();

        $hierarchy = [];
        $byParent = [];

        foreach ($menus as $menu) {
            $byParent[$menu->getParentId()][] = $menu;
        }

        if (isset($byParent[0])) {
            foreach ($byParent[0] as $rootMenu) {
                $hierarchy[] = [
                    'menu' => $rootMenu,
                    'submenus' => $byParent[$rootMenu->getId()] ?? []
                ];
            }
        }

        return $hierarchy;
    }
}