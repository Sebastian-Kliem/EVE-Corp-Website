<?php

namespace App\Repository;

use App\Entity\Fitting\Fitting;
use App\Entity\Fitting\FleetComposition;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<FleetComposition>
 */
class FleetCompositionRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, FleetComposition::class);
    }

    /**
     * @return FleetComposition[]
     */
    public function findAllWithEntries(): array
    {
        return $this->createQueryBuilder('fleet')
            ->leftJoin('fleet.createdBy', 'u')
            ->addSelect('u')
            ->leftJoin('fleet.entries', 'entry')
            ->addSelect('entry')
            ->leftJoin('entry.fitting', 'f')
            ->addSelect('f')
            ->orderBy('fleet.name', \SortDirection::Ascending)
            ->getQuery()
            ->getResult();
    }

    /**
     * Names of the fleets using the given fitting.
     *
     * @return list<string>
     */
    public function findFleetNamesUsingFitting(Fitting $fitting): array
    {
        $rows = $this->createQueryBuilder('fleet')
            ->select('DISTINCT fleet.name')
            ->join('fleet.entries', 'entry')
            ->where('entry.fitting = :fitting')
            ->setParameter('fitting', $fitting)
            ->getQuery()
            ->getScalarResult();

        $names = [];
        foreach ($rows as $row) {
            $names[] = (string) $row['name'];
        }

        return $names;
    }
}
