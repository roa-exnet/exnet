<?php

namespace App\ModuloCore\Service;

use App\ModuloCore\Entity\User;
use App\ModuloCore\Repository\UserRepository;
use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use Symfony\Component\HttpFoundation\Cookie;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Doctrine\ORM\EntityManagerInterface;

class JwtAuthService
{
    private string $jwtKey;
    private int $tokenLifetime;
    private string $cookieName;
    private IpAuthService $ipAuthService;
    private EntityManagerInterface $entityManager;
    private ?UserRepository $userRepository = null;
    
    public function __construct(
        string $appSecret,
        IpAuthService $ipAuthService,
        EntityManagerInterface $entityManager
    ) {
        $this->jwtKey = hash('sha256', $appSecret . '_exnet_jwt_key');
        $this->tokenLifetime = 86400;
        $this->cookieName = 'exnet_auth_jwt';
        $this->ipAuthService = $ipAuthService;
        $this->entityManager = $entityManager;
    }
    
    public function generateToken(User $user, ?string $ipAddress = null): string
    {
        $now = new \DateTimeImmutable();
        
        if ($ipAddress === null) {
            $ipAddress = $user->getIpAddress() ?: $this->ipAuthService->getCurrentIp();
        }
        
        $payload = [
            'iat' => $now->getTimestamp(),
            'exp' => $now->modify('+24 hours')->getTimestamp(),
            'uid' => $user->getId(),
            'email' => $user->getEmail(),
            'name' => $user->getNombre() . ' ' . $user->getApellidos(),
            'roles' => $user->getRoles(),
            'ip' => $ipAddress,
        ];
        
        return JWT::encode($payload, $this->jwtKey, 'HS256');
    }
    
    public function verifyToken(string $token, bool $checkIp = true): ?array
    {
        try {
            $decoded = (array) JWT::decode($token, new Key($this->jwtKey, 'HS256'));
            
            if (isset($decoded['exp']) && $decoded['exp'] < time()) {
                return null;
            }
            
            if ($checkIp && isset($decoded['ip'])) {
                $currentIp = $this->ipAuthService->getCurrentIp();
                
                if ($currentIp !== $decoded['ip'] && !empty($decoded['ip'])) {
                    return null;
                }
            }
            
            return $decoded;
        } catch (\Exception $e) {
            return null;
        }
    }
    
    public function addTokenCookie(Response $response, User $user, bool $secure = false): Response
    {
        $token = $this->generateToken($user, $this->ipAuthService->getCurrentIp());
        
        $cookie = Cookie::create($this->cookieName)
            ->withValue($token)
            ->withExpires(time() + $this->tokenLifetime)
            ->withPath('/')
            ->withSecure($secure)
            ->withHttpOnly(true);
            
        $response->headers->setCookie($cookie);
        
        return $response;
    }
    
    public function removeTokenCookie(Response $response): Response
    {
        $response->headers->clearCookie($this->cookieName, '/', null, true, true);
        return $response;
    }
    
    public function getTokenFromRequest(Request $request): ?string
    {
        if ($request->cookies->has($this->cookieName)) {
            return $request->cookies->get($this->cookieName);
        }
        
        $authHeader = $request->headers->get('Authorization');
        if ($authHeader && preg_match('/Bearer\s+(.+)/', $authHeader, $matches)) {
            return $matches[1];
        }
        
        $token = $request->query->get('jwt_token');
        if ($token) {
            return $token;
        }
        
        return null;
    }
    
    public function getAuthenticatedUser(Request $request): ?User
    {
        $userFromRequest = $request->attributes->get('jwt_user');
        if ($userFromRequest instanceof User) {
            return $userFromRequest;
        }
        
        $token = $this->getTokenFromRequest($request);
        if ($token) {
            $payload = $this->verifyToken($token);
            if ($payload && isset($payload['uid'])) {
                $user = $this->getUserRepository()->find($payload['uid']);
                if ($user) {
                    return $user;
                }
            }
        }
        
        if ($request->hasSession() && $request->getSession()->has('jwt_auth')) {
            $sessionData = $request->getSession()->get('jwt_auth');
            if (isset($sessionData['user_id']) && isset($sessionData['timestamp'])) {
                if (time() - $sessionData['timestamp'] < 1800) {
                    $user = $this->getUserRepository()->find($sessionData['user_id']);
                    if ($user) {
                        $sessionData['timestamp'] = time();
                        $request->getSession()->set('jwt_auth', $sessionData);
                        
                        if (!$token) {
                            $response = new Response();
                            $this->addTokenCookie($response, $user, $this->isSecureContext());
                            $response->sendHeaders();
                        }
                        
                        return $user;
                    }
                }
            }
        }
        
        $user = $this->ipAuthService->getCurrentUser();
        if ($user) {
            if (!$token) {
                $response = new Response();
                $this->addTokenCookie($response, $user, $this->isSecureContext());
                $response->sendHeaders();
            }
            return $user;
        }
        
        return null;
    }
    
    public function getUserRepository(): UserRepository
    {
        if (!$this->userRepository) {
            $this->userRepository = $this->entityManager->getRepository(User::class);
        }
        
        return $this->userRepository;
    }
    
    public function checkAccess(Request $request, array $requiredRoles = []): bool
    {
        $user = $this->getAuthenticatedUser($request);
        
        if (!$user) {
            return false;
        }
        
        if (empty($requiredRoles)) {
            return true;
        }
        
        $userRoles = $user->getRoles();
        foreach ($requiredRoles as $role) {
            if (in_array($role, $userRoles)) {
                return true;
            }
        }
        
        return false;
    }
    
    private function isSecureContext(): bool
    {
        return isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off';
    }
}