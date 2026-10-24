<?php

namespace App\Repository;

use App\Entity\Market\MarketTypeStats;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<MarketTypeStats>
 */
class MarketTypeStatsRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, MarketTypeStats::class);
    }

    /**
     * Recomputes all key figures from market_history in one statement.
     * The newest day is left out, EVE Ref still completes it during the day.
     *
     * @return int Number of stored region/type rows
     */
    public function rebuild(\DateTimeImmutable $computedAt): int
    {
        $connection = $this->getEntityManager()->getConnection();
        $newestDate = $connection->fetchOne('SELECT MAX(date) FROM market_history');
        if ($newestDate === null || $newestDate === false) {
            return 0;
        }

        $windowEnd = (new \DateTimeImmutable((string)$newestDate))->modify('-1 day');
        $shortWindowStart = $windowEnd->modify(sprintf('-%d days', MarketTypeStats::SHORT_WINDOW_DAYS - 1))->format('Y-m-d');
        $longWindowStart = $windowEnd->modify(sprintf('-%d days', MarketTypeStats::LONG_WINDOW_DAYS - 1))->format('Y-m-d');

        return (int)$connection->transactional(function () use ($connection, $windowEnd, $shortWindowStart, $longWindowStart, $computedAt): int {
            $connection->executeStatement('DELETE FROM market_type_stats');

            return (int)$connection->executeStatement(
                'INSERT INTO market_type_stats (region_id, type_id, avg_volume7d, avg_volume30d, avg_price7d, avg_price30d, avg_lowest7d, avg_highest7d, traded_days30d, price_volatility30d, computed_at)
                 SELECT region_id, type_id,
                    SUM(CASE WHEN date >= :shortStart THEN volume ELSE 0 END) / :shortDays,
                    SUM(volume) / :longDays,
                    SUM(CASE WHEN date >= :shortStart THEN average * volume ELSE 0 END) / NULLIF(SUM(CASE WHEN date >= :shortStart THEN volume ELSE 0 END), 0),
                    SUM(average * volume) / NULLIF(SUM(volume), 0),
                    AVG(CASE WHEN date >= :shortStart THEN lowest END),
                    AVG(CASE WHEN date >= :shortStart THEN highest END),
                    COUNT(*),
                    STDDEV_POP(average) / NULLIF(AVG(average), 0),
                    :computedAt
                 FROM market_history
                 WHERE date BETWEEN :longStart AND :windowEnd
                 GROUP BY region_id, type_id',
                [
                    'shortStart' => $shortWindowStart,
                    'longStart' => $longWindowStart,
                    'windowEnd' => $windowEnd->format('Y-m-d'),
                    'shortDays' => MarketTypeStats::SHORT_WINDOW_DAYS,
                    'longDays' => MarketTypeStats::LONG_WINDOW_DAYS,
                    'computedAt' => $computedAt->format('Y-m-d H:i:s'),
                ]
            );
        });
    }

    public function isEmpty(): bool
    {
        return $this->getEntityManager()->getConnection()->fetchOne('SELECT 1 FROM market_type_stats LIMIT 1') === false;
    }
}
