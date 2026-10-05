<?php

namespace App\Repository;

use App\Entity\EveCharacterValueSnapshot;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<EveCharacterValueSnapshot>
 */
class EveCharacterValueSnapshotRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, EveCharacterValueSnapshot::class);
    }
}
