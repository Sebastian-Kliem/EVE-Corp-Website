<?php

namespace App\Repository;

use App\Entity\Fitting\Fitting;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Fitting>
 */
class FittingRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Fitting::class);
    }

    public function findOneByContentHash(string $contentHash): ?Fitting
    {
        return $this->findOneBy(['contentHash' => $contentHash]);
    }

    /**
     * All fittings with creator and in-game copies (incl. characters) loaded in one query.
     *
     * @return Fitting[]
     */
    public function findAllWithCopies(): array
    {
        return $this->createQueryBuilder('f')
            ->leftJoin('f.createdBy', 'u')
            ->addSelect('u')
            ->leftJoin('f.ingameCopies', 'copy')
            ->addSelect('copy')
            ->leftJoin('copy.character', 'c')
            ->addSelect('c')
            ->orderBy('f.name', \SortDirection::Ascending)
            ->getQuery()
            ->getResult();
    }
}
