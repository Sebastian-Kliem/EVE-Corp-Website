<?php

namespace App\Repository;

use App\Entity\EveCharacterAsset;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<EveCharacterAsset>
 */
class EveCharacterAssetRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, EveCharacterAsset::class);
    }

    /**
     * Clears all assets for a given character ID.
     */
    public function clearAssetsForCharacter(int $characterId): void
    {
        $this->createQueryBuilder('a')
            ->delete()
            ->where('a.character = :charId')
            ->setParameter('charId', $characterId)
            ->getQuery()
            ->execute();
    }
}
