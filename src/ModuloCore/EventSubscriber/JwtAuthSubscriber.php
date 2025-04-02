<?php

namespace App\ModuloCore\EventSubscriber;

use App\ModuloCore\Service\JwtAuthService;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\KernelEvents;

class JwtAuthSubscriber implements EventSubscriberInterface
{
    private JwtAuthService $jwtAuthService;
    private RequestStack $requestStack;
    
    public function __construct(JwtAuthService $jwtAuthService, RequestStack $requestStack)
    {
        $this->jwtAuthService = $jwtAuthService;
        $this->requestStack = $requestStack;
    }
    
    public static function getSubscribedEvents(): array
    {
        return [
            KernelEvents::REQUEST => ['onKernelRequest', 30],
            KernelEvents::RESPONSE => ['onKernelResponse', -10],
        ];
    }
    
    public function onKernelRequest(RequestEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }
        
        $request = $event->getRequest();
        $path = $request->getPathInfo();
        
        $user = $this->jwtAuthService->getAuthenticatedUser($request);
        
        if ($user) {
            $request->attributes->set('jwt_user', $user);
            
            if ($request->hasSession()) {
                $session = $request->getSession();
                $session->set('jwt_auth', [
                    'user_id' => $user->getId(),
                    'timestamp' => time(),
                ]);
            }
        }
        
        $publicPaths = [
            '/login',
            '/register',
            '/register-ip',
            '/jwt-logout',
            '/api/auth',
            '/',
            '/modulos',
            '/css/', 
            '/js/',
            '/images/',
            '/favicon.ico',
        ];
        
        foreach ($publicPaths as $publicPath) {
            if (strpos($path, $publicPath) === 0) {
                return;
            }
        }
        
        if (!$user) {
            $this->handleUnauthenticatedAccess($event, $request, $path);
        }
    }
    
    private function handleUnauthenticatedAccess(RequestEvent $event, Request $request, string $path): void
    {
        if ($request->isXmlHttpRequest() || $this->isApiRequest($path)) {
            $response = new JsonResponse([
                'success' => false,
                'error' => 'Se requiere autenticación',
                'needsAuthentication' => true
            ], 401);
            
            $event->setResponse($response);
        } else {
            $redirectUrl = '/login?redirect=' . urlencode($request->getUri());
            $response = new RedirectResponse($redirectUrl);
            
            $event->setResponse($response);
        }
    }

    public function onKernelResponse(ResponseEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }
        
        $request = $event->getRequest();
        $response = $event->getResponse();
        
        $token = $this->jwtAuthService->getTokenFromRequest($request);
        
        if ($token) {
            $payload = $this->jwtAuthService->verifyToken($token, false);
            
            if ($payload && isset($payload['exp']) && $payload['exp'] - time() < 14400) {
                $user = $request->attributes->get('jwt_user');
                
                if (!$user && isset($payload['uid'])) {
                    $userRepository = $this->jwtAuthService->getUserRepository();
                    $user = $userRepository->find($payload['uid']);
                }
                
                if ($user) {
                    $response = $this->jwtAuthService->addTokenCookie($response, $user, isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off');
                    $event->setResponse($response);
                }
            }
        }
    }
    
    private function isApiRequest(string $path): bool
    {
        return strpos($path, '/api/') === 0;
    }
}