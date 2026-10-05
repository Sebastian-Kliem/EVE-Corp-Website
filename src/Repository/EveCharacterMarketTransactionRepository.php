<?php

namespace App\Repository;

use App\Entity\EveCharacter;
use App\Entity\EveCharacterMarketTransaction;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<EveCharacterMarketTransaction>
 *
 * @method EveCharacterMarketTransaction|null find($id, $lockMode = null, $lockVersion = null)
 * @method EveCharacterMarketTransaction|null findOneBy(array $criteria, array $orderBy = null)
 * @method EveCharacterMarketTransaction[]    findAll()
 * @method EveCharacterMarketTransaction[]    findBy(array $criteria, array $orderBy = null, $limit = null, $offset = null)
 */
class EveCharacterMarketTransactionRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, EveCharacterMarketTransaction::class);
    }

    /**
     * Returns which of the given transaction IDs are already stored, in one query.
     *
     * @param string[] $transactionIds
     * @return array<string, true> keyed by transactionId
     */
    public function findExistingTransactionIds(EveCharacter $character, array $transactionIds): array
    {
        if (empty($transactionIds)) {
            return [];
        }

        $rows = $this->createQueryBuilder('e')
            ->select('e.transactionId')
            ->where('e.character = :character')
            ->andWhere('e.transactionId IN (:ids)')
            ->setParameter('character', $character)
            ->setParameter('ids', $transactionIds)
            ->getQuery()
            ->getScalarResult();

        $existing = [];
        foreach ($rows as $row) {
            $existing[(string)$row['transactionId']] = true;
        }

        return $existing;
    }
}
