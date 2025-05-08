<?php

namespace App\ModuloCore\Helper;

use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use Symfony\Component\HttpFoundation\RequestStack;

class CookieValidator
{
    private RequestStack $requestStack;
    private string $keyPath;

    public function __construct(RequestStack $requestStack)
    {
        $this->requestStack = $requestStack;
        $this->keyPath = __DIR__ . '/../Security/Data/key.txt';
    }

    public function validate(): ?array
    {
        $request = $this->requestStack->getCurrentRequest();
        if (!$request) {
            return null;
        }

        $token = $request->cookies->get('module_token');
        if (!$token || !file_exists($this->keyPath)) {
            return null;
        }

        try {
            $decoded = JWT::decode($token, new Key(file_get_contents($this->keyPath), 'RS256'));
            return json_decode(json_encode($decoded), true);
        } catch (\Throwable) {
            return null;
        }
    }
}
