<?php

namespace App\ModuloCore\Service;

use Symfony\Contracts\HttpClient\HttpClientInterface;
use Doctrine\DBAL\Connection;
use Symfony\Component\DependencyInjection\ParameterBag\ParameterBagInterface;

class KeycloakModuleService
{
    private HttpClientInterface $httpClient;
    private Connection $conn;
    private ParameterBagInterface $params;
    private string $apiUrl;

    public function __construct(HttpClientInterface $httpClient, Connection $conn, ParameterBagInterface $params)
    {
        $this->httpClient = $httpClient;
        $this->conn = $conn;
        $this->params = $params;
        $this->apiUrl = $params->get('API_URL');
    }

    public function registrarLicencia(string $modulo): array
    {
        $realm = $this->params->get('KEYCLOAK_REALM');

        $existe = $this->conn->fetchOne('SELECT COUNT(*) FROM licencia WHERE nombre = :nombre', [
            'nombre' => $modulo
        ]);

        if ($existe > 0) {
            return ['success' => true, 'message' => 'Licencia ya registrada'];
        }

        $response = $this->httpClient->request('POST', $this->apiUrl . '/crear-cliente', [
            'json' => [
                'realm' => $realm,
                'client_id' => $modulo,
                'lifespan' => 31536000
            ]
        ]);

        $data = $response->toArray(false);
        if (!isset($data['access_token'])) {
            return ['success' => false, 'error' => 'No se recibió token'];
        }

        $this->conn->insert('licencia', [
            'nombre' => $modulo,
            'token' => $data['access_token']
        ]);

        return ['success' => true, 'token' => $data['access_token']];
    }
}
