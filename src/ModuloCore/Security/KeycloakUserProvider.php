<?php
namespace App\ModuloCore\Security;

use Symfony\Component\Security\Core\Exception\UserNotFoundException;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Component\Security\Core\User\UserProviderInterface;

class KeycloakUserProvider implements UserProviderInterface
{
    public function loadUserByIdentifier(string $identifier): UserInterface
    {
        // Aquí puedes agregar lógica para buscar al usuario en tu sistema si es necesario.
        return new KeycloakUser($identifier);
    }

    /**
     * Método usado por Symfony para cargar al usuario desde el token JWT.
     */
    public function loadUserByToken(array $decodedToken): UserInterface
    {
        // Extraer identificador único (generalmente "sub") y roles del token
        $identifier = $decodedToken['sub'] ?? 'unknown';
        $roles = $decodedToken['realm_access']['roles'] ?? ['ROLE_USER'];

        return new KeycloakUser($identifier, $roles);
    }

    public function refreshUser(UserInterface $user): UserInterface
    {
        if (!$user instanceof KeycloakUser) {
            throw new \InvalidArgumentException('Unsupported user type');
        }

        return $user;
    }

    public function supportsClass(string $class): bool
    {
        return KeycloakUser::class === $class;
    }
}
