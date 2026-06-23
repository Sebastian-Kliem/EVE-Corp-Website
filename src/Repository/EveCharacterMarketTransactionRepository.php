<?php

namespace App\Repository;

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
}
