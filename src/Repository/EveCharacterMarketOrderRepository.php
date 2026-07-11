<?php

namespace App\Repository;

use App\Entity\EveCharacterMarketOrder;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<EveCharacterMarketOrder>
 *
 * @method EveCharacterMarketOrder|null find($id, $lockMode = null, $lockVersion = null)
 * @method EveCharacterMarketOrder|null findOneBy(array $criteria, array $orderBy = null)
 * @method EveCharacterMarketOrder[]    findAll()
 * @method EveCharacterMarketOrder[]    findBy(array $criteria, array $orderBy = null, $limit = null, $offset = null)
 */
class EveCharacterMarketOrderRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, EveCharacterMarketOrder::class);
    }
}
