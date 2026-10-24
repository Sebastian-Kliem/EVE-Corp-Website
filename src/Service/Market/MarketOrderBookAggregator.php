<?php

namespace App\Service\Market;

use App\Entity\Market\MarketPrice;

/**
 * Condenses the order book of one region into MarketPrice rows for the region and its hub station.
 * Orders are kept as packed binary strings (20 bytes each) because a full Jita order book as PHP arrays would exceed the CLI memory limit.
 */
class MarketOrderBookAggregator
{
    // Price and remaining volume as doubles, issued as unsigned 32 bit timestamp
    private const PACK_FORMAT = 'dPrice/dVolume/VIssued';
    private const PACK_CODE = 'ddV';
    private const PACKED_ORDER_SIZE = 20;

    // Best share of the volume that the percentile price averages over
    private const PERCENTILE_VOLUME_SHARE = 0.05;

    private int $regionId = 0;
    private ?int $hubStationId = null;

    /** @var array<string, array<string, string>> "scope|locationId|typeId" => side => packed orders */
    private array $packedOrders = [];
    private int $orderCount = 0;

    public function start(int $regionId, ?int $hubStationId): void
    {
        $this->regionId = $regionId;
        $this->hubStationId = $hubStationId;
        $this->packedOrders = [];
        $this->orderCount = 0;
    }

    /**
     * @param array<int, array<string, mixed>> $orders ESI market orders of one page
     */
    public function addOrders(array $orders): void
    {
        foreach ($orders as $order) {
            $side = !empty($order['is_buy_order']) ? 'buy' : 'sell';
            $typeId = (int)$order['type_id'];
            $packedOrder = pack(self::PACK_CODE, (float)$order['price'], (float)$order['volume_remain'], $this->_toTimestamp((string)$order['issued']));

            $this->_appendOrder(MarketPrice::SCOPE_REGION . '|' . $this->regionId . '|' . $typeId, $side, $packedOrder);
            if ($this->hubStationId !== null && (int)$order['location_id'] === $this->hubStationId) {
                $this->_appendOrder(MarketPrice::SCOPE_STATION . '|' . $this->hubStationId . '|' . $typeId, $side, $packedOrder);
            }
            $this->orderCount++;
        }
    }

    // Appending in place keeps large types (thousands of orders) from being copied on every order
    private function _appendOrder(string $rowKey, string $side, string $packedOrder): void
    {
        if (isset($this->packedOrders[$rowKey][$side])) {
            $this->packedOrders[$rowKey][$side] .= $packedOrder;
        } else {
            $this->packedOrders[$rowKey][$side] = $packedOrder;
        }
    }

    public function getOrderCount(): int
    {
        return $this->orderCount;
    }

    /**
     * Rows for the market_price table, one per scope, location and type. Built one at a time,
     * all rows of The Forge as PHP arrays at once would again exceed the memory limit.
     *
     * @return \Generator<int, array<string, mixed>>
     */
    public function buildRows(\DateTimeImmutable $fetchedAt): \Generator
    {
        foreach (array_keys($this->packedOrders) as $rowKey) {
            [$scope, $locationId, $typeId] = explode('|', $rowKey);
            $row = $this->_emptyRow($scope, (int)$locationId, (int)$typeId, $fetchedAt);

            foreach ($this->packedOrders[$rowKey] as $side => $packedOrders) {
                $orders = $this->_unpackSorted($packedOrders, $side === 'buy');
                foreach ($this->_calculateSide($orders, $side === 'buy', $fetchedAt->getTimestamp()) as $column => $value) {
                    $row[$side . '_' . $column] = $value;
                }
            }
            // Free each type as soon as it is condensed
            unset($this->packedOrders[$rowKey]);

            yield $row;
        }
    }

    /**
     * @return list<array{price: float, volume: float, issued: int}> Best price first
     */
    private function _unpackSorted(string $packedOrders, bool $isBuy): array
    {
        $orders = [];
        $orderTotal = intdiv(strlen($packedOrders), self::PACKED_ORDER_SIZE);
        for ($index = 0; $index < $orderTotal; $index++) {
            $values = unpack(self::PACK_FORMAT, $packedOrders, $index * self::PACKED_ORDER_SIZE);
            $orders[] = ['price' => $values['Price'], 'volume' => $values['Volume'], 'issued' => $values['Issued']];
        }

        usort($orders, function (array $first, array $second) use ($isBuy): int {
            $byPrice = $isBuy ? $second['price'] <=> $first['price'] : $first['price'] <=> $second['price'];
            // Among equal prices the oldest order first, so best_issued_at tells since when the best price stands
            return $byPrice !== 0 ? $byPrice : $first['issued'] <=> $second['issued'];
        });

        return $orders;
    }

    /**
     * @param list<array{price: float, volume: float, issued: int}> $orders Best price first
     * @return array<string, mixed> Column suffix => value
     */
    private function _calculateSide(array $orders, bool $isBuy, int $now): array
    {
        $bestPrice = $orders[0]['price'];
        $depthLimit = $isBuy ? $bestPrice * (1 - MarketPrice::DEPTH_PRICE_RANGE) : $bestPrice * (1 + MarketPrice::DEPTH_PRICE_RANGE);

        $totalVolume = 0.0;
        foreach ($orders as $order) {
            $totalVolume += $order['volume'];
        }

        $depthVolume = 0.0;
        $depthOrderCount = 0;
        $updatedLastHour = 0;
        $updatedLastDay = 0;
        foreach ($orders as $order) {
            $isInDepth = $isBuy ? $order['price'] >= $depthLimit : $order['price'] <= $depthLimit;
            if (!$isInDepth) {
                // Sorted by price, every following order is further away
                break;
            }
            $depthVolume += $order['volume'];
            $depthOrderCount++;
            if ($order['issued'] >= $now - 3600) {
                $updatedLastHour++;
            }
            if ($order['issued'] >= $now - 86400) {
                $updatedLastDay++;
            }
        }

        return [
            'best' => $this->_formatPrice($bestPrice),
            'percentile' => $this->_formatPrice($this->_calculatePercentilePrice($orders, $totalVolume)),
            'volume' => (int)round($totalVolume),
            'order_count' => count($orders),
            'depth_volume' => (int)round($depthVolume),
            'depth_order_count' => $depthOrderCount,
            'updated1h' => $updatedLastHour,
            'updated24h' => $updatedLastDay,
            'best_issued_at' => date('Y-m-d H:i:s', $orders[0]['issued']),
        ];
    }

    /**
     * Volume weighted average price of the best 5 % of the volume, robust against single bait orders.
     *
     * @param list<array{price: float, volume: float, issued: int}> $orders Best price first
     */
    private function _calculatePercentilePrice(array $orders, float $totalVolume): float
    {
        $targetVolume = max($totalVolume * self::PERCENTILE_VOLUME_SHARE, 1.0);
        $collectedVolume = 0.0;
        $collectedValue = 0.0;
        foreach ($orders as $order) {
            $takenVolume = min($order['volume'], $targetVolume - $collectedVolume);
            $collectedVolume += $takenVolume;
            $collectedValue += $takenVolume * $order['price'];
            if ($collectedVolume >= $targetVolume) {
                break;
            }
        }

        return $collectedVolume > 0 ? $collectedValue / $collectedVolume : $orders[0]['price'];
    }

    /**
     * @return array<string, mixed>
     */
    private function _emptyRow(string $scope, int $locationId, int $typeId, \DateTimeImmutable $fetchedAt): array
    {
        $row = [
            'scope' => $scope,
            'location_id' => $locationId,
            'region_id' => $this->regionId,
            'type_id' => $typeId,
        ];
        foreach (['buy', 'sell'] as $side) {
            $row[$side . '_best'] = null;
            $row[$side . '_percentile'] = null;
            $row[$side . '_volume'] = 0;
            $row[$side . '_order_count'] = 0;
            $row[$side . '_depth_volume'] = 0;
            $row[$side . '_depth_order_count'] = 0;
            $row[$side . '_updated1h'] = 0;
            $row[$side . '_updated24h'] = 0;
            $row[$side . '_best_issued_at'] = null;
        }
        $row['fetched_at'] = $fetchedAt->format('Y-m-d H:i:s');

        return $row;
    }

    private function _formatPrice(float $price): string
    {
        return number_format($price, 2, '.', '');
    }

    private function _toTimestamp(string $issued): int
    {
        $timestamp = strtotime($issued);

        return $timestamp === false ? 0 : $timestamp;
    }
}
