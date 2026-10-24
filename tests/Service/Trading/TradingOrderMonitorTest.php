<?php

namespace App\Tests\Service\Trading;

use App\Repository\TradingOrderCheckRepository;
use App\Service\Trading\TradingOrderMonitor;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;

class TradingOrderMonitorTest extends TestCase
{
    private function _monitor(): TradingOrderMonitor
    {
        return new TradingOrderMonitor($this->createStub(EntityManagerInterface::class), $this->createStub(TradingOrderCheckRepository::class));
    }

    private function _order(int $orderId, float $price, bool $isBuy, int $issued, int $volume = 10): array
    {
        return ['order_id' => $orderId, 'price' => $price, 'volume' => $volume, 'is_buy' => $isBuy, 'issued' => $issued];
    }

    public function testSellOrderIsOutbidByCheaperAndOlderEqualOrders(): void
    {
        $book = [
            $this->_order(1, 100.0, false, 500),
            $this->_order(2, 99.0, false, 600, 5),
            $this->_order(3, 100.0, false, 400, 7),
            $this->_order(4, 101.0, false, 300),
            // Buy side and own alt orders never count
            $this->_order(5, 200.0, true, 100),
            $this->_order(6, 50.0, false, 100),
        ];

        $position = $this->_monitor()->calculatePosition(1, false, 105.0, $book, [1 => true, 6 => true]);

        $this->assertTrue($position['inBook']);
        $this->assertSame(100.0, $position['currentPrice']);
        $this->assertSame(99.0, $position['bestCompetitorPrice']);
        $this->assertSame(2, $position['competitorsAhead']);
        $this->assertSame(12, $position['competitorVolumeAhead']);
    }

    public function testBuyOrderOnTopHasNobodyAhead(): void
    {
        $book = [
            $this->_order(1, 100.0, true, 500),
            $this->_order(2, 99.0, true, 100),
            $this->_order(3, 100.0, true, 900),
        ];

        $position = $this->_monitor()->calculatePosition(1, true, 100.0, $book, [1 => true]);

        $this->assertSame(0, $position['competitorsAhead']);
        $this->assertSame(100.0, $position['bestCompetitorPrice']);
    }

    public function testOrderMissingInTheBookFallsBackToTheSyncedPrice(): void
    {
        $position = $this->_monitor()->calculatePosition(1, false, 100.0, [$this->_order(2, 90.0, false, 100)], [1 => true]);

        $this->assertFalse($position['inBook']);
        $this->assertNull($position['currentPrice']);
        $this->assertSame(1, $position['competitorsAhead']);
    }
}
