<?php

namespace App\ModuloCore\Security;

use App\ModuloCore\Security\KeycloakUser;
use Doctrine\ORM\EntityManagerInterface;
use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use Symfony\Component\HttpFoundation\Cookie;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Exception\AuthenticationException;
use Symfony\Component\Security\Core\Exception\CustomUserMessageAuthenticationException;
use Symfony\Component\Security\Http\Authenticator\AbstractAuthenticator;
use Symfony\Component\Security\Http\Authenticator\Passport\Badge\UserBadge;
use Symfony\Component\Security\Http\Authenticator\Passport\Passport;
use Symfony\Component\Security\Http\Authenticator\Passport\SelfValidatingPassport;
use Symfony\Component\Security\Http\EntryPoint\AuthenticationEntryPointInterface;

class KeycloakAuthenticator extends AbstractAuthenticator implements AuthenticationEntryPointInterface
{
    private EntityManagerInterface $em;

    public function __construct(EntityManagerInterface $em)
    {
        $this->em = $em;
    }

    public function supports(Request $request): ?bool
    {
        return true;
    }

    public function authenticate(Request $request): Passport
    {
        $modulo = explode('/', trim($request->getPathInfo(), '/'))[1] ?? 'default';
        // dd($modulo);
        $conn = $this->em->getConnection();
        $stmt = $conn->prepare('SELECT token FROM licencia WHERE nombre = :nombre');

        $result = $stmt->executeQuery(['nombre' => $modulo])->fetchAssociative();
        // dd($result);
        if ($result && isset($result['token'])) {
            $cookie = Cookie::create('module_token')
                ->withValue($result['token'])
                ->withPath('/')
                ->withSecure(false)
                ->withHttpOnly(true);

            $request->attributes->set('_set_cookie', $cookie);
        }
        // dd($cookie);
        $token = $cookie->getValue();
        // dd($token);
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

    public function start(Request $request, AuthenticationException $authException = null): Response
    {
        if ($request->cookies->has('module_token')) {
            return new JsonResponse(['error' => 'Acceso no autorizado'], 401);
        }

        $path = $request->getPathInfo();
        $modulo = explode('/', trim($path, '/'))[0] ?? 'default';
        $redirect = $request->getRequestUri();

        $url = '/generar-cookie/' . $modulo . '?redirect=' . urlencode($redirect);
        return new RedirectResponse($url);
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
}
