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

        return $qb->orderBy('u.username', 'ASC')
            ->addOrderBy('c.name', 'ASC')
            ->getQuery()
            ->getResult();
    }
}
