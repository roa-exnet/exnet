<?php

namespace App\ModuloCore\Service;

use App\ModuloCore\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\RequestStack;

class IpAuthService
{
    private EntityManagerInterface $entityManager;
    private RequestStack $requestStack;

    public function __construct(
        EntityManagerInterface $entityManager,
        RequestStack $requestStack
    ) {
        $this->entityManager = $entityManager;
        $this->requestStack = $requestStack;
    }

    public function isIpRegistered(): bool
    {
        $request = $this->requestStack->getCurrentRequest();
        if (!$request) {
            return false;
        }

        $ipAddress = $request->getClientIp();
        if (!$ipAddress) {
            return false;
        }

        $userRepository = $this->entityManager->getRepository(User::class);
        $user = $userRepository->findOneBy(['ip_address' => $ipAddress]);

        return $user !== null;
    }

    public function getCurrentUser(): ?User
    {
        $request = $this->requestStack->getCurrentRequest();
        if (!$request) {
            return null;
        }

        $ipAddress = $request->getClientIp();
        if (!$ipAddress) {
            return null;
        }

        $userRepository = $this->entityManager->getRepository(User::class);
        $user = $userRepository->findOneBy(['ip_address' => $ipAddress]);

        if ($user) {
            return $user;
        }

        return null;
    }

    public function registerUserIp(User $user): void
    {
        $request = $this->requestStack->getCurrentRequest();
        if (!$request) {
            return;
        }

        $ipAddress = $request->getClientIp();
        if (!$ipAddress) {
            return;
        }

        $userRepository = $this->entityManager->getRepository(User::class);
        $existingUser = $userRepository->findOneBy(['ip_address' => $ipAddress]);
        
        if ($existingUser && $existingUser->getId() !== $user->getId()) {
            $existingUser->setIpAddress(null);
            $this->entityManager->persist($existingUser);
        }

        $user->setIpAddress($ipAddress);
        $this->entityManager->persist($user);
        $this->entityManager->flush();
    }

    public function getCurrentIp(): ?string
    {
        $request = $this->requestStack->getCurrentRequest();
        if (!$request) {
            return null;
        }

        return $request->getClientIp();
    }

    public function generateUserToken(User $user): string
    {
        $uniqueData = $user->getId() . '-' . $user->getIpAddress() . '-' . $user->getEmail();
        return hash('sha256', $uniqueData . $_SERVER['APP_SECRET']);
    }

    public function validateUserToken(User $user, string $token): bool
    {
        $expectedToken = $this->generateUserToken($user);
        return hash_equals($expectedToken, $token);
    }
}