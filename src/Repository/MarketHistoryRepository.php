<?php

namespace App\Repository;

use App\Entity\Market\MarketHistory;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<MarketHistory>
 */
class MarketHistoryRepository extends ServiceEntityRepository
{
    // Rows per INSERT statement, keeps the statement well below max_allowed_packet
    private const INSERT_CHUNK_SIZE = 1000;

    private const COLUMNS = ['region_id', 'type_id', 'date', 'average', 'highest', 'lowest', 'volume', 'order_count'];

    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, MarketHistory::class);
    }

    /**
     * Inserts or overwrites history rows; days are imported again while EVE Ref still completes them.
     *
     * @param iterable<array<string, mixed>> $rows Column => value with the columns of self::COLUMNS
     * @return int Number of written rows
     */
    public function upsertRows(iterable $rows): int
    {
        $writtenRows = 0;
        $chunk = [];
        foreach ($rows as $row) {
            $chunk[] = $row;
            if (count($chunk) === self::INSERT_CHUNK_SIZE) {
                $this->_upsertChunk($chunk);
                $writtenRows += count($chunk);
                $chunk = [];
            }
        }
        if ($chunk !== []) {
            $this->_upsertChunk($chunk);
            $writtenRows += count($chunk);
        }

        return $writtenRows;
    }

    /**
     * Removes days beyond the retention: hub regions keep longer than all other regions.
     *
     * @param int[] $hubRegionIds
     * @return int Number of deleted rows
     */
    public function deleteExpired(array $hubRegionIds, \DateTimeImmutable $hubCutoff, \DateTimeImmutable $otherCutoff): int
    {
        $connection = $this->getEntityManager()->getConnection();

        $deletedRows = $connection->executeStatement(
            'DELETE FROM market_history WHERE date < ? AND region_id NOT IN (?)',
            [$otherCutoff->format('Y-m-d'), $hubRegionIds],
            [\Doctrine\DBAL\ParameterType::STRING, ArrayParameterType::INTEGER]
        );
        $deletedRows += $connection->executeStatement(
            'DELETE FROM market_history WHERE date < ?',
            [$hubCutoff->format('Y-m-d')]
        );

        return (int)$deletedRows;
    }

    /**
     * @param list<array<string, mixed>> $rows
     */
    private function _upsertChunk(array $rows): void
    {
        $rowPlaceholder = '(' . implode(', ', array_fill(0, count(self::COLUMNS), '?')) . ')';

        $parameters = [];
        foreach ($rows as $row) {
            foreach (self::COLUMNS as $column) {
                $parameters[] = $row[$column];
            }
        }

        $this->getEntityManager()->getConnection()->executeStatement(
            sprintf(
                'INSERT INTO market_history (%s) VALUES %s ON DUPLICATE KEY UPDATE average = VALUES(average), highest = VALUES(highest), lowest = VALUES(lowest), volume = VALUES(volume), order_count = VALUES(order_count)',
                implode(', ', self::COLUMNS),
                implode(', ', array_fill(0, count($rows), $rowPlaceholder))
            ),
            $parameters
        );
    }
}
