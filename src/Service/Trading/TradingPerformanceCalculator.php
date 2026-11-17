<?php

namespace App\Service\Trading;

use App\Entity\EveCharacter;
use App\Service\Market\MarketHub;
use App\Service\SdeService;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;

/**
 * Realised market trading profit of a user: sales are matched against the newest own market purchases (LIFO).
 *
 * Market sales without a preceding purchase (loot, production, old stock) carry no cost basis and are left out completely.
 * Contracts are ignored, delivery contracts into wormholes distort the cost of single items.
 */
class TradingPerformanceCalculator
{
    private const SECONDS_PER_DAY = 86400;
    private const EVE_START_DATE = '2003-05-06';
    // PLEX can also be bought for real money into the vault, which ESI does not show
    private const IGNORED_TYPE_IDS = [44992 => true];

    public function __construct(
        private readonly Connection $connection,
        private readonly SdeService $sdeService,
        private readonly TradingBrokerFeeAttributor $brokerFeeAttributor
    ) {}

    /**
     * @param EveCharacter[] $characters All characters of the user, the cost basis is shared between them
     * @param ?int $characterId Only report sales of this character, null for all
     * @param ?int $detailTypeId Also return every purchase and sale of this type in the period
     * @return array<string, mixed>
     */
    public function calculate(array $characters, \DateTimeImmutable $from, \DateTimeImmutable $to, ?int $characterId, ?int $detailTypeId = null): array
    {
        $characterIds = [];
        foreach ($characters as $character) {
            $characterIds[] = (int)$character->getId();
        }
        if ($characterIds === []) {
            return $this->_emptyResult();
        }

        $events = $this->_loadEvents($characterIds);
        $taxBySale = $this->_loadSalesTax($characterIds);
        $reportedCharacterIds = $characterId === null ? $characterIds : [$characterId];
        $fees = $this->brokerFeeAttributor->attribute($characterIds, new \DateTimeImmutable(self::EVE_START_DATE), new \DateTimeImmutable());
        $feeRates = $this->_feeRates($events, $fees, $this->_loadListedSellValues($characterIds));

        $stock = new TradingLotStock();
        $items = [];
        $detailEntries = [];
        // Sold and cost-matched units per type over all time, the rest of the sales are loot or production
        $soldQuantities = [];
        $matchedQuantities = [];
        $saleCount = 0;
        foreach ($events as $event) {
            $isInPeriod = $event['timestamp'] >= $from->getTimestamp() && $event['timestamp'] < $to->getTimestamp();
            $isReported = $isInPeriod && in_array($event['characterId'], $reportedCharacterIds, true);

            $typeId = $event['typeId'];
            $isDetail = $isReported && $typeId === $detailTypeId;
            if ($event['isBuy']) {
                $unitFee = $event['unitPrice'] * ($feeRates['buy'][$typeId] ?? 0.0);
                $stock->add($typeId, $event['quantity'], $event['unitPrice'], $event['timestamp'], $unitFee);
                if ($isDetail) {
                    $detailEntries[] = $this->_detailEntry($event, null, 0.0);
                }
                continue;
            }

            $taken = $stock->take($typeId, $event['quantity'], $event['timestamp']);
            $soldQuantities[$typeId] = ($soldQuantities[$typeId] ?? 0.0) + $event['quantity'];
            $matchedQuantities[$typeId] = ($matchedQuantities[$typeId] ?? 0.0) + $taken['quantity'];
            $tax = ($taxBySale[$event['taxKey']] ?? 0.0) * $event['taxShare'] * ($taken['quantity'] / $event['quantity']);
            $sellFee = $taken['quantity'] * $event['unitPrice'] * ($feeRates['sell'][$typeId] ?? 0.0);
            if ($isDetail) {
                $detailEntries[] = $this->_detailEntry($event, $taken, $tax, $sellFee);
            }
            if ($taken['quantity'] <= 0 || !$isReported) {
                continue;
            }

            $sale = [
                'quantity' => $taken['quantity'],
                'revenue' => $taken['quantity'] * $event['unitPrice'],
                'cost' => $taken['cost'],
                'brokerFee' => $taken['fee'] + $sellFee,
                'buyFee' => $taken['fee'],
                'tax' => $tax,
                'holdingSeconds' => $taken['holdingSeconds'],
            ];
            $items[$typeId] = $this->_addSaleToItem($items[$typeId] ?? null, $typeId, $sale);
            $saleCount++;
        }

        $tradedSellShares = [];
        foreach ($soldQuantities as $typeId => $soldQuantity) {
            $tradedSellShares[$typeId] = $soldQuantity > 0 ? $matchedQuantities[$typeId] / $soldQuantity : 1.0;
        }
        $brokerFees = $this->_summarizeBrokerFees($fees, $reportedCharacterIds, $from, $to, $feeRates['buy'], $tradedSellShares);

        $result = $this->_buildResult($items, $saleCount, $brokerFees, $characterIds);
        // Types never sold on the market are production inputs, not trading stock
        $tradedStock = array_intersect_key($stock->remaining(), $soldQuantities);
        $result['positions'] = $this->_buildPositions($tradedStock, $characterIds);
        if ($detailTypeId !== null) {
            $result['detail'] = [
                'typeId' => $detailTypeId,
                'itemName' => $this->sdeService->getItemName($detailTypeId),
                'entries' => array_reverse($detailEntries),
            ];
        }

        return $result;
    }

    /**
     * @param array<string, mixed> $event
     * @param ?array{quantity: float, cost: float, fee: float, holdingSeconds: float} $taken Null for purchases
     * @return array<string, mixed>
     */
    private function _detailEntry(array $event, ?array $taken, float $tax, float $sellFee = 0.0): array
    {
        $entry = [
            'date' => (new \DateTimeImmutable('@' . $event['timestamp']))->format(\DateTimeInterface::ATOM),
            'characterId' => $event['characterId'],
            'isBuy' => $event['isBuy'],
            'quantity' => $event['quantity'],
            'unitPrice' => $event['unitPrice'],
        ];
        if ($taken === null) {
            return $entry;
        }

        $revenue = $taken['quantity'] * $event['unitPrice'];
        return $entry + [
            'matchedQuantity' => $taken['quantity'],
            // Including the buy order fee share
            'unitCost' => $taken['quantity'] > 0 ? ($taken['cost'] + $taken['fee']) / $taken['quantity'] : null,
            'salesTax' => $tax,
            'brokerFee' => $sellFee,
            'profit' => $taken['quantity'] > 0 ? $revenue - $taken['cost'] - $taken['fee'] - $tax - $sellFee : null,
        ];
    }

    /**
     * Remaining purchased lots, capped at what the characters still own (assets and sell orders); the rest was used or moved.
     *
     * @param array<int, array{quantity: float, cost: float, oldestAcquiredAt: int}> $remaining
     * @param int[] $characterIds
     * @return list<array<string, mixed>>
     */
    private function _buildPositions(array $remaining, array $characterIds): array
    {
        if ($remaining === []) {
            return [];
        }
        $typeIds = array_keys($remaining);
        $owned = $this->_loadOwnedQuantities($characterIds, $typeIds);
        $marketPrices = $this->_loadJitaPrices($typeIds);

        $positions = [];
        foreach ($remaining as $typeId => $position) {
            $quantity = min($position['quantity'], $owned[$typeId] ?? 0.0);
            if ($quantity <= 0) {
                continue;
            }
            // Of the remaining lots the oldest are left, their average cost applies
            $cost = $position['cost'] * $quantity / $position['quantity'];
            $sellPrice = $marketPrices[$typeId]['sell'] ?? null;
            $value = $sellPrice === null ? null : $sellPrice * $quantity;
            $positions[] = [
                'typeId' => $typeId,
                'itemName' => $this->sdeService->getItemName($typeId),
                'quantity' => $quantity,
                'cost' => $cost,
                'unitCost' => $cost / $quantity,
                'jitaSell' => $sellPrice,
                'jitaBuy' => $marketPrices[$typeId]['buy'] ?? null,
                'value' => $value,
                'unrealizedProfit' => $value === null ? null : $value - $cost,
                'oldestAcquiredAt' => (new \DateTimeImmutable('@' . $position['oldestAcquiredAt']))->format(\DateTimeInterface::ATOM),
            ];
        }
        usort($positions, fn(array $first, array $second): int => $second['cost'] <=> $first['cost']);

        return $positions;
    }

    /**
     * @param int[] $characterIds
     * @param int[] $typeIds
     * @return array<int, float>
     */
    private function _loadOwnedQuantities(array $characterIds, array $typeIds): array
    {
        $rows = $this->connection->fetchAllAssociative(
            'SELECT type_id, SUM(quantity) AS quantity FROM (
                SELECT type_id, quantity FROM eve_character_asset WHERE character_id IN (:characterIds) AND type_id IN (:typeIds)
                UNION ALL
                SELECT type_id, volume_remain FROM eve_character_market_order
                WHERE character_id IN (:characterIds) AND type_id IN (:typeIds) AND is_buy = 0 AND is_corporation = 0
             ) owned GROUP BY type_id',
            ['characterIds' => $characterIds, 'typeIds' => $typeIds],
            ['characterIds' => ArrayParameterType::INTEGER, 'typeIds' => ArrayParameterType::INTEGER]
        );

        $owned = [];
        foreach ($rows as $row) {
            $owned[(int)$row['type_id']] = (float)$row['quantity'];
        }

        return $owned;
    }

    /**
     * @param int[] $typeIds
     * @return array<int, array{buy: ?float, sell: ?float}>
     */
    private function _loadJitaPrices(array $typeIds): array
    {
        $rows = $this->connection->fetchAllAssociative(
            'SELECT type_id, buy_best, sell_best FROM market_price WHERE location_id = :stationId AND type_id IN (:typeIds)',
            ['stationId' => MarketHub::JITA, 'typeIds' => $typeIds],
            ['typeIds' => ArrayParameterType::INTEGER]
        );

        $prices = [];
        foreach ($rows as $row) {
            $prices[(int)$row['type_id']] = [
                'buy' => $row['buy_best'] === null ? null : (float)$row['buy_best'],
                'sell' => $row['sell_best'] === null ? null : (float)$row['sell_best'],
            ];
        }

        return $prices;
    }

    /**
     * Broker fees per traded ISK and type and order side; each unit carries its share into the sale, so a fee for a large
     * order spreads over all its units instead of hitting the first sale. Sell fees also spread over units still listed.
     * Only trades after the first fee booking of the character count, older journal entries are not synced.
     *
     * @param list<array<string, mixed>> $events
     * @param list<array{characterId: int, timestamp: int, amount: float, typeId: ?int, isBuy: ?bool, isExact: bool}> $fees
     * @param array<int, float> $listedSellValues
     * @return array{buy: array<int, float>, sell: array<int, float>} Buy rates for every bought type
     */
    private function _feeRates(array $events, array $fees, array $listedSellValues): array
    {
        $firstFeeTimestamps = [];
        $feeSums = ['buy' => [], 'sell' => []];
        foreach ($fees as $fee) {
            $firstFeeTimestamps[$fee['characterId']] = min($firstFeeTimestamps[$fee['characterId']] ?? PHP_INT_MAX, $fee['timestamp']);
            if ($fee['typeId'] !== null) {
                $side = $fee['isBuy'] ? 'buy' : 'sell';
                $feeSums[$side][$fee['typeId']] = ($feeSums[$side][$fee['typeId']] ?? 0.0) + $fee['amount'];
            }
        }

        $tradedValues = ['buy' => [], 'sell' => $listedSellValues];
        // Types bought only before the fee data start still count as traded, without a known fee
        $rates = ['buy' => [], 'sell' => []];
        foreach ($events as $event) {
            $side = $event['isBuy'] ? 'buy' : 'sell';
            if ($event['isBuy']) {
                $rates['buy'][$event['typeId']] = 0.0;
            }
            if ($event['timestamp'] >= ($firstFeeTimestamps[$event['characterId']] ?? PHP_INT_MAX)) {
                $tradedValues[$side][$event['typeId']] = ($tradedValues[$side][$event['typeId']] ?? 0.0) + $event['quantity'] * $event['unitPrice'];
            }
        }

        foreach ($tradedValues as $side => $values) {
            foreach ($values as $typeId => $value) {
                if ($value > 0) {
                    $rates[$side][$typeId] = ($feeSums[$side][$typeId] ?? 0.0) / $value;
                }
            }
        }

        return $rates;
    }

    /**
     * Value of the own sell orders that are still listed.
     *
     * @param int[] $characterIds
     * @return array<int, float>
     */
    private function _loadListedSellValues(array $characterIds): array
    {
        $rows = $this->connection->fetchAllAssociative(
            'SELECT type_id, SUM(price * volume_remain) AS listed_value FROM eve_character_market_order
             WHERE character_id IN (:characterIds) AND is_buy = 0 AND is_corporation = 0 GROUP BY type_id',
            ['characterIds' => $characterIds],
            ['characterIds' => ArrayParameterType::INTEGER]
        );

        $values = [];
        foreach ($rows as $row) {
            $values[(int)$row['type_id']] = (float)$row['listed_value'];
        }

        return $values;
    }

    /**
     * Broker fee bookings in the period for the match statistics. The fees themselves reach the result through the
     * sold units (see _feeRates); fees of types never bought (loot, production) and the sell fee share of untraded
     * units are no trading cost, unassigned bookings are charged to the period.
     *
     * @param list<array{characterId: int, timestamp: int, amount: float, typeId: ?int, isBuy: ?bool, isExact: bool}> $fees
     * @param int[] $characterIds
     * @param array<int, float> $buyFeeRates
     * @param array<int, float> $tradedSellShares
     * @return array{unassigned: float, excluded: float, exactCount: int, estimatedCount: int, totalCount: int}
     */
    private function _summarizeBrokerFees(array $fees, array $characterIds, \DateTimeImmutable $from, \DateTimeImmutable $to, array $buyFeeRates, array $tradedSellShares): array
    {
        $result = ['unassigned' => 0.0, 'excluded' => 0.0, 'exactCount' => 0, 'estimatedCount' => 0, 'totalCount' => 0];
        foreach ($fees as $fee) {
            if ($fee['timestamp'] < $from->getTimestamp() || $fee['timestamp'] >= $to->getTimestamp() || !in_array($fee['characterId'], $characterIds, true)) {
                continue;
            }
            $result['totalCount']++;
            $typeId = $fee['typeId'];
            if ($typeId === null) {
                $result['unassigned'] += $fee['amount'];
                continue;
            }
            $result[$fee['isExact'] ? 'exactCount' : 'estimatedCount']++;

            if (!isset($buyFeeRates[$typeId])) {
                $result['excluded'] += $fee['amount'];
            } elseif (!$fee['isBuy']) {
                $result['excluded'] += $fee['amount'] * (1 - ($tradedSellShares[$typeId] ?? 1.0));
            }
        }

        return $result;
    }

    /**
     * @param ?array<string, mixed> $item
     * @param array<string, mixed> $sale
     * @return array<string, mixed>
     */
    private function _addSaleToItem(?array $item, int $typeId, array $sale): array
    {
        $item ??= [
            'typeId' => $typeId,
            'quantity' => 0.0,
            'revenue' => 0.0,
            'cost' => 0.0,
            'brokerFees' => 0.0,
            'buyFees' => 0.0,
            'tax' => 0.0,
            'holdingSeconds' => 0.0,
            'sales' => 0,
        ];

        $item['quantity'] += $sale['quantity'];
        $item['revenue'] += $sale['revenue'];
        $item['cost'] += $sale['cost'];
        $item['brokerFees'] += $sale['brokerFee'];
        $item['buyFees'] += $sale['buyFee'];
        $item['tax'] += $sale['tax'];
        $item['holdingSeconds'] += $sale['holdingSeconds'];
        $item['sales']++;

        return $item;
    }

    /**
     * Market transactions, oldest first.
     *
     * @param int[] $characterIds
     * @return list<array<string, mixed>>
     */
    private function _loadEvents(array $characterIds): array
    {
        $events = $this->_loadMarketEvents($characterIds);

        // Purchases first within the same second, so an instant resale finds its cost basis
        usort($events, function (array $first, array $second): int {
            return [$first['timestamp'], $first['isBuy'] ? 0 : 1] <=> [$second['timestamp'], $second['isBuy'] ? 0 : 1];
        });

        return $events;
    }

    /**
     * @param int[] $characterIds
     * @return list<array<string, mixed>>
     */
    private function _loadMarketEvents(array $characterIds): array
    {
        $rows = $this->connection->fetchAllAssociative(
            'SELECT character_id, date, type_id, quantity, unit_price, is_buy, unit_price * quantity AS total
             FROM eve_character_market_transaction WHERE character_id IN (:characterIds)',
            ['characterIds' => $characterIds],
            ['characterIds' => ArrayParameterType::INTEGER]
        );

        $saleTotals = [];
        foreach ($rows as $row) {
            if (!(bool)$row['is_buy']) {
                $taxKey = $row['character_id'] . '|' . $row['date'];
                $saleTotals[$taxKey] = ($saleTotals[$taxKey] ?? 0.0) + (float)$row['total'];
            }
        }

        $events = [];
        foreach ($rows as $row) {
            if (isset(self::IGNORED_TYPE_IDS[(int)$row['type_id']])) {
                continue;
            }
            $taxKey = $row['character_id'] . '|' . $row['date'];
            $isBuy = (bool)$row['is_buy'];
            $events[] = [
                'characterId' => (int)$row['character_id'],
                'timestamp' => $this->_toTimestamp((string)$row['date']),
                'typeId' => (int)$row['type_id'],
                'quantity' => (float)$row['quantity'],
                'unitPrice' => (float)$row['unit_price'],
                'isBuy' => $isBuy,
                'taxKey' => $taxKey,
                // Tax is booked per wallet timestamp, sales in the same second share it by value
                'taxShare' => !$isBuy && ($saleTotals[$taxKey] ?? 0.0) > 0 ? (float)$row['total'] / $saleTotals[$taxKey] : 0.0,
            ];
        }

        return $events;
    }

    /**
     * Sales tax is booked without a reference to the transaction, but with the same character and second.
     *
     * @param int[] $characterIds
     * @return array<string, float> "characterId|date" => tax (positive)
     */
    private function _loadSalesTax(array $characterIds): array
    {
        $rows = $this->connection->fetchAllAssociative(
            "SELECT character_id, date, SUM(amount) AS amount FROM eve_character_wallet_journal_entry
             WHERE character_id IN (:characterIds) AND ref_type = 'transaction_tax'
             GROUP BY character_id, date",
            ['characterIds' => $characterIds],
            ['characterIds' => ArrayParameterType::INTEGER]
        );

        $taxes = [];
        foreach ($rows as $row) {
            $taxes[$row['character_id'] . '|' . $row['date']] = abs((float)$row['amount']);
        }

        return $taxes;
    }

    /**
     * @param int[] $characterIds
     */
    private function _findDataStart(array $characterIds): ?string
    {
        $firstDate = $this->connection->fetchOne(
            'SELECT MIN(date) FROM eve_character_market_transaction WHERE character_id IN (:characterIds)',
            ['characterIds' => $characterIds],
            ['characterIds' => ArrayParameterType::INTEGER]
        );

        return $firstDate ? (new \DateTimeImmutable((string)$firstDate, new \DateTimeZone('UTC')))->format(\DateTimeInterface::ATOM) : null;
    }

    /**
     * @param array<int, array<string, mixed>> $items
     * @param array{unassigned: float, excluded: float, exactCount: int, estimatedCount: int, totalCount: int} $brokerFees
     * @param int[] $characterIds
     * @return array<string, mixed>
     */
    private function _buildResult(array $items, int $saleCount, array $brokerFees, array $characterIds): array
    {
        $summary = ['revenue' => 0.0, 'cost' => 0.0, 'tax' => 0.0, 'itemBrokerFees' => 0.0, 'buyFees' => 0.0];
        $rows = [];
        foreach ($items as $item) {
            $summary['revenue'] += $item['revenue'];
            $summary['cost'] += $item['cost'];
            $summary['tax'] += $item['tax'];
            $summary['itemBrokerFees'] += $item['brokerFees'];
            $summary['buyFees'] += $item['buyFees'];
            $rows[] = $this->_formatItem($item);
        }
        usort($rows, fn(array $first, array $second): int => $second['profit'] <=> $first['profit']);

        $totalBrokerFees = $summary['itemBrokerFees'] + $brokerFees['unassigned'];
        $grossProfit = $summary['revenue'] - $summary['cost'];
        $netProfit = $grossProfit - $summary['tax'] - $totalBrokerFees;

        return [
            'summary' => [
                'revenue' => $summary['revenue'],
                'cost' => $summary['cost'],
                'grossProfit' => $grossProfit,
                'salesTax' => $summary['tax'],
                'brokerFees' => $totalBrokerFees,
                'brokerFeesItems' => $summary['itemBrokerFees'],
                'brokerFeesBuyShare' => $summary['buyFees'],
                'brokerFeesUnassigned' => $brokerFees['unassigned'],
                'brokerFeesExcluded' => $brokerFees['excluded'],
                'feeBookings' => $brokerFees['totalCount'],
                'feeBookingsExact' => $brokerFees['exactCount'],
                'feeBookingsEstimated' => $brokerFees['estimatedCount'],
                'netProfit' => $netProfit,
                'margin' => $summary['revenue'] > 0 ? $netProfit / $summary['revenue'] : null,
                'sales' => $saleCount,
                'orderActions' => $brokerFees['totalCount'],
            ],
            'items' => $rows,
            'dataStart' => $this->_findDataStart($characterIds),
        ];
    }

    /**
     * @param array<string, mixed> $item
     * @return array<string, mixed>
     */
    private function _formatItem(array $item): array
    {
        $profit = $item['revenue'] - $item['cost'] - $item['tax'] - $item['brokerFees'];

        return [
            'typeId' => $item['typeId'],
            'itemName' => $this->sdeService->getItemName($item['typeId']),
            'quantity' => $item['quantity'],
            'revenue' => $item['revenue'],
            'cost' => $item['cost'],
            'salesTax' => $item['tax'],
            'brokerFees' => $item['brokerFees'],
            'profit' => $profit,
            'margin' => $item['revenue'] > 0 ? $profit / $item['revenue'] : null,
            'averageHoldingDays' => $item['quantity'] > 0 ? $item['holdingSeconds'] / $item['quantity'] / self::SECONDS_PER_DAY : null,
            'sales' => $item['sales'],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function _emptyResult(): array
    {
        return [
            'summary' => [
                'revenue' => 0.0, 'cost' => 0.0, 'grossProfit' => 0.0, 'salesTax' => 0.0, 'brokerFees' => 0.0,
                'brokerFeesItems' => 0.0, 'brokerFeesBuyShare' => 0.0, 'brokerFeesUnassigned' => 0.0, 'brokerFeesExcluded' => 0.0,
                'feeBookings' => 0, 'feeBookingsExact' => 0, 'feeBookingsEstimated' => 0,
                'netProfit' => 0.0, 'margin' => null, 'sales' => 0, 'orderActions' => 0,
            ],
            'items' => [],
            'positions' => [],
            'dataStart' => null,
        ];
    }

    // Database dates are stored in UTC (EVE time)
    private function _toTimestamp(string $date): int
    {
        return (new \DateTimeImmutable($date, new \DateTimeZone('UTC')))->getTimestamp();
    }
}
