<?php

namespace App\Repository;

use App\Entity\Wormhole\WormholeSiteAlias;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<WormholeSiteAlias>
 */
class WormholeSiteAliasRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, WormholeSiteAlias::class);
    }

    public function findOneByAlias(string $alias): ?WormholeSiteAlias
    {
        return $this->findOneBy(['alias' => $alias]);
    }
}
