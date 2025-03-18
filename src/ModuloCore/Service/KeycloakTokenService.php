<?php

namespace App\ModuloCore\Service;

use Symfony\Contracts\HttpClient\HttpClientInterface;

class KeycloakTokenService
{
    private HttpClientInterface $httpClient;
    private string $tokenUrl;
    private string $clientId;
    private string $clientSecret;
    private string $username;
    private string $password;
    private string $tokenFile;

    public function __construct(
        HttpClientInterface $httpClient,
        string $realmUrl,
        string $clientId,
        string $clientSecret,
        string $username,
        string $password
    ) {
        $this->httpClient = $httpClient;
        $this->tokenUrl = $realmUrl . '/protocol/openid-connect/token';
        $this->clientId = $clientId;
        $this->clientSecret = $clientSecret;
        $this->username = $username;
        $this->password = $password;
        $this->tokenFile = __DIR__ . '/../Security/Data/token.txt';
    }

    public function getToken(): string
    {
        return $this->requestNewToken();
    }
    private function requestNewToken(): string
    {
        $response = $this->httpClient->request('POST', $this->tokenUrl, [
            'headers' => ['Content-Type' => 'application/x-www-form-urlencoded'],
            'body' => [
                'client_id' => $this->clientId,
                'client_secret' => $this->clientSecret,
                'grant_type' => 'password',
                'username' => $this->username,
                'password' => $this->password,
            ],
        ]);

        $data = $response->toArray();
        if (!isset($data['access_token'])) {
            throw new \Exception('No se pudo obtener el token de Keycloak.');
        }

        $tokenData = [
            'access_token' => $data['access_token'],
            'expires_at' => time() + $data['expires_in'] - 60,
        ];
        file_put_contents($this->tokenFile, json_encode($tokenData));

        return $data['access_token'];
    }
}
