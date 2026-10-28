<?php

namespace App\Repository;

use App\Entity\Wormhole\WormholeSite;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<WormholeSite>
 */
class WormholeSiteRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, WormholeSite::class);
    }

    /**
     * @return list<WormholeSite>
     */
    public function findAllOrdered(): array
    {
        return $this->findBy([], ['sortOrder' => 'ASC']);
    }
}
