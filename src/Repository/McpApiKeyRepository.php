<?php

namespace App\Repository;

use App\Entity\McpApiKey;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<McpApiKey>
 */
class McpApiKeyRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, McpApiKey::class);
    }

    public function findByToken(string $token): ?McpApiKey
    {
        return $this->createQueryBuilder('m')
            ->andWhere('m.token = :token')
            ->andWhere('m.isActive = :active')
            ->setParameter('token', hash('sha256', $token))
            ->setParameter('active', true)
            ->getQuery()
            ->getOneOrNullResult();
    }

    public function save(McpApiKey $entity, bool $flush = false): void
    {
        $this->getEntityManager()->persist($entity);

        if ($flush) {
            $this->getEntityManager()->flush();
        }
    }

    public function remove(McpApiKey $entity, bool $flush = false): void
    {
        $this->getEntityManager()->remove($entity);

        if ($flush) {
            $this->getEntityManager()->flush();
        }
    }
}
