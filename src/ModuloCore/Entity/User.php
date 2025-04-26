<?php

namespace App\ModuloCore\Entity;

use App\ModuloCore\Repository\UserRepository;
use App\ModuloCore\Service\EncryptionService;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Validator\Constraints\UniqueEntity;
use Symfony\Component\Security\Core\User\PasswordAuthenticatedUserInterface;
use Symfony\Component\Security\Core\User\UserInterface;

#[ORM\Entity(repositoryClass: UserRepository::class)]
#[ORM\Table(name: '`user`')]
#[UniqueEntity(fields: ['email'], message: 'Ya existe una cuenta con este email')]
class User implements UserInterface, PasswordAuthenticatedUserInterface
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 180, unique: true)]
    private ?string $email = null;

    #[ORM\Column]
    private array $roles = [];

    /**
     * @var string The hashed password
     */
    #[ORM\Column]
    private ?string $password = null;

    #[ORM\Column(length: 255)]
    private ?string $nombre = null;

    #[ORM\Column(length: 255)]
    private ?string $apellidos = null;

    #[ORM\Column]
    private ?\DateTimeImmutable $created_at = null;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $last_login = null;

    #[ORM\Column]
    private ?bool $is_active = null;

    #[ORM\Column(length: 45, nullable: true)]
    private ?string $ip_address = null;

    /**
     * Servicio de cifrado inyectado por el EntityEncryptionSubscriber
     */
    private ?EncryptionService $encryptionService = null;

    /**
     * Guarda los valores originales (sin cifrar) para la sesión actual
     */
    private array $decryptedValues = [];

    public function __construct()
    {
        $this->created_at = new \DateTimeImmutable();
        $this->is_active = true;
        $this->roles = ['ROLE_USER'];
    }

    /**
     * Establece el servicio de cifrado
     */
    public function setEncryptionService(EncryptionService $service): void
    {
        $this->encryptionService = $service;
        // Desciframos valores al cargar la entidad si no están ya en caché
        $this->initializeDecryptedValues();
    }

    /**
     * Inicializa valores descifrados al cargar la entidad
     */
    private function initializeDecryptedValues(): void
    {
        if (!$this->encryptionService || !empty($this->decryptedValues)) {
            return;
        }

        if ($this->email && $this->isEncrypted($this->email)) {
            $this->decryptedValues['email'] = $this->encryptionService->decrypt($this->email);
        }

        if ($this->nombre && $this->isEncrypted($this->nombre)) {
            $this->decryptedValues['nombre'] = $this->encryptionService->decrypt($this->nombre);
        }

        if ($this->apellidos && $this->isEncrypted($this->apellidos)) {
            $this->decryptedValues['apellidos'] = $this->encryptionService->decrypt($this->apellidos);
        }
    }

    /**
     * Comprueba si un valor está cifrado
     */
    private function isEncrypted(string $value): bool
    {
        // Los datos cifrados con Sodium siempre serán cadenas base64 largas
        return (strlen($value) > 40) && 
            (base64_decode($value, true) !== false) && 
            (strpos($value, '=') !== false || strlen($value) % 4 === 0);
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getEmail(): ?string
    {
        if (isset($this->decryptedValues['email'])) {
            return $this->decryptedValues['email'];
        }

        if ($this->encryptionService && $this->email && $this->isEncrypted($this->email)) {
            $decrypted = $this->encryptionService->decrypt($this->email);
            $this->decryptedValues['email'] = $decrypted;
            return $decrypted;
        }

        return $this->email;
    }

    public function setEmail(string $email): static
    {
        $this->decryptedValues['email'] = $email;

        if ($this->encryptionService) {
            $this->email = $this->encryptionService->encrypt($email);
        } else {
            $this->email = $email;
        }

        return $this;
    }

    /**
     * @see UserInterface
     */
    public function getUserIdentifier(): string
    {
        return (string) $this->getEmail();
    }

    /**
     * @see UserInterface
     */
    public function getRoles(): array
    {
        $roles = $this->roles;
        $roles[] = 'ROLE_USER';

        return array_unique($roles);
    }

    public function setRoles(array $roles): static
    {
        $this->roles = $roles;

        return $this;
    }

    /**
     * @see PasswordAuthenticatedUserInterface
     */
    public function getPassword(): string
    {
        return $this->password;
    }

    public function setPassword(string $password): static
    {
        $this->password = $password;

        return $this;
    }

    /**
     * @see UserInterface
     */
    public function eraseCredentials(): void
    {
        // $this->plainPassword = null;
    }

    public function getNombre(): ?string
    {
        // Forzar descifrado si tenemos el servicio y parece cifrado
        if ($this->encryptionService && $this->nombre && 
            (strlen($this->nombre) > 40) && 
            (base64_decode($this->nombre, true) !== false)) {
            
            try {
                return $this->encryptionService->decrypt($this->nombre);
            } catch (\Exception $e) {
                // Si falla el descifrado, devolvemos el valor original
                return $this->nombre;
            }
        }
        
        return $this->nombre;
    }

    public function setNombre(string $nombre): static
    {
        $this->decryptedValues['nombre'] = $nombre;

        if ($this->encryptionService) {
            $this->nombre = $this->encryptionService->encrypt($nombre);
        } else {
            $this->nombre = $nombre;
        }

        return $this;
    }

    public function getApellidos(): ?string
    {
        if (isset($this->decryptedValues['apellidos'])) {
            return $this->decryptedValues['apellidos'];
        }

        if ($this->encryptionService && $this->apellidos && $this->isEncrypted($this->apellidos)) {
            $decrypted = $this->encryptionService->decrypt($this->apellidos);
            $this->decryptedValues['apellidos'] = $decrypted;
            return $decrypted;
        }

        return $this->apellidos;
    }

    public function setApellidos(string $apellidos): static
    {
        $this->decryptedValues['apellidos'] = $apellidos;

        if ($this->encryptionService) {
            $this->apellidos = $this->encryptionService->encrypt($apellidos);
        } else {
            $this->apellidos = $apellidos;
        }

        return $this;
    }

    public function getCreatedAt(): ?\DateTimeImmutable
    {
        return $this->created_at;
    }

    public function setCreatedAt(\DateTimeImmutable $created_at): static
    {
        $this->created_at = $created_at;

        return $this;
    }

    public function getLastLogin(): ?\DateTimeImmutable
    {
        return $this->last_login;
    }

    public function setLastLogin(?\DateTimeImmutable $last_login): static
    {
        $this->last_login = $last_login;

        return $this;
    }

    public function isIsActive(): ?bool
    {
        return $this->is_active;
    }

    public function setIsActive(bool $is_active): static
    {
        $this->is_active = $is_active;

        return $this;
    }

    public function getIpAddress(): ?string
    {
        return $this->ip_address;
    }

    public function setIpAddress(?string $ip_address): static
    {
        $this->ip_address = $ip_address;

        return $this;
    }
}