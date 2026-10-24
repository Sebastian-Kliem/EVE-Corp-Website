<?php

namespace App\Service\Trading;

use App\Entity\Market\MarketPrice;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;

/**
 * Ranks the types of one hub station for station trading; filtering, sorting and paging happen in SQL on market_price and market_type_stats.
 */
class StationTradingQuery
{
    public const PAGE_SIZE = 50;
    // Best prices may lie this far beyond the average daily low or high and still count as filled
    private const FILL_TOLERANCE = 0.9;
    public const SORT_COLUMNS = ['potential', 'profit_per_unit', 'margin', 'avg_volume7d', 'competitors', 'buy_best', 'activity'];

    public function __construct(private readonly Connection $connection) {}

    /**
     * @param array{stationId: int, regionId: int, salesTax: float, brokerFee: float, capital: float, minVolume: float, minMargin: float, maxBuyPrice: float, minTradedDays: int, typeIds: ?array<int>, sort: string, direction: string, page: int} $filter
     * @return array{rows: list<array<string, mixed>>, total: int}
     */
    public function find(array $filter): array
    {
        [$innerSql, $parameters, $types] = $this->_buildInnerQuery($filter);
        [$whereSql, $parameters, $types] = $this->_buildOuterWhere($filter, $parameters, $types);

        $total = (int)$this->connection->fetchOne(sprintf('SELECT COUNT(*) FROM (%s) ranked %s', $innerSql, $whereSql), $parameters, $types);

        $sort = in_array($filter['sort'], self::SORT_COLUMNS, true) ? $filter['sort'] : 'potential';
        $direction = $filter['direction'] === 'asc' ? 'ASC' : 'DESC';
        $offset = max(0, $filter['page'] - 1) * self::PAGE_SIZE;

        $rows = $this->connection->fetchAllAssociative(
            sprintf('SELECT * FROM (%s) ranked %s ORDER BY %s %s, type_id ASC LIMIT %d OFFSET %d', $innerSql, $whereSql, $sort, $direction, self::PAGE_SIZE, $offset),
            $parameters,
            $types
        );

        return ['rows' => $rows, 'total' => $total];
    }

    /**
     * Profit per unit buys at the best buy and sells at the best sell price with broker fee on both orders and sales tax on the sale.
     * The 7 day average price tells which share of the volume filled buy orders (average near the buy price) or sell orders;
     * a trade cycle needs both, so the smaller share counts. Prices outside the daily lows and highs of the last 7 days never filled
     * and give no units. Expected units share the rest with the orders near the best price and are capped by the capital.
     *
     * @return array{0: string, 1: array<string, mixed>, 2: array<string, mixed>}
     */
    private function _buildInnerQuery(array $filter): array
    {
        $buySideShare = 'GREATEST(0, LEAST(1, (p.sell_best - COALESCE(s.avg_price7d, p.sell_best)) / NULLIF(p.sell_best - p.buy_best, 0)))';
        $isFillable = sprintf('(p.buy_best >= s.avg_lowest7d * %1$s AND p.sell_best <= s.avg_highest7d / %1$s)', self::FILL_TOLERANCE);
        $expectedUnits = sprintf(
            '(CASE WHEN %2$s THEN s.avg_volume7d * LEAST(%1$s, 1 - %1$s) / (GREATEST(p.buy_depth_order_count, p.sell_depth_order_count) + 1) ELSE 0 END)',
            $buySideShare,
            $isFillable
        );
        if ($filter['capital'] > 0) {
            $expectedUnits = sprintf('LEAST(%s, :capital / (p.buy_best * (1 + :brokerFee)))', $expectedUnits);
        }
        $profitPerUnit = '(p.sell_best * (1 - :salesTax - :brokerFee) - p.buy_best * (1 + :brokerFee))';

        $sql = sprintf(
            'SELECT p.type_id, p.buy_best, p.sell_best, p.buy_volume, p.sell_volume,
                p.buy_depth_order_count, p.sell_depth_order_count,
                p.buy_updated1h, p.sell_updated1h, p.buy_updated24h, p.sell_updated24h,
                p.buy_best_issued_at, p.sell_best_issued_at, p.fetched_at,
                s.avg_volume7d, s.avg_volume30d, s.avg_price7d, s.avg_price30d, s.traded_days30d, s.price_volatility30d,
                GREATEST(p.buy_depth_order_count, p.sell_depth_order_count) AS competitors,
                %3$s AS buy_side_share,
                %4$s AS fillable,
                s.avg_lowest7d, s.avg_highest7d,
                p.buy_updated24h + p.sell_updated24h AS activity,
                %1$s AS profit_per_unit,
                %1$s / (p.buy_best * (1 + :brokerFee)) AS margin,
                %2$s AS expected_units,
                %1$s * %2$s AS potential
             FROM market_price p
             JOIN market_type_stats s ON s.region_id = p.region_id AND s.type_id = p.type_id
             WHERE p.scope = :scope AND p.location_id = :stationId AND p.region_id = :regionId
                AND p.buy_best IS NOT NULL AND p.sell_best IS NOT NULL AND s.avg_volume7d > 0',
            $profitPerUnit,
            $expectedUnits,
            $buySideShare,
            $isFillable
        );

        $parameters = [
            'scope' => MarketPrice::SCOPE_STATION,
            'stationId' => $filter['stationId'],
            'regionId' => $filter['regionId'],
            'salesTax' => $filter['salesTax'],
            'brokerFee' => $filter['brokerFee'],
        ];
        $types = [];
        if ($filter['capital'] > 0) {
            $parameters['capital'] = $filter['capital'];
        }
        if ($filter['typeIds'] !== null) {
            $sql .= ' AND p.type_id IN (:typeIds)';
            $parameters['typeIds'] = $filter['typeIds'] === [] ? [0] : $filter['typeIds'];
            $types['typeIds'] = ArrayParameterType::INTEGER;
        }

        return [$sql, $parameters, $types];
    }

    /**
     * @return array{0: string, 1: array<string, mixed>, 2: array<string, mixed>}
     */
    private function _buildOuterWhere(array $filter, array $parameters, array $types): array
    {
        $conditions = ['profit_per_unit > 0'];
        if ($filter['minVolume'] > 0) {
            $conditions[] = 'avg_volume7d >= :minVolume';
            $parameters['minVolume'] = $filter['minVolume'];
        }
        if ($filter['minMargin'] > 0) {
            $conditions[] = 'margin >= :minMargin';
            $parameters['minMargin'] = $filter['minMargin'];
        }
        if ($filter['maxBuyPrice'] > 0) {
            $conditions[] = 'buy_best <= :maxBuyPrice';
            $parameters['maxBuyPrice'] = $filter['maxBuyPrice'];
        }
        if ($filter['minTradedDays'] > 0) {
            $conditions[] = 'traded_days30d >= :minTradedDays';
            $parameters['minTradedDays'] = $filter['minTradedDays'];
            $types['minTradedDays'] = ParameterType::INTEGER;
        }

        return ['WHERE ' . implode(' AND ', $conditions), $parameters, $types];
    }
}
