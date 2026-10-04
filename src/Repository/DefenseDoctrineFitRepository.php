<?php

namespace App\Repository;

use App\Entity\DefenseDoctrineFit;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<DefenseDoctrineFit>
 */
class DefenseDoctrineFitRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, DefenseDoctrineFit::class);
    }

    /**
     * Returns all defense doctrine fits ordered by sort order then ship name/title.
     *
     * @return DefenseDoctrineFit[]
     */
    public function findAllOrdered(): array
    {
        return $this->createQueryBuilder('d')
            ->leftJoin('d.createdBy', 'u')
            ->addSelect('u')
            ->orderBy('d.sortOrder', \SortDirection::Ascending)
            ->addOrderBy('d.role', \SortDirection::Ascending)
            ->addOrderBy('d.shipName', \SortDirection::Ascending)
            ->addOrderBy('d.title', \SortDirection::Ascending)
            ->getQuery()
            ->getResult();
    }
}
