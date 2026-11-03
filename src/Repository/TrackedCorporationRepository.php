<?php

namespace App\Repository;

use App\Entity\TrackedCorporation;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<TrackedCorporation>
 */
class TrackedCorporationRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, TrackedCorporation::class);
    }

    /**
     * @return string[]
     */
    public function findActiveIds(): array
    {
        $activeIds = [];
        foreach ($this->findBy(['status' => TrackedCorporation::STATUS_ACTIVE]) as $corporation) {
            $activeIds[] = $corporation->getId();
        }

        return $activeIds;
    }
}
