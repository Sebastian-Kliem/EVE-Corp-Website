<?php

namespace App\Repository;

use App\Entity\Market\MarketPrice;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<MarketPrice>
 */
class MarketPriceRepository extends ServiceEntityRepository
{
    // Rows per INSERT statement, keeps the statement well below max_allowed_packet
    private const INSERT_CHUNK_SIZE = 500;

    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, MarketPrice::class);
    }

    /**
     * Replaces all rows of a region (region and station scope) in one transaction, so readers never see a half written region.
     * Written with plain SQL because tens of thousands of rows per run are far too many for the ORM.
     *
     * @param iterable<array<string, mixed>> $rows Column => value, all rows with the same columns
     * @return int Number of written rows
     */
    public function replaceRegion(int $regionId, iterable $rows): int
    {
        $connection = $this->getEntityManager()->getConnection();

        return $connection->transactional(function () use ($connection, $regionId, $rows): int {
            $connection->executeStatement('DELETE FROM market_price WHERE region_id = ?', [$regionId]);

            $writtenRows = 0;
            $chunk = [];
            foreach ($rows as $row) {
                $chunk[] = $row;
                if (count($chunk) === self::INSERT_CHUNK_SIZE) {
                    $this->_insertChunk($connection, $chunk);
                    $writtenRows += count($chunk);
                    $chunk = [];
                }
            }
            if ($chunk !== []) {
                $this->_insertChunk($connection, $chunk);
                $writtenRows += count($chunk);
            }

            return $writtenRows;
        });
    }

    public function findLastFetchedAt(int $regionId): ?\DateTimeImmutable
    {
        $lastFetchedAt = $this->getEntityManager()->getConnection()->fetchOne(
            'SELECT MAX(fetched_at) FROM market_price WHERE region_id = ? AND scope = ?',
            [$regionId, MarketPrice::SCOPE_REGION]
        );

        return $lastFetchedAt ? new \DateTimeImmutable($lastFetchedAt) : null;
    }

    /**
     * Price columns of one side of a type at a hub station, null when the type has no orders there at all.
     *
     * @return array{price: ?string, best: ?string, orderCount: int}|null
     */
    public function findStationSide(int $stationId, int $typeId, bool $isBuy): ?array
    {
        $side = $isBuy ? 'buy' : 'sell';
        $row = $this->getEntityManager()->getConnection()->fetchAssociative(
            sprintf('SELECT %1$s_percentile AS price, %1$s_best AS best, %1$s_order_count AS order_count FROM market_price WHERE scope = ? AND location_id = ? AND type_id = ?', $side),
            [MarketPrice::SCOPE_STATION, $stationId, $typeId]
        );

        if ($row === false) {
            return null;
        }

        return ['price' => $row['price'], 'best' => $row['best'], 'orderCount' => (int)$row['order_count']];
    }

    /**
     * @param list<array<string, mixed>> $rows
     */
    private function _insertChunk(\Doctrine\DBAL\Connection $connection, array $rows): void
    {
        $columns = array_keys($rows[0]);
        $rowPlaceholder = '(' . implode(', ', array_fill(0, count($columns), '?')) . ')';

        $parameters = [];
        foreach ($rows as $row) {
            foreach ($columns as $column) {
                $parameters[] = $row[$column];
            }
        }

        $connection->executeStatement(
            sprintf('INSERT INTO market_price (%s) VALUES %s', implode(', ', $columns), implode(', ', array_fill(0, count($rows), $rowPlaceholder))),
            $parameters
        );
    }
}
