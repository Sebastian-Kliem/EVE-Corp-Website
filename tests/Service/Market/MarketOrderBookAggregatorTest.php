<?php

namespace App\Tests\Service\Market;

use App\Entity\Market\MarketPrice;
use App\Service\Market\MarketHub;
use App\Service\Market\MarketOrderBookAggregator;
use PHPUnit\Framework\TestCase;

class MarketOrderBookAggregatorTest extends TestCase
{
    private const REGION_THE_FORGE = 10000002;
    private const OTHER_STATION = 60003761;
    private const NOW = '2026-10-24 12:00:00';

    public function testStationRowOnlyContainsOrdersOfTheHubStation(): void
    {
        $rows = $this->_aggregate([
            $this->_order(34, false, 5.0, 100, MarketHub::JITA, '-2 days'),
            $this->_order(34, false, 4.0, 100, self::OTHER_STATION, '-2 days'),
        ]);

        $this->assertSame('4.00', $rows[MarketPrice::SCOPE_REGION]['sell_best']);
        $this->assertSame(2, $rows[MarketPrice::SCOPE_REGION]['sell_order_count']);
        $this->assertSame('5.00', $rows[MarketPrice::SCOPE_STATION]['sell_best']);
        $this->assertSame(1, $rows[MarketPrice::SCOPE_STATION]['sell_order_count']);
    }

    public function testBuyAndSellSidesAreCalculatedSeparately(): void
    {
        $rows = $this->_aggregate([
            $this->_order(34, true, 3.5, 1000, MarketHub::JITA, '-2 days'),
            $this->_order(34, true, 3.9, 500, MarketHub::JITA, '-2 days'),
            $this->_order(34, false, 4.1, 200, MarketHub::JITA, '-2 days'),
        ]);
        $station = $rows[MarketPrice::SCOPE_STATION];

        // Highest buy and lowest sell are the best prices
        $this->assertSame('3.90', $station['buy_best']);
        $this->assertSame(1500, $station['buy_volume']);
        $this->assertSame(2, $station['buy_order_count']);
        $this->assertSame('4.10', $station['sell_best']);
        $this->assertSame(200, $station['sell_volume']);
    }

    public function testPercentileAveragesTheBestFivePercentOfTheVolume(): void
    {
        // 5 % of 2000 units are 100 units: 50 at 10 ISK and 50 of the next order at 12 ISK
        $rows = $this->_aggregate([
            $this->_order(34, false, 10.0, 50, MarketHub::JITA, '-2 days'),
            $this->_order(34, false, 12.0, 950, MarketHub::JITA, '-2 days'),
            $this->_order(34, false, 20.0, 1000, MarketHub::JITA, '-2 days'),
        ]);

        $this->assertSame('11.00', $rows[MarketPrice::SCOPE_STATION]['sell_percentile']);
    }

    public function testDepthAndActivityOnlyCountOrdersNearTheBestPrice(): void
    {
        $rows = $this->_aggregate([
            $this->_order(34, false, 100.0, 10, MarketHub::JITA, '-30 minutes'),
            $this->_order(34, false, 104.0, 20, MarketHub::JITA, '-5 hours'),
            $this->_order(34, false, 104.5, 30, MarketHub::JITA, '-3 days'),
            // More than 5 % above the best price, no direct competition
            $this->_order(34, false, 106.0, 40, MarketHub::JITA, '-10 minutes'),
        ]);
        $station = $rows[MarketPrice::SCOPE_STATION];

        $this->assertSame(3, $station['sell_depth_order_count']);
        $this->assertSame(60, $station['sell_depth_volume']);
        $this->assertSame(1, $station['sell_updated1h']);
        $this->assertSame(2, $station['sell_updated24h']);
        $this->assertSame(4, $station['sell_order_count']);
    }

    public function testBestIssuedAtIsTheOldestOrderAtTheBestPrice(): void
    {
        $rows = $this->_aggregate([
            $this->_order(34, false, 4.0, 10, MarketHub::JITA, '-1 hour'),
            $this->_order(34, false, 4.0, 10, MarketHub::JITA, '-6 hours'),
        ]);

        $this->assertSame('2026-10-24 06:00:00', $rows[MarketPrice::SCOPE_STATION]['sell_best_issued_at']);
    }

    public function testSideWithoutOrdersStaysEmpty(): void
    {
        $rows = $this->_aggregate([$this->_order(34, false, 4.0, 10, MarketHub::JITA, '-1 day')]);
        $station = $rows[MarketPrice::SCOPE_STATION];

        $this->assertNull($station['buy_best']);
        $this->assertSame(0, $station['buy_order_count']);
        $this->assertSame(self::REGION_THE_FORGE, $station['region_id']);
        $this->assertSame(MarketHub::JITA, $station['location_id']);
        $this->assertSame(self::NOW, $station['fetched_at']);
    }

    /**
     * @param list<array<string, mixed>> $orders
     * @return array<string, array<string, mixed>> Scope => row of type 34
     */
    private function _aggregate(array $orders): array
    {
        $aggregator = new MarketOrderBookAggregator();
        $aggregator->start(self::REGION_THE_FORGE, MarketHub::JITA);
        $aggregator->addOrders($orders);

        $rows = [];
        foreach ($aggregator->buildRows(new \DateTimeImmutable(self::NOW)) as $row) {
            $rows[$row['scope']] = $row;
        }

        return $rows;
    }

    /**
     * @return array<string, mixed>
     */
    private function _order(int $typeId, bool $isBuy, float $price, int $volume, int $locationId, string $issuedAgo): array
    {
        return [
            'type_id' => $typeId,
            'is_buy_order' => $isBuy,
            'price' => $price,
            'volume_remain' => $volume,
            'location_id' => $locationId,
            'issued' => (new \DateTimeImmutable(self::NOW))->modify($issuedAgo)->format('Y-m-d\TH:i:s\Z'),
        ];
    }
}
