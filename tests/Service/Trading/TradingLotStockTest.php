<?php

namespace App\Tests\Service\Trading;

use App\Service\Trading\TradingLotStock;
use PHPUnit\Framework\TestCase;

class TradingLotStockTest extends TestCase
{
    public function testSaleTakesTheNewestLotFirst(): void
    {
        $stock = new TradingLotStock();
        $stock->add(442, 100, 180000.0, 1000);
        $stock->add(442, 10, 2000.0, 5000);

        $taken = $stock->take(442, 10, 5100);

        $this->assertEqualsWithDelta(10.0, $taken['quantity'], 0.001);
        $this->assertEqualsWithDelta(20000.0, $taken['cost'], 0.001);
        $this->assertEqualsWithDelta(1000.0, $taken['holdingSeconds'], 0.001);
    }

    public function testSaleSpanningLotsLeavesTheOldestRemainder(): void
    {
        $stock = new TradingLotStock();
        $stock->add(442, 5, 100.0, 1000);
        $stock->add(442, 3, 10.0, 2000);

        $taken = $stock->take(442, 4, 3000);
        $remaining = $stock->remaining();

        $this->assertEqualsWithDelta(130.0, $taken['cost'], 0.001);
        $this->assertEqualsWithDelta(4.0, $remaining[442]['quantity'], 0.001);
        $this->assertEqualsWithDelta(400.0, $remaining[442]['cost'], 0.001);
        $this->assertSame(1000, $remaining[442]['oldestAcquiredAt']);
    }

    public function testSaleBeyondStockOnlyTakesWhatWasBought(): void
    {
        $stock = new TradingLotStock();
        $stock->add(442, 2, 50.0, 1000);

        $taken = $stock->take(442, 5, 2000);

        $this->assertEqualsWithDelta(2.0, $taken['quantity'], 0.001);
        $this->assertSame([], $stock->remaining());
    }

    public function testUnknownTypeTakesNothing(): void
    {
        $taken = (new TradingLotStock())->take(1, 3, 1000);

        $this->assertSame(0.0, $taken['quantity']);
        $this->assertSame(0.0, $taken['cost']);
    }
}
