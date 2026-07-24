<?php

namespace App\Controller;

use App\Entity\McpApiKey;
use App\Repository\McpApiKeyRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;

#[Route('/admin/mcp/keys')]
class McpApiKeyController extends AbstractController
{
    private EntityManagerInterface $em;
    private McpApiKeyRepository $repository;

    public function __construct(EntityManagerInterface $em, McpApiKeyRepository $repository)
    {
        $this->em = $em;
        $this->repository = $repository;
    }

    #[Route('', name: 'mcp_keys_page', methods: ['GET'])]
    public function page(): Response
    {
        return $this->render('mcp/keys.html.twig', ['usemenu' => true]);
    }

    #[Route('/list', name: 'mcp_keys_list', methods: ['GET'])]
    public function list(): Response
    {
        $keys = $this->repository->findBy([], ['createdAt' => 'DESC']);

        $data = [];
        foreach ($keys as $key) {
            $data[] = [
                'id' => $key->getId(),
                'name' => $key->getName(),
                'tokenPrefix' => substr($key->getToken(), 0, 8) . '...' ,
                'permissions' => $key->getPermissions(),
                'isActive' => $key->isActive(),
                'createdAt' => $key->getCreatedAt()?->format('Y-m-d H:i:s'),
                'lastUsedAt' => $key->getLastUsedAt()?->format('Y-m-d H:i:s'),
            ];
        }

        return $this->json(['keys' => $data]);
    }

    #[Route('/create', name: 'mcp_keys_create', methods: ['POST'])]
    public function create(Request $request): Response
    {
        $data = json_decode($request->getContent(), true);

        if (empty($data['name'])) {
            return $this->json(['error' => 'name is required'], 400);
        }

        $plainToken = McpApiKey::generateToken();
        $hashedToken = hash('sha256', $plainToken);

        $key = new McpApiKey();
        $key->setName($data['name']);
        $key->setToken($hashedToken);
        $key->setPermissions($data['permissions'] ?? ['*']);

        $this->em->persist($key);
        $this->em->flush();

        return $this->json([
            'id' => $key->getId(),
            'name' => $key->getName(),
            'token' => $plainToken,
            'permissions' => $key->getPermissions(),
            'message' => 'Save this token - it will not be shown again',
        ], 201);
    }

    #[Route('/{id}', name: 'mcp_keys_get', methods: ['GET'])]
    public function get(int $id): Response
    {
        $key = $this->repository->find($id);

        if (!$key) {
            return $this->json(['error' => 'Not found'], 404);
        }

        return $this->json([
            'id' => $key->getId(),
            'name' => $key->getName(),
            'permissions' => $key->getPermissions(),
            'isActive' => $key->isActive(),
            'createdAt' => $key->getCreatedAt()?->format('Y-m-d H:i:s'),
            'lastUsedAt' => $key->getLastUsedAt()?->format('Y-m-d H:i:s'),
        ]);
    }

    #[Route('/{id}', name: 'mcp_keys_update', methods: ['PUT', 'PATCH'])]
    public function update(int $id, Request $request): Response
    {
        $key = $this->repository->find($id);

        if (!$key) {
            return $this->json(['error' => 'Not found'], 404);
        }

        $data = json_decode($request->getContent(), true);

        if (isset($data['name'])) {
            $key->setName($data['name']);
        }
        if (isset($data['permissions'])) {
            $key->setPermissions($data['permissions']);
        }
        if (isset($data['isActive'])) {
            $key->setIsActive($data['isActive']);
        }

        $this->em->flush();

        return $this->json(['message' => 'Updated successfully']);
    }

    #[Route('/{id}', name: 'mcp_keys_delete', methods: ['DELETE'])]
    public function delete(int $id): Response
    {
        $key = $this->repository->find($id);

        if (!$key) {
            return $this->json(['error' => 'Not found'], 404);
        }

        $this->em->remove($key);
        $this->em->flush();

        return $this->json(['message' => 'Deleted successfully']);
    }

    #[Route('/{id}/regenerate', name: 'mcp_keys_regenerate', methods: ['POST'])]
    public function regenerate(int $id): Response
    {
        $key = $this->repository->find($id);

        if (!$key) {
            return $this->json(['error' => 'Not found'], 404);
        }

        $plainToken = McpApiKey::generateToken();
        $hashedToken = hash('sha256', $plainToken);

        $key->setToken($hashedToken);
        $this->em->flush();

        return $this->json([
            'token' => $plainToken,
            'message' => 'Save this new token - it will not be shown again',
        ]);
    }
}
