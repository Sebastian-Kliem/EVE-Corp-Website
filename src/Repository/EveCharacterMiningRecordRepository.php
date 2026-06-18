<?php

namespace App\Repository;

use App\Entity\EveCharacterMiningRecord;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<EveCharacterMiningRecord>
 */
class EveCharacterMiningRecordRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, EveCharacterMiningRecord::class);
    }

    /**
     * Clears all mining records for a given character ID.
     */
    public function clearMiningRecordsForCharacter(int $characterId): void
    {
        $this->createQueryBuilder('r')
            ->delete()
            ->where('r.character = :charId')
            ->setParameter('charId', $characterId)
            ->getQuery()
            ->execute();
    }
}
