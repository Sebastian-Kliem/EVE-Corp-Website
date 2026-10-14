<?php

namespace App\Tests\Service;

use App\Entity\Orders\CorpOrder;
use App\Entity\Orders\CorpOrderItem;
use App\Service\ItemParserService;
use App\Service\JitaPriceService;
use App\Service\OrderService;
use App\Service\SdeService;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;

class OrderServiceTest extends TestCase
{
    private OrderService $service;

    protected function setUp(): void
    {
        $this->service = new OrderService(
            $this->createStub(EntityManagerInterface::class),
            $this->createStub(ItemParserService::class),
            $this->createStub(JitaPriceService::class),
            $this->createStub(SdeService::class)
        );
    }

    public function testExactOrderIdMatches(): void
    {
        $this->assertTrue($this->service->contractTitleMatchesOrder('Order #1', 1));
        $this->assertTrue($this->service->contractTitleMatchesOrder('WH-Order #42 Minerals', 42));
        $this->assertTrue($this->service->contractTitleMatchesOrder('order #7', 7));
    }

    public function testLongerOrderIdDoesNotMatch(): void
    {
        $this->assertFalse($this->service->contractTitleMatchesOrder('Order #12', 1));
        $this->assertFalse($this->service->contractTitleMatchesOrder('WH-Order #100', 10));
    }

    public function testLivePricesAreOnlyCalculatedForOpenOrders(): void
    {
        $jitaPriceService = $this->createMock(JitaPriceService::class);
        // Only the open order may trigger an ESI price lookup
        $jitaPriceService->expects($this->once())
            ->method('getAverageJitaPrice')
            ->with(34, false)
            ->willReturn(['price' => 6.0]);

        $service = new OrderService(
            $this->createStub(EntityManagerInterface::class),
            $this->createStub(ItemParserService::class),
            $jitaPriceService,
            $this->createStub(SdeService::class)
        );

        $openOrder = $this->_createOrder(1, CorpOrder::STATUS_OPEN);
        $acceptedOrder = $this->_createOrder(2, CorpOrder::STATUS_IN_PROGRESS);

        $livePrices = $service->getLivePrices([$openOrder, $acceptedOrder]);

        // 100 units at 6 ISK and 90 % against a snapshot of 500 ISK
        $this->assertSame([1 => ['liveTotalPrice' => 540.0, 'priceDiff' => 40.0, 'priceDiffPercent' => 8.0]], $livePrices);
    }

    public function testMissingTitleDoesNotMatch(): void
    {
        $this->assertFalse($this->service->contractTitleMatchesOrder(null, 1));
        $this->assertFalse($this->service->contractTitleMatchesOrder('Fuel delivery', 1));
    }

    private function _createOrder(int $orderId, string $status): CorpOrder
    {
        $order = new CorpOrder();
        $order->setType(CorpOrder::TYPE_BUY);
        $order->setStatus($status);
        $order->setPercentToJita(90);
        $order->setTotalPrice('500.00');

        $item = new CorpOrderItem();
        $item->setTypeId(34);
        $item->setAmount(100);
        $item->setTotalPrice('500.00');
        $order->addItem($item);

        (new \ReflectionProperty(CorpOrder::class, 'id'))->setValue($order, $orderId);

        return $order;
    }
}
