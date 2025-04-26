<?php

namespace App\ModuloCore\EventSubscriber;

use App\ModuloCore\Entity\User;
use App\ModuloCore\Service\EncryptionService;
use Doctrine\Common\EventSubscriber;
use Doctrine\ORM\Events;
use Doctrine\Persistence\Event\LifecycleEventArgs;

class EntityEncryptionSubscriber implements EventSubscriber
{
    private EncryptionService $encryptionService;
    
    public function __construct(EncryptionService $encryptionService)
    {
        $this->encryptionService = $encryptionService;
    }
    
    /**
     * Retorna los eventos a los que el subscriber está suscrito
     */
    public function getSubscribedEvents(): array
    {
        return [
            Events::postLoad,
            Events::prePersist,
            Events::preUpdate,
        ];
    }
    
    /**
     * Después de cargar una entidad, inyecta el servicio de cifrado 
     * para poder descifrar valores cuando se accede a ellos
     */
    public function postLoad(LifecycleEventArgs $args): void
    {
        $this->processEntity($args->getObject());
    }
    
    /**
     * Antes de persistir una nueva entidad, inyecta el servicio de cifrado
     * para cifrar los valores sensibles
     */
    public function prePersist(LifecycleEventArgs $args): void
    {
        $this->processEntity($args->getObject());
    }
    
    /**
     * Antes de actualizar una entidad, inyecta el servicio de cifrado
     * para cifrar los valores sensibles
     */
    public function preUpdate(LifecycleEventArgs $args): void
    {
        $this->processEntity($args->getObject());
    }
    
    /**
     * Procesa una entidad inyectando el servicio de cifrado si es necesario
     */
    private function processEntity($entity): void
    {
        if ($entity instanceof User) {
            $entity->setEncryptionService($this->encryptionService);
        }
        
        // Aquí se pueden añadir más entidades que requieran cifrado
        // if ($entity instanceof OtraEntidad) {
        //    $entity->setEncryptionService($this->encryptionService);
        // }
    }
}