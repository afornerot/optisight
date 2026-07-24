<?php

namespace App\Mcp\Security;

use App\Repository\McpApiKeyRepository;
use Doctrine\ORM\EntityManagerInterface;

class McpAuthenticator
{
    private McpApiKeyRepository $repository;
    private EntityManagerInterface $em;

    public function __construct(
        McpApiKeyRepository $repository,
        EntityManagerInterface $em
    ) {
        $this->repository = $repository;
        $this->em = $em;
    }

    public function authenticate(?string $token): ?\App\Entity\McpApiKey
    {
        if (!$token) {
            return null;
        }

        $apiKey = $this->repository->findByToken($token);

        if (!$apiKey) {
            return null;
        }

        $apiKey->updateLastUsed();
        $this->em->flush();

        return $apiKey;
    }

    public function validatePermission(?string $token, string $permission): bool
    {
        $apiKey = $this->authenticate($token);

        if (!$apiKey) {
            return false;
        }

        return $apiKey->hasPermission($permission);
    }
}
