<?php
namespace App\ModuloCore\Service;

use Symfony\Contracts\HttpClient\HttpClientInterface;

class KeycloakRealmService
{
    private string $apiUrl;

    public function __construct(private HttpClientInterface $httpClient)
    {
        $this->apiUrl = $_ENV['API_URL'] ?? '';
    }

    public function instalarRealm(string $nombre): array
    {
        try {
            // Crear el realm
            $realmResponse = $this->httpClient->request('POST', $this->apiUrl . '/crear-reino', [
                'json' => ['nombre' => $nombre]
            ]);
            $realmData = $realmResponse->toArray();

            if (!isset($realmData['public_key'])) {
                return ['success' => false, 'error' => 'No se recibió clave pública'];
            }

            // Crear el usuario
            $this->httpClient->request('POST', $this->apiUrl . '/crear-usuario', [
                'json' => ['realm' => $nombre]
            ]);

            // Guardar clave pública en archivo
            $claveFormateada = "-----BEGIN PUBLIC KEY-----\n" .
                wordwrap($realmData['public_key'], 64, "\n", true) .
                "\n-----END PUBLIC KEY-----";

            $keyPath = __DIR__ . '/../Security/Data/key.txt';
            file_put_contents($keyPath, $claveFormateada);

            // Escribir en .env las variables necesarias
            $envPath = dirname(__DIR__, 3).'/.env';
            $envContent = file_get_contents($envPath);

            $envContent .= "\nKEYCLOAK_URL=" . rtrim($this->apiUrl, '/');
            $envContent .= "\nKEYCLOAK_REALM=" . $nombre;

            file_put_contents($envPath, $envContent);

            return ['success' => true, 'realm' => $nombre];
        } catch (\Throwable $e) {
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }
}