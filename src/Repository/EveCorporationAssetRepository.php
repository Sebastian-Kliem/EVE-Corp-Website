<?php

namespace App\Repository;

use App\Entity\EveCorporationAsset;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<EveCorporationAsset>
 */
class EveCorporationAssetRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, EveCorporationAsset::class);
    }

    /**
     * Clears all assets for a given corporation ID.
     */
    public function clearAssetsForCorporation(int $corporationId): void
    {
        $this->createQueryBuilder('a')
            ->delete()
            ->where('a.corporationId = :corpId')
            ->setParameter('corpId', $corporationId)
            ->getQuery()
            ->execute();
    }
}
