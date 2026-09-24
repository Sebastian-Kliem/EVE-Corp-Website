<?php

namespace App\Repository;

use App\Entity\Orders\CorpOrder;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<CorpOrder>
 */
class CorpOrderRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, CorpOrder::class);
    }

    /**
     * Finds active orders for a given type (BUY or SELL).
     *
     * @param string $type
     * @return CorpOrder[]
     */
    public function findActiveOrders(string $type): array
    {
        return $this->createQueryBuilder('o')
            ->leftJoin('o.items', 'i')
            ->addSelect('i')
            ->leftJoin('o.user', 'u')
            ->addSelect('u')
            ->leftJoin('i.fulfiller', 'f')
            ->addSelect('f')
            ->where('o.type = :type')
            ->andWhere('o.status IN (:activeStatuses)')
            ->setParameter('type', strtoupper($type))
            ->setParameter('activeStatuses', [CorpOrder::STATUS_OPEN, CorpOrder::STATUS_IN_PROGRESS])
            ->orderBy('o.createdAt', 'DESC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Finds archived (fulfilled or cancelled) orders for a given type.
     *
     * @param string $type
     * @param int $limit
     * @return CorpOrder[]
     */
    public function findArchivedOrders(string $type, int $limit = 50): array
    {
        return $this->createQueryBuilder('o')
            ->leftJoin('o.items', 'i')
            ->addSelect('i')
            ->leftJoin('o.user', 'u')
            ->addSelect('u')
            ->leftJoin('i.fulfiller', 'f')
            ->addSelect('f')
            ->where('o.type = :type')
            ->andWhere('o.status IN (:archivedStatuses)')
            ->setParameter('type', strtoupper($type))
            ->setParameter('archivedStatuses', [CorpOrder::STATUS_FULFILLED, CorpOrder::STATUS_CANCELLED])
            ->orderBy('o.updatedAt', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }
}
