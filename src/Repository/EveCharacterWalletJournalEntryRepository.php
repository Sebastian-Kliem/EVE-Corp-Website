<?php

namespace App\Repository;

use App\Entity\EveCharacter;
use App\Entity\EveCharacterWalletJournalEntry;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<EveCharacterWalletJournalEntry>
 */
class EveCharacterWalletJournalEntryRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, EveCharacterWalletJournalEntry::class);
    }

    /**
     * Returns which of the given journal reference IDs are already stored, in one query.
     *
     * @param string[] $refIds
     * @return array<string, true> keyed by refId
     */
    public function findExistingRefIds(EveCharacter $character, array $refIds): array
    {
        if (empty($refIds)) {
            return [];
        }

        $rows = $this->createQueryBuilder('e')
            ->select('e.refId')
            ->where('e.character = :character')
            ->andWhere('e.refId IN (:ids)')
            ->setParameter('character', $character)
            ->setParameter('ids', $refIds)
            ->getQuery()
            ->getScalarResult();

        $existing = [];
        foreach ($rows as $row) {
            $existing[(string)$row['refId']] = true;
        }

        return $existing;
    }
}
