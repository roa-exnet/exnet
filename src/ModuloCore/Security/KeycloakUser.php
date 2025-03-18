<?php
namespace App\ModuloCore\Security;

use Symfony\Component\Security\Core\User\UserInterface;

class KeycloakUser implements UserInterface
{
    private string $identifier;
    private array $roles;

    public function __construct(string $identifier, array $roles = [])
    {
        $this->identifier = $identifier;
        $this->roles = $roles;
    }

    /**
     * Devuelve los roles del usuario.
     */
    public function getRoles(): array
    {
        return $this->roles;
    }

    /**
     * No se requiere contraseña ya que la autenticación es gestionada por Keycloak.
     */
    public function getPassword(): ?string
    {
        return null;
    }

    /**
     * No se requiere salt para la autenticación.
     */
    public function getSalt(): ?string
    {
        return null;
    }

    /**
     * Devuelve el nombre de usuario.
     */
    public function getUsername(): string
    {
        return $this->identifier;
    }

    /**
     * Borra credenciales sensibles (no aplicable aquí).
     */
    public function eraseCredentials(): void
    {
        // No se utilizan credenciales sensibles
    }

    /**
     * Devuelve el identificador único del usuario.
     */
    public function getUserIdentifier(): string
    {
        return $this->identifier;
    }
}
