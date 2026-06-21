<?php

namespace App\Repository;

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
}
