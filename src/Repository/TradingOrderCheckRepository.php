<?php

namespace App\Repository;

use App\Entity\Market\TradingOrderCheck;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<TradingOrderCheck>
 */
class TradingOrderCheckRepository extends ServiceEntityRepository
{
    private const COLUMNS = ['order_id', 'character_id', 'location_id', 'type_id', 'is_buy', 'in_book', 'current_price', 'current_volume_remain', 'best_competitor_price', 'competitors_ahead', 'competitor_volume_ahead', 'checked_at'];

    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, TradingOrderCheck::class);
    }

    /**
     * Replaces all checks of one station with the result of the latest order book.
     *
     * @param list<array<string, mixed>> $rows Rows with the columns of self::COLUMNS
     */
    public function replaceStation(int $locationId, array $rows): void
    {
        $connection = $this->getEntityManager()->getConnection();
        $connection->transactional(function () use ($connection, $locationId, $rows): void {
            $connection->executeStatement('DELETE FROM trading_order_check WHERE location_id = ?', [$locationId]);
            if ($rows === []) {
                return;
            }

            $rowPlaceholder = '(' . implode(', ', array_fill(0, count(self::COLUMNS), '?')) . ')';
            $parameters = [];
            foreach ($rows as $row) {
                foreach (self::COLUMNS as $column) {
                    $parameters[] = $row[$column];
                }
            }
            // An order checked at two stations cannot exist, the upsert only guards against duplicate order IDs
            $connection->executeStatement(
                sprintf('INSERT INTO trading_order_check (%s) VALUES %s ON DUPLICATE KEY UPDATE checked_at = VALUES(checked_at)', implode(', ', self::COLUMNS), implode(', ', array_fill(0, count($rows), $rowPlaceholder))),
                $parameters
            );
        });
    }

    /**
     * @param int[] $orderIds
     * @return array<int, TradingOrderCheck> Order ID => check
     */
    public function findByOrderIds(array $orderIds): array
    {
        if ($orderIds === []) {
            return [];
        }

        $checks = [];
        foreach ($this->findBy(['orderId' => $orderIds]) as $check) {
            $checks[(int)$check->getOrderId()] = $check;
        }

        return $checks;
    }
}
