<?php

namespace App\ModuloChat\EventListener;

use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Psr\Log\LoggerInterface;

class WebSocketEventListener implements EventSubscriberInterface
{
    private $logger;
    private $connections = [];
    private $chatSubscriptions = [];

    public function __construct(LoggerInterface $logger)
    {
        $this->logger = $logger;
    }

    public static function getSubscribedEvents()
    {
        return [
            KernelEvents::RESPONSE => 'onKernelResponse',
        ];
    }

    public function onKernelResponse(ResponseEvent $event)
    {
        $request = $event->getRequest();
        
        if ($request->headers->get('Upgrade') === 'websocket') {
            $response = $event->getResponse();
            $response->headers->set('Upgrade', 'websocket');
            $response->headers->set('Connection', 'Upgrade');
            
            $key = $request->headers->get('Sec-WebSocket-Key');
            if ($key) {
                $acceptKey = base64_encode(sha1($key . '258EAFA5-E914-47DA-95CA-C5AB0DC85B11', true));
                $response->headers->set('Sec-WebSocket-Accept', $acceptKey);
            }
            
            $clientId = uniqid('client_');
            $this->connections[$clientId] = [
                'id' => $clientId,
                'connected' => true,
                'lastActivity' => time()
            ];
            
            $this->logger->info("Nueva conexión WebSocket: {$clientId}");
            
            $response->setContent('');
            $response->setStatusCode(101);
        
            
            $event->stopPropagation();
        }
    }
    
    public function sendToClient(string $clientId, array $data)
    {
        if (!isset($this->connections[$clientId]) || !$this->connections[$clientId]['connected']) {
            return false;
        }
        
        $this->logger->info("Enviando mensaje a cliente {$clientId}: " . json_encode($data));
        
        return true;
    }
    
    public function broadcastToChat(int $chatId, array $data)
    {
        if (!isset($this->chatSubscriptions[$chatId])) {
            return;
        }
        
        foreach ($this->chatSubscriptions[$chatId] as $clientId) {
            $this->sendToClient($clientId, $data);
        }
    }
    
    public function subscribeToChat(string $clientId, int $chatId)
    {
        if (!isset($this->chatSubscriptions[$chatId])) {
            $this->chatSubscriptions[$chatId] = [];
        }
        
        if (!in_array($clientId, $this->chatSubscriptions[$chatId])) {
            $this->chatSubscriptions[$chatId][] = $clientId;
            $this->logger->info("Cliente {$clientId} suscrito al chat {$chatId}");
        }
    }

    public function closeConnection(string $clientId)
    {
        if (isset($this->connections[$clientId])) {
            $this->connections[$clientId]['connected'] = false;
            $this->logger->info("Conexión cerrada: {$clientId}");
            
            foreach ($this->chatSubscriptions as $chatId => $clients) {
                $index = array_search($clientId, $clients);
                if ($index !== false) {
                    unset($this->chatSubscriptions[$chatId][$index]);
                }
            }
        }
    }
}