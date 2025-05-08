<?php
namespace App\ModuloCore\Service;

use Firebase\JWT\JWT;
use Firebase\JWT\Key;

class CookieValidatorService
{
    public function validate(string $token): ?array
    {
        try {
            $key = file_get_contents(__DIR__ . '/../Security/Data/key.txt');
            $decoded = JWT::decode($token, new Key($key, 'RS256'));
            return (array) $decoded;
        } catch (\Exception) {
            return null;
        }
    }
}