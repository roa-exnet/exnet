<?php

namespace App\ModuloCore\Security;

use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Security\Http\Authenticator\AbstractAuthenticator;
use Symfony\Component\Security\Http\Authenticator\Passport\Passport;
use Symfony\Component\Security\Http\Authenticator\Passport\SelfValidatingPassport;
use Symfony\Component\Security\Core\Exception\AuthenticationException;
use Symfony\Component\Security\Core\Exception\CustomUserMessageAuthenticationException;
use Symfony\Component\Security\Http\EntryPoint\AuthenticationEntryPointInterface;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\HttpFoundation\Response;
use Firebase\JWT\JWT;
use Firebase\JWT\Key;

use App\ModuloCore\Security\KeycloakUser;

use Symfony\Component\Security\Http\Authenticator\Passport\Badge\UserBadge;
use Symfony\Component\HttpFoundation\RedirectResponse;

class KeycloakAuthenticator extends AbstractAuthenticator implements AuthenticationEntryPointInterface
{
    private string $jwksUrl;

    public function __construct(string $realmUrl)
    {
        $this->jwksUrl = $realmUrl . '/protocol/openid-connect/certs';
    }

    public function supports(Request $request): ?bool
    {
        return true;
    }

    public function authenticate(Request $request): Passport
    {

        $tokenPath = __DIR__ . '/Data/token.txt';

        if (!file_exists($tokenPath)) {
            throw new CustomUserMessageAuthenticationException('No se encontró el archivo token.txt.');
        }
        
        $tokenData = json_decode(file_get_contents($tokenPath), true);
        $token = $tokenData['access_token'] ?? null;
        if (!$token) {
            throw new CustomUserMessageAuthenticationException('No se encontró un token válido en token.txt.');
        }
    
        try {
            $decoded = $this->validateJwt($token);
            $decodedArray = $this->convertToArray($decoded);
    
            $roles = $decodedArray['resource_access']['prodengine']['roles'] ?? [];
            $username = $decodedArray['preferred_username'] ?? 'anonymous';
    
            return new SelfValidatingPassport(
                new UserBadge($username, function ($userIdentifier) use ($roles) {
                    return new KeycloakUser($userIdentifier, $roles);
                })
            );
        } catch (\Exception $e) {
            throw new CustomUserMessageAuthenticationException('Token inválido o expirado.');
        }
    }
    
    

    public function onAuthenticationSuccess(Request $request, TokenInterface $token, string $firewallName): ?Response
    {
        return null;
    }

    public function onAuthenticationFailure(Request $request, AuthenticationException $exception): ?Response
    {
        // return new RedirectResponse('/login');
        return new JsonResponse(['error' => $exception->getMessage()], Response::HTTP_UNAUTHORIZED);
    }

    private function validateJwt(string $token): array
    {
        $keyPath = __DIR__ . '/Data/key.txt';
    
        if (!file_exists($keyPath)) {
            throw new \Exception('No se encontró key.txt');
        }
    
        $keyPem = file_get_contents($keyPath);
        
        return (array) JWT::decode($token, new Key($keyPem, 'RS256'));
    }

    private function convertToArray($data)
    {
        if (is_object($data)) {
            $data = (array) $data;
        }
        if (is_array($data)) {
            foreach ($data as $key => $value) {
                $data[$key] = $this->convertToArray($value);
            }
        }
        return $data;
    }
    public function start(Request $request, AuthenticationException $authException = null): RedirectResponse|JsonResponse
    {
        $firewall = $request->attributes->get('_firewall_context');
        return new JsonResponse(['error' => 'Acceso no autorizado'], 401);
    }
    
    
}
