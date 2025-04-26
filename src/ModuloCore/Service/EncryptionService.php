<?php

namespace App\ModuloCore\Service;

class EncryptionService
{
    private string $encryptionKey;
    private $logger;

    
    public function __construct(string $appSecret, LoggerInterface $logger = null)
    {
        $this->encryptionKey = hash('sha256', $appSecret, true);
        $this->logger = $logger;
    }
    
    /**
     * Cifra datos usando sodium crypto_secretbox
     */
    public function encrypt(string $data): string
    {
        if (empty($data)) {
            return $data;
        }
        
        if ($this->logger) {
            $this->logger->debug('Cifrando datos: ' . substr($data, 0, 3) . '...');
        }
        
        $nonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        $cipher = sodium_crypto_secretbox($data, $nonce, $this->encryptionKey);
        $encrypted = base64_encode($nonce . $cipher);
        
        if ($this->logger) {
            $this->logger->debug('Datos cifrados: ' . substr($encrypted, 0, 10) . '...');
        }
        
        return $encrypted;
    }
    
    /**
     * Descifra datos cifrados con encrypt()
     */
    public function decrypt(string $encrypted): string
    {
        if (empty($encrypted)) {
            return $encrypted;
        }
        
        try {
            $decoded = base64_decode($encrypted);
            if ($decoded === false) {
                throw new \Exception('El texto no está codificado en base64');
            }
            
            $nonce = mb_substr($decoded, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES, '8bit');
            $cipher = mb_substr($decoded, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES, null, '8bit');
            
            $data = sodium_crypto_secretbox_open($cipher, $nonce, $this->encryptionKey);
            if ($data === false) {
                throw new \Exception('La desencriptación falló');
            }
            
            return $data;
        } catch (\Exception $e) {
            // En caso de error devolvemos el valor original
            // Esto es útil durante la migración de datos existentes
            return $encrypted;
        }
    }
}