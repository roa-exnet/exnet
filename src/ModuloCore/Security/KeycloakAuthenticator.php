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
    public function supports(Request $request): ?bool
    {
        return true;
    }

    public function authenticate(Request $request): Passport
    {
        $token = $request->cookies->get('module_token');

        if (!$token) {
            throw new CustomUserMessageAuthenticationException('Token ausente en cookie.');
        }

        try {
            $decoded = $this->validateJwt($token);
            $decodedArray = $this->convertToArray($decoded);

            $username = $decodedArray['preferred_username'] ?? 'anonymous';
            $roles = $decodedArray['realm_access']['roles'] ?? [];

            return new SelfValidatingPassport(
                new UserBadge($username, fn($userIdentifier) => new KeycloakUser($userIdentifier, $roles))
            );
        } catch (\Exception) {
            throw new CustomUserMessageAuthenticationException('Token inválido o expirado.');
        }
    }

    public function onAuthenticationSuccess(Request $request, TokenInterface $token, string $firewallName): ?Response
    {
        return null;
    }

    public function onAuthenticationFailure(Request $request, AuthenticationException $exception): ?Response
    {
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
        return is_object($data) ? $this->convertToArray((array)$data) :
               (is_array($data) ? array_map([$this, 'convertToArray'], $data) : $data);
    }

    public function start(Request $request, AuthenticationException $authException = null): JsonResponse
    {
        return new JsonResponse(['error' => 'Acceso no autorizado'], 401);
    }
}