<?php

namespace App\Repository;

use App\Entity\EveCharacter;
use App\Entity\Fitting\FittingIngameCopy;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<FittingIngameCopy>
 */
class FittingIngameCopyRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, FittingIngameCopy::class);
    }

    /**
     * @return array<int, FittingIngameCopy> Map of ESI fitting ID => copy
     */
    public function findByCharacterIndexedByEsiId(EveCharacter $character): array
    {
        $copies = $this->createQueryBuilder('copy')
            ->join('copy.fitting', 'f')
            ->addSelect('f')
            ->where('copy.character = :character')
            ->setParameter('character', $character)
            ->getQuery()
            ->getResult();

        $copiesByEsiId = [];
        foreach ($copies as $copy) {
            $copiesByEsiId[$copy->getEsiFittingId()] = $copy;
        }

        return $copiesByEsiId;
    }
}
