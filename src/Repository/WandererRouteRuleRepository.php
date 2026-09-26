<?php

namespace App\Repository;

use App\Entity\User;
use App\Entity\WandererRouteRule;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<WandererRouteRule>
 */
class WandererRouteRuleRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, WandererRouteRule::class);
    }

    /**
     * Finds all active corporate rules (user is null).
     *
     * @return WandererRouteRule[]
     */
    public function findActiveCorpRules(): array
    {
        return $this->createQueryBuilder('r')
            ->where('r.user IS NULL')
            ->andWhere('r.isActive = :active')
            ->setParameter('active', true)
            ->orderBy('r.name', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Finds all corporate rules.
     *
     * @return WandererRouteRule[]
     */
    public function findAllCorpRules(): array
    {
        return $this->createQueryBuilder('r')
            ->where('r.user IS NULL')
            ->orderBy('r.name', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Finds all active user rules for users who have a personal Discord webhook configured.
     *
     * @return WandererRouteRule[]
     */
    public function findActiveUserRules(): array
    {
        return $this->createQueryBuilder('r')
            ->join('r.user', 'u')
            ->where('r.user IS NOT NULL')
            ->andWhere('r.isActive = :active')
            ->setParameter('active', true)
            ->orderBy('r.name', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Finds all rules for a specific user.
     *
     * @return WandererRouteRule[]
     */
    public function findByUser(User $user): array
    {
        return $this->createQueryBuilder('r')
            ->where('r.user = :user')
            ->setParameter('user', $user)
            ->orderBy('r.createdAt', 'DESC')
            ->getQuery()
            ->getResult();
    }
}
