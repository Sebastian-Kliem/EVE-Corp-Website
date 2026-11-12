<?php

namespace App\Service\Trading;

use App\Entity\EveCharacter;
use App\Service\JitaPriceService;
use App\Service\Market\MarketHub;
use App\Service\SdeService;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;

/**
 * Realised trading profit of a user: sales are matched against a moving average cost of all own purchases (market and contracts).
 *
 * Market sales without a preceding purchase (loot, production, old stock) carry no cost basis and are left out completely.
 * Sale contracts are reported as a whole, items in them without a purchase are valued at the Jita buy price of the sale day.
 */
class TradingPerformanceCalculator
{
    private const SECONDS_PER_DAY = 86400;
    // PLEX can also be bought for real money into the vault, which ESI does not show
    private const IGNORED_TYPE_IDS = [44992 => true];

    public function __construct(
        private readonly Connection $connection,
        private readonly JitaPriceService $jitaPriceService,
        private readonly SdeService $sdeService,
        private readonly TradingBrokerFeeAttributor $brokerFeeAttributor
    ) {}

    /**
     * @param EveCharacter[] $characters All characters of the user, the cost basis is shared between them
     * @param ?int $characterId Only report sales of this character, null for all
     * @return array<string, mixed>
     */
    public function calculate(array $characters, \DateTimeImmutable $from, \DateTimeImmutable $to, ?int $characterId): array
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

        $holdings = [];
        $items = [];
        $contracts = [];
        $purchasedTypeIds = [];
        $saleCount = 0;
        foreach ($events as $event) {
            $isInPeriod = $event['timestamp'] >= $from->getTimestamp() && $event['timestamp'] < $to->getTimestamp();
            $isReported = $isInPeriod && in_array($event['characterId'], $reportedCharacterIds, true);

            if ($event['kind'] === 'contractSale') {
                [$holdings, $contract] = $this->_processContractSale($holdings, $event);
                if ($isReported) {
                    $contracts[] = $contract;
                }
                continue;
            }

            $typeId = $event['typeId'];
            $holding = $holdings[$typeId] ?? ['quantity' => 0.0, 'averageCost' => 0.0, 'averageAcquiredAt' => 0.0];

            if ($event['isBuy']) {
                $holdings[$typeId] = $this->_addPurchase($holding, $event);
                if ($event['timestamp'] < $to->getTimestamp()) {
                    $purchasedTypeIds[$typeId] = true;
                }
                continue;
            }

            $matchedQuantity = min($event['quantity'], $holding['quantity']);
            if ($matchedQuantity <= 0) {
                continue;
            }
            $holdings[$typeId] = ['quantity' => $holding['quantity'] - $matchedQuantity] + $holding;
            if (!$isReported) {
                continue;
            }

            $matchedShare = $matchedQuantity / $event['quantity'];
            $sale = [
                'quantity' => $matchedQuantity,
                'revenue' => $matchedQuantity * $event['unitPrice'],
                'cost' => $matchedQuantity * $holding['averageCost'],
                'tax' => ($taxBySale[$event['taxKey']] ?? 0.0) * $event['taxShare'] * $matchedShare,
                'holdingSeconds' => max(0.0, $event['timestamp'] - $holding['averageAcquiredAt']) * $matchedQuantity,
            ];
            $items[$typeId] = $this->_addSaleToItem($items[$typeId] ?? null, $typeId, $sale);
            $saleCount++;
        }

        $brokerFees = $this->_splitBrokerFees($reportedCharacterIds, $from, $to, $purchasedTypeIds);

        return $this->_buildResult($items, $contracts, $saleCount, $brokerFees, $characterIds);
    }

    /**
     * Broker fees of item types never bought (loot, production) are no trading cost and left out.
     *
     * @param int[] $characterIds
     * @param array<int, bool> $purchasedTypeIds
     * @return array{byType: array<int, float>, unassigned: float, excluded: float, exactCount: int, estimatedCount: int, totalCount: int}
     */
    private function _splitBrokerFees(array $characterIds, \DateTimeImmutable $from, \DateTimeImmutable $to, array $purchasedTypeIds): array
    {
        $attribution = $this->brokerFeeAttributor->attribute($characterIds, $from, $to);

        $tradingFees = [];
        $excludedFees = 0.0;
        foreach ($attribution['byType'] as $typeId => $fee) {
            if (isset($purchasedTypeIds[$typeId])) {
                $tradingFees[$typeId] = $fee;
            } else {
                $excludedFees += $fee;
            }
        }

        return [
            'byType' => $tradingFees,
            'unassigned' => $attribution['unassigned'],
            'excluded' => $excludedFees,
            'exactCount' => $attribution['exactCount'],
            'estimatedCount' => $attribution['estimatedCount'],
            'totalCount' => $attribution['totalCount'],
        ];
    }

    /**
     * Takes the contract items out of the holdings and compares the contract price with their cost.
     *
     * @param array<int, array{quantity: float, averageCost: float, averageAcquiredAt: float}> $holdings
     * @param array<string, mixed> $event
     * @return array{0: array<int, array{quantity: float, averageCost: float, averageAcquiredAt: float}>, 1: array<string, mixed>}
     */
    private function _processContractSale(array $holdings, array $event): array
    {
        $buyPrices = null;
        $positions = [];
        $totalCost = 0.0;
        foreach ($event['items'] as $typeId => $quantity) {
            $holding = $holdings[$typeId] ?? ['quantity' => 0.0, 'averageCost' => 0.0, 'averageAcquiredAt' => 0.0];
            $matchedQuantity = min($quantity, $holding['quantity']);
            $holdings[$typeId] = ['quantity' => $holding['quantity'] - $matchedQuantity] + $holding;

            $cost = $matchedQuantity * $holding['averageCost'];
            $remainingQuantity = $quantity - $matchedQuantity;
            $costSource = 'purchase';
            if ($remainingQuantity > 0) {
                // Only looked up for contracts with own stock, most contracts are fully covered by purchases
                $buyPrices ??= $this->_loadBuyPrices(array_keys($event['items']), $event['timestamp']);
                $buyPrice = $buyPrices[$typeId] ?? null;
                $cost += $remainingQuantity * ($buyPrice ?? 0.0);
                $costSource = $buyPrice === null ? 'none' : ($matchedQuantity > 0 ? 'mixed' : 'buyPrice');
            }

            $totalCost += $cost;
            $positions[] = [
                'typeId' => $typeId,
                'itemName' => $this->sdeService->getItemName($typeId),
                'quantity' => $quantity,
                'cost' => $cost,
                'costSource' => $costSource,
            ];
        }
        usort($positions, fn(array $first, array $second): int => $second['cost'] <=> $first['cost']);

        $profit = $event['price'] - $totalCost;

        return [$holdings, [
            'contractId' => $event['contractId'],
            'date' => (new \DateTimeImmutable('@' . $event['timestamp']))->format(\DateTimeInterface::ATOM),
            'mainItemName' => $positions[0]['itemName'] ?? '',
            'mainTypeId' => $positions[0]['typeId'] ?? 0,
            'revenue' => $event['price'],
            'cost' => $totalCost,
            'profit' => $profit,
            'margin' => $event['price'] > 0 ? $profit / $event['price'] : null,
            'positions' => $positions,
        ]];
    }

    /**
     * Jita buy side of the sale day: lowest traded price in The Forge (history), else the current best buy order.
     *
     * @param int[] $typeIds
     * @return array<int, float>
     */
    private function _loadBuyPrices(array $typeIds, int $timestamp): array
    {
        $prices = [];
        $historyRows = $this->connection->fetchAllAssociative(
            'SELECT type_id, lowest FROM market_history WHERE region_id = :regionId AND date = :date AND type_id IN (:typeIds)',
            [
                'regionId' => MarketHub::HUBS[MarketHub::JITA]['regionId'],
                'date' => gmdate('Y-m-d', $timestamp),
                'typeIds' => $typeIds,
            ],
            ['typeIds' => ArrayParameterType::INTEGER]
        );
        foreach ($historyRows as $row) {
            $prices[(int)$row['type_id']] = (float)$row['lowest'];
        }

        $missingTypeIds = array_values(array_diff($typeIds, array_keys($prices)));
        if ($missingTypeIds === []) {
            return $prices;
        }
        $currentRows = $this->connection->fetchAllAssociative(
            'SELECT type_id, buy_best FROM market_price WHERE location_id = :stationId AND type_id IN (:typeIds) AND buy_best IS NOT NULL',
            ['stationId' => MarketHub::JITA, 'typeIds' => $missingTypeIds],
            ['typeIds' => ArrayParameterType::INTEGER]
        );
        foreach ($currentRows as $row) {
            $prices[(int)$row['type_id']] = (float)$row['buy_best'];
        }

        return $prices;
    }

    /**
     * @param array{quantity: float, averageCost: float, averageAcquiredAt: float} $holding
     * @param array<string, mixed> $event
     * @return array{quantity: float, averageCost: float, averageAcquiredAt: float}
     */
    private function _addPurchase(array $holding, array $event): array
    {
        $newQuantity = $holding['quantity'] + $event['quantity'];

        return [
            'quantity' => $newQuantity,
            'averageCost' => ($holding['quantity'] * $holding['averageCost'] + $event['quantity'] * $event['unitPrice']) / $newQuantity,
            'averageAcquiredAt' => ($holding['quantity'] * $holding['averageAcquiredAt'] + $event['quantity'] * $event['timestamp']) / $newQuantity,
        ];
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
            'tax' => 0.0,
            'holdingSeconds' => 0.0,
            'sales' => 0,
        ];

        $item['quantity'] += $sale['quantity'];
        $item['revenue'] += $sale['revenue'];
        $item['cost'] += $sale['cost'];
        $item['tax'] += $sale['tax'];
        $item['holdingSeconds'] += $sale['holdingSeconds'];
        $item['sales']++;

        return $item;
    }

    /**
     * Market transactions, contract purchases split into items and whole sale contracts, oldest first.
     *
     * @param int[] $characterIds
     * @return list<array<string, mixed>>
     */
    private function _loadEvents(array $characterIds): array
    {
        $events = $this->_loadMarketEvents($characterIds);
        foreach ($this->_loadContractEvents($characterIds) as $event) {
            $events[] = $event;
        }

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
                'kind' => 'trade',
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
     * Finished item exchanges between an own character and an outsider; for purchases the price is split by average item value.
     *
     * @param int[] $characterIds
     * @return list<array<string, mixed>>
     */
    private function _loadContractEvents(array $characterIds): array
    {
        $rows = $this->connection->fetchAllAssociative(
            "SELECT contract_id, MIN(character_id) AS character_id, price, date_completed, items, issuer_id, acceptor_id
             FROM eve_character_contract
             WHERE character_id IN (:characterIds) AND type = 'item_exchange' AND status = 'finished'
               AND for_corporation = 0 AND price > 0 AND date_completed IS NOT NULL
             GROUP BY contract_id, price, date_completed, items, issuer_id, acceptor_id",
            ['characterIds' => $characterIds],
            ['characterIds' => ArrayParameterType::INTEGER]
        );

        $prices = $this->jitaPriceService->getGlobalPrices();
        $events = [];
        foreach ($rows as $row) {
            $isIssuerOwn = in_array((int)$row['issuer_id'], $characterIds, true);
            $isAcceptorOwn = in_array((int)$row['acceptor_id'], $characterIds, true);
            // Transfers between own characters are no trade
            if ($isIssuerOwn === $isAcceptorOwn) {
                continue;
            }
            $items = $this->_contractItems((string)$row['items']);
            if ($items === null) {
                continue;
            }

            $ownCharacterId = $isIssuerOwn ? (int)$row['issuer_id'] : (int)$row['acceptor_id'];
            $timestamp = $this->_toTimestamp((string)$row['date_completed']);
            if ($isIssuerOwn) {
                $events[] = [
                    'kind' => 'contractSale',
                    'characterId' => $ownCharacterId,
                    'timestamp' => $timestamp,
                    'contractId' => (string)$row['contract_id'],
                    'price' => (float)$row['price'],
                    'items' => $items,
                    'isBuy' => false,
                ];
                continue;
            }
            foreach ($this->_splitContractPrice($items, (float)$row['price'], $prices) as $typeId => $split) {
                $events[] = [
                    'kind' => 'trade',
                    'characterId' => $ownCharacterId,
                    'timestamp' => $timestamp,
                    'typeId' => $typeId,
                    'quantity' => $split['quantity'],
                    'unitPrice' => $split['unitPrice'],
                    'isBuy' => true,
                    'taxKey' => '',
                    'taxShare' => 0.0,
                ];
            }
        }

        return $events;
    }

    /**
     * Included items without blueprint copies, null for contracts that also request items in return.
     *
     * @return ?array<int, float> Type ID => quantity
     */
    private function _contractItems(string $itemsJson): ?array
    {
        $decoded = json_decode($itemsJson, true);
        if (!is_array($decoded) || $decoded === []) {
            return null;
        }

        $items = [];
        foreach ($decoded as $item) {
            if (empty($item['isIncluded'])) {
                return null;
            }
            $typeId = (int)($item['typeId'] ?? 0);
            if (!empty($item['isBlueprintCopy']) || isset(self::IGNORED_TYPE_IDS[$typeId])) {
                continue;
            }
            $items[$typeId] = ($items[$typeId] ?? 0.0) + (float)($item['quantity'] ?? 0);
        }

        return $items === [] ? null : $items;
    }

    /**
     * @param array<int, float> $items
     * @param array<int, float> $prices
     * @return array<int, array{quantity: float, unitPrice: float}>
     */
    private function _splitContractPrice(array $items, float $contractPrice, array $prices): array
    {
        $totalValue = 0.0;
        $totalQuantity = 0.0;
        foreach ($items as $typeId => $quantity) {
            $totalValue += ($prices[$typeId] ?? 0.0) * $quantity;
            $totalQuantity += $quantity;
        }

        $split = [];
        foreach ($items as $typeId => $quantity) {
            if ($quantity <= 0) {
                continue;
            }
            // Without any known price every unit gets the same share
            $share = $totalValue > 0 ? ($prices[$typeId] ?? 0.0) * $quantity / $totalValue : $quantity / $totalQuantity;
            $split[$typeId] = ['quantity' => $quantity, 'unitPrice' => $contractPrice * $share / $quantity];
        }

        return $split;
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
     * @param list<array<string, mixed>> $contracts
     * @param array{byType: array<int, float>, unassigned: float, excluded: float, exactCount: int, estimatedCount: int, totalCount: int} $brokerFees
     * @param int[] $characterIds
     * @return array<string, mixed>
     */
    private function _buildResult(array $items, array $contracts, int $saleCount, array $brokerFees, array $characterIds): array
    {
        $summary = ['revenue' => 0.0, 'cost' => 0.0, 'tax' => 0.0, 'itemBrokerFees' => 0.0];
        $openPositionFees = $brokerFees['byType'];
        $rows = [];
        foreach ($items as $typeId => $item) {
            $item['brokerFees'] = $openPositionFees[$typeId] ?? 0.0;
            unset($openPositionFees[$typeId]);
            $summary['revenue'] += $item['revenue'];
            $summary['cost'] += $item['cost'];
            $summary['tax'] += $item['tax'];
            $summary['itemBrokerFees'] += $item['brokerFees'];
            $rows[] = $this->_formatItem($item);
        }
        usort($rows, fn(array $first, array $second): int => $second['profit'] <=> $first['profit']);
        foreach ($contracts as $contract) {
            $summary['revenue'] += $contract['revenue'];
            $summary['cost'] += $contract['cost'];
        }
        usort($contracts, fn(array $first, array $second): int => $second['date'] <=> $first['date']);

        // Fees of bought items without a sale in the period still belong to trading (open positions)
        $openFees = array_sum($openPositionFees);
        $totalBrokerFees = $summary['itemBrokerFees'] + $openFees + $brokerFees['unassigned'];
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
                'brokerFeesOpenPositions' => $openFees,
                'brokerFeesUnassigned' => $brokerFees['unassigned'],
                'brokerFeesExcluded' => $brokerFees['excluded'],
                'feeBookings' => $brokerFees['totalCount'],
                'feeBookingsExact' => $brokerFees['exactCount'],
                'feeBookingsEstimated' => $brokerFees['estimatedCount'],
                'netProfit' => $netProfit,
                'margin' => $summary['revenue'] > 0 ? $netProfit / $summary['revenue'] : null,
                'sales' => $saleCount + count($contracts),
                'orderActions' => $brokerFees['totalCount'],
            ],
            'items' => $rows,
            'contracts' => $contracts,
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
                'brokerFeesItems' => 0.0, 'brokerFeesOpenPositions' => 0.0, 'brokerFeesUnassigned' => 0.0, 'brokerFeesExcluded' => 0.0,
                'feeBookings' => 0, 'feeBookingsExact' => 0, 'feeBookingsEstimated' => 0,
                'netProfit' => 0.0, 'margin' => null, 'sales' => 0, 'orderActions' => 0,
            ],
            'items' => [],
            'contracts' => [],
            'dataStart' => null,
        ];
    }

    // Database dates are stored in UTC (EVE time)
    private function _toTimestamp(string $date): int
    {
        return (new \DateTimeImmutable($date, new \DateTimeZone('UTC')))->getTimestamp();
    }
}
