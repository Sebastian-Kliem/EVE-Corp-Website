<?php

namespace App\Repository;

use App\Entity\EveCharacter;
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

    /**
     * Type and quantity of a character's stored assets as plain rows (no managed entities).
     *
     * @return array<int, array{typeId: int, quantity: int}>
     */
    public function findTypeQuantitiesForCharacter(EveCharacter $character): array
    {
        $rows = $this->createQueryBuilder('a')
            ->select('a.typeId', 'a.quantity')
            ->where('a.character = :character')
            ->setParameter('character', $character)
            ->getQuery()
            ->getScalarResult();

        $typeQuantities = [];
        foreach ($rows as $row) {
            $typeQuantities[] = ['typeId' => (int)$row['typeId'], 'quantity' => (int)$row['quantity']];
        }

        return $typeQuantities;
    }
}
