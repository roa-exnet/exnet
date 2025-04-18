<?php

namespace App\ModuloChat\Service;

use App\ModuloChat\Entity\Chat;
use App\ModuloChat\Entity\ChatMessage;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\ParameterBag\ParameterBagInterface;
use Symfony\Component\Cache\Adapter\FilesystemAdapter;
use Symfony\Contracts\Cache\ItemInterface;

class WebSocketService
{
    private $logger;
    private $params;
    private $cache;
    
    public function __construct(LoggerInterface $logger, ParameterBagInterface $params)
    {
        $this->logger = $logger;
        $this->params = $params;
        $this->cache = new FilesystemAdapter('chat_events', 0, sys_get_temp_dir());
    }
    
    public function publishToChat(int $chatId, string $type, array $data): bool
    {
        $eventData = [
            'type' => $type,
            'chatId' => $chatId,
            'data' => $data,
            'timestamp' => (new \DateTimeImmutable())->format('c')
        ];
        
        return $this->storeEvent($chatId, $eventData);
    }
    
    public function notifyNewMessage(ChatMessage $message): bool
    {
        return $this->publishToChat(
            $message->getChat()->getId(),
            'message',
            [
                'id' => $message->getId(),
                'content' => $message->getContent(),
                'senderId' => $message->getSenderIdentifier(),
                'senderName' => $message->getSenderName(),
                'messageType' => $message->getMessageType(),
                'timestamp' => $message->getSentAt()->format('Y-m-d H:i:s')
            ]
        );
    }
    
    public function notifyMessagesRead(Chat $chat, string $userId): bool
    {
        return $this->publishToChat(
            $chat->getId(),
            'read',
            [
                'userId' => $userId,
                'timestamp' => (new \DateTimeImmutable())->format('Y-m-d H:i:s')
            ]
        );
    }
    
    public function notifyTyping(Chat $chat, string $userId, string $userName): bool
    {
        return $this->publishToChat(
            $chat->getId(),
            'typing',
            [
                'userId' => $userId,
                'userName' => $userName,
                'timestamp' => (new \DateTimeImmutable())->format('Y-m-d H:i:s')
            ]
        );
    }
    
    private function storeEvent(int $chatId, array $eventData): bool
    {
        try {
            $cacheKey = 'chat_' . $chatId . '_events';
            
            $this->cache->get($cacheKey, function (ItemInterface $item) use ($eventData, $cacheKey) {
                $item->expiresAfter(3600);
                
                $events = $this->cache->getItem($cacheKey)->get() ?: [];
                
                $eventData['id'] = uniqid();
                $events[] = $eventData;
                
                if (count($events) > 100) {
                    $events = array_slice($events, -100);
                }
                
                return $events;
            });
            
            $this->logger->info("Evento almacenado para chat {$chatId}: " . json_encode($eventData));
            return true;
        } catch (\Exception $e) {
            $this->logger->error("Error al almacenar evento: " . $e->getMessage());
            return false;
        }
    }
    
    public function getEvents(int $chatId, string $lastEventId = null): array
    {
        $cacheKey = 'chat_' . $chatId . '_events';
        $events = $this->cache->getItem($cacheKey)->get() ?: [];
        
        if ($lastEventId === null) {
            return empty($events) ? [] : [end($events)];
        }
        
        $filteredEvents = [];
        $foundLastEvent = false;
        
        foreach ($events as $event) {
            if ($foundLastEvent) {
                $filteredEvents[] = $event;
            } elseif ($event['id'] === $lastEventId) {
                $foundLastEvent = true;
            }
        }
        
        return $filteredEvents;
    }
    
    public function cleanupOldEvents(): int
    {
        $cleanedCount = 0;
        
        $this->cache->prune();
        
        return $cleanedCount;
    }
}