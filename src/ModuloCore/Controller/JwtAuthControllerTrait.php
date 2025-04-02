<?php

namespace App\ModuloCore\Controller;

use App\ModuloCore\Entity\User;
use App\ModuloCore\Service\JwtAuthService;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

trait JwtAuthControllerTrait
{
    protected function getJwtUser(Request $request): ?User
    {
        $user = $request->attributes->get('jwt_user');
        if ($user instanceof User) {
            return $user;
        }
        
        if (isset($this->jwtAuthService) && $this->jwtAuthService instanceof JwtAuthService) {
            return $this->jwtAuthService->getAuthenticatedUser($request);
        }
        
        return null;
    }
    
    protected function isJwtAuthenticated(Request $request): bool
    {
        return $this->getJwtUser($request) !== null;
    }
    
    protected function requireJwtAuthentication(Request $request)
    {
        $user = $this->getJwtUser($request);
        
        if (!$user) {
            if ($request->isXmlHttpRequest()) {
                return new JsonResponse([
                    'success' => false,
                    'error' => 'Se requiere autenticación',
                    'needsAuthentication' => true
                ], Response::HTTP_UNAUTHORIZED);
            }
            
            return $this->redirectToRoute('app_login', [
                'redirect' => $request->getUri()
            ]);
        }
        
        return $user;
    }
    
    protected function requireJwtRoles(Request $request, array $roles)
    {
        $user = $this->getJwtUser($request);
        
        if (!$user) {
            if ($request->isXmlHttpRequest()) {
                return new JsonResponse([
                    'success' => false,
                    'error' => 'Se requiere autenticación',
                    'needsAuthentication' => true
                ], Response::HTTP_UNAUTHORIZED);
            }
            
            return $this->redirectToRoute('app_login', [
                'redirect' => $request->getUri()
            ]);
        }
        
        $userRoles = $user->getRoles();
        $hasRole = false;
        
        foreach ($roles as $role) {
            if (in_array($role, $userRoles)) {
                $hasRole = true;
                break;
            }
        }
        
        if (!$hasRole) {
            if ($request->isXmlHttpRequest()) {
                return new JsonResponse([
                    'success' => false,
                    'error' => 'No tienes permiso para acceder a este recurso',
                    'accessDenied' => true
                ], Response::HTTP_FORBIDDEN);
            }
            
            return $this->redirectToRoute('landing');
        }
        
        return $user;
    }
    
    protected function refreshJwtToken(Request $request, Response $response): Response
    {
        if (isset($this->jwtAuthService) && $this->jwtAuthService instanceof JwtAuthService) {
            $user = $this->getJwtUser($request);
            
            if ($user) {
                return $this->jwtAuthService->addTokenCookie(
                    $response, 
                    $user, 
                    isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off'
                );
            }
        }
        
        return $response;
    }
}