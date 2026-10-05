<?php

namespace App\Repository;

use App\Entity\EveCharacter;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<EveCharacter>
 */
class EveCharacterRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, EveCharacter::class);
    }

    /**
     * Characters the cron tasks should sync: valid tokens plus revoked ones whose hourly retry is due.
     *
     * @return EveCharacter[]
     */
    public function findSyncableCharacters(?\DateTimeImmutable $now = null): array
    {
        return $this->createQueryBuilder('c')
            ->where('c.refreshToken IS NOT NULL')
            ->andWhere('c.tokenValid = true OR c.tokenRetryAt IS NULL OR c.tokenRetryAt <= :now')
            ->setParameter('now', $now ?? new \DateTimeImmutable())
            ->getQuery()
            ->getResult();
    }

    /**
     * Finds all characters currently marked as online.
     *
     * @return EveCharacter[]
     */
    public function findOnlineCharacters(?\DateTimeImmutable $since = null): array
    {
        $qb = $this->createQueryBuilder('c')
            ->innerJoin('c.user', 'u')
            ->addSelect('u')
            ->where('c.isOnline = true');

        if ($since !== null) {
            $qb->andWhere('c.lastOnlineCheck >= :since')
               ->setParameter('since', $since);
        }

        return $qb->orderBy('u.username', \SortDirection::Ascending)
            ->addOrderBy('c.name', \SortDirection::Ascending)
            ->getQuery()
            ->getResult();
    }
}
