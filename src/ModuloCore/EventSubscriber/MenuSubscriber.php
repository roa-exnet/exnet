<?php

namespace App\ModuloCore\EventSubscriber;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\ControllerEvent;
use Doctrine\ORM\EntityManagerInterface;
use Twig\Environment;

class MenuSubscriber implements EventSubscriberInterface
{
    private EntityManagerInterface $entityManager;
    private Environment $twig;

    public function __construct(EntityManagerInterface $entityManager, Environment $twig)
    {
        $this->entityManager = $entityManager;
        $this->twig = $twig;
    }

    public function onKernelController(ControllerEvent $event): void
    {
        $menuElements = $this->entityManager->getRepository(\App\ModuloCore\Entity\MenuElement::class)
            ->findBy(['type' => 'menu', 'enabled' => 1]);
            
        $mainMenus = [];
        $subMenus = [];
        
        foreach ($menuElements as $menu) {
            if ($menu->getParentId() == 0) {
                $mainMenus[$menu->getId()] = $menu;
            } else {
                if (!isset($subMenus[$menu->getParentId()])) {
                    $subMenus[$menu->getParentId()] = [];
                }
                $subMenus[$menu->getParentId()][] = $menu;
            }
        }
        
        $menuStructure = [];
        foreach ($mainMenus as $id => $menu) {
            $menuStructure[] = [
                'menu' => $menu,
                'submenus' => isset($subMenus[$id]) ? $subMenus[$id] : []
            ];
        }
        
        $this->twig->addGlobal('menuStructure', $menuStructure);
        $this->twig->addGlobal('menus', array_values($mainMenus));
    }

    public static function getSubscribedEvents(): array
    {
        return [
            ControllerEvent::class => 'onKernelController',
        ];
    }
}