<?php

namespace App\ModuloCore\EventSubscriber;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\ControllerEvent;
use Doctrine\ORM\EntityManagerInterface;
use Twig\Environment;
use Symfony\Component\HttpFoundation\RequestStack;

class MenuSubscriber implements EventSubscriberInterface
{
    private EntityManagerInterface $entityManager;
    private Environment $twig;
    private RequestStack $requestStack;

    public function __construct(EntityManagerInterface $entityManager, Environment $twig, RequestStack $requestStack)
    {
        $this->entityManager = $entityManager;
        $this->twig = $twig;
        $this->requestStack = $requestStack;
    }

    public function onKernelController(ControllerEvent $event): void
    {
        $request = $this->requestStack->getCurrentRequest();
        $user = $request->attributes->get('jwt_user');
        $isAdmin = false;
        
        if ($user && is_array($user->getRoles())) {
            $isAdmin = in_array('ROLE_ADMIN', $user->getRoles());
        }
        
        $menuElements = $this->entityManager->getRepository(\App\ModuloCore\Entity\MenuElement::class)
            ->findBy(['type' => 'menu', 'enabled' => 1]);
            
        $mainMenus = [];
        $subMenus = [];
        
        foreach ($menuElements as $menu) {
            // Filtrar el menú de Respaldos para mostrar solo a administradores
            if ($menu->getNombre() === 'Respaldos' && !$isAdmin) {
                continue;
            }
            
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
        $this->twig->addGlobal('isAdmin', $isAdmin);
    }

    public static function getSubscribedEvents(): array
    {
        return [
            ControllerEvent::class => 'onKernelController',
        ];
    }
}