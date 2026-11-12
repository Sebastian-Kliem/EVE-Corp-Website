<?php

namespace App\Tests\Service;

use App\Entity\Orders\CorpOrder;
use App\Entity\Orders\CorpOrderItem;
use App\Entity\User;
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

    public function testPartialFulfillSplitsItem(): void
    {
        $order = $this->_createOrder(1, CorpOrder::STATUS_OPEN);
        $item = $order->getItems()->first();
        $supplier = new User();

        $this->service->fulfillItem($item, $supplier, true, 30);

        $this->assertCount(2, $order->getItems());
        $this->assertSame(70, $item->getAmount());
        $this->assertFalse($item->isFulfilled());
        $this->assertSame('315.00', $item->getTotalPrice());

        $splitItem = $order->getItems()->last();
        $this->assertSame(30, $splitItem->getAmount());
        $this->assertSame($supplier, $splitItem->getFulfiller());
        $this->assertSame(CorpOrderItem::DELIVERY_ACCEPTED, $splitItem->getDeliveryStatus());
        $this->assertSame('135.00', $splitItem->getTotalPrice());
        $this->assertSame(CorpOrder::STATUS_IN_PROGRESS, $order->getStatus());
    }

    public function testSecondPartOfSameSupplierIsMerged(): void
    {
        $order = $this->_createOrder(1, CorpOrder::STATUS_OPEN);
        $item = $order->getItems()->first();
        $supplier = new User();

        $this->service->fulfillItem($item, $supplier, true, 30);
        $this->service->fulfillItem($item, $supplier, true, 20);

        $this->assertCount(2, $order->getItems());
        $this->assertSame(50, $item->getAmount());
        $this->assertSame(50, $order->getItems()->last()->getAmount());
    }

    public function testReleasedPartIsMergedBackIntoOpenItem(): void
    {
        $order = $this->_createOrder(1, CorpOrder::STATUS_OPEN);
        $item = $order->getItems()->first();

        $this->service->fulfillItem($item, new User(), true, 30);
        $this->service->fulfillItem($order->getItems()->last(), new User(), false);

        $this->assertCount(1, $order->getItems());
        $this->assertSame(100, $item->getAmount());
        $this->assertSame('450.00', $item->getTotalPrice());
        $this->assertSame(CorpOrder::STATUS_OPEN, $order->getStatus());
    }

    public function testUnacceptKeepsDeliveredItems(): void
    {
        $order = $this->_createOrder(1, CorpOrder::STATUS_OPEN);
        $item = $order->getItems()->first();
        $supplier = new User();

        $this->service->fulfillItem($item, $supplier, true, 30);
        $deliveredItem = $order->getItems()->last();
        $deliveredItem->setDeliveryStatus(CorpOrderItem::DELIVERY_DELIVERED);
        $this->service->fulfillItem($item, $supplier, true);

        $this->service->unacceptOrder($order, $supplier);

        $this->assertCount(2, $order->getItems());
        $this->assertSame(CorpOrderItem::DELIVERY_DELIVERED, $deliveredItem->getDeliveryStatus());
        $this->assertFalse($item->isFulfilled());
    }

    public function testSupplierChangesOwnStatusUntilReceived(): void
    {
        [$item, $customer, $supplier] = $this->_createAcceptedItem(CorpOrder::TYPE_BUY);

        $this->assertTrue($this->service->canChangeDeliveryStatus($item, $supplier, CorpOrderItem::DELIVERY_MANUFACTURING, false));
        $this->assertTrue($this->service->canChangeDeliveryStatus($item, $supplier, CorpOrderItem::DELIVERY_DELIVERED, false));
        $this->assertFalse($this->service->canChangeDeliveryStatus($item, $supplier, CorpOrderItem::DELIVERY_RECEIVED, false));
        $this->assertFalse($this->service->canChangeDeliveryStatus($item, new User(), CorpOrderItem::DELIVERY_DELIVERED, false));

        $item->setDeliveryStatus(CorpOrderItem::DELIVERY_RECEIVED);
        $this->assertFalse($this->service->canChangeDeliveryStatus($item, $supplier, CorpOrderItem::DELIVERY_DELIVERED, false));
    }

    public function testCustomerConfirmsAndRevertsReceipt(): void
    {
        [$item, $customer] = $this->_createAcceptedItem(CorpOrder::TYPE_BUY);

        $this->assertTrue($this->service->canChangeDeliveryStatus($item, $customer, CorpOrderItem::DELIVERY_RECEIVED, false));
        $this->assertFalse($this->service->canChangeDeliveryStatus($item, $customer, CorpOrderItem::DELIVERY_DELIVERED, false));

        $item->setDeliveryStatus(CorpOrderItem::DELIVERY_RECEIVED);
        $this->assertTrue($this->service->canChangeDeliveryStatus($item, $customer, CorpOrderItem::DELIVERY_DELIVERED, false));
    }

    public function testSellOrdersHaveNoIntermediateStatus(): void
    {
        [$item, , $supplier] = $this->_createAcceptedItem(CorpOrder::TYPE_SELL);

        $this->assertFalse($this->service->canChangeDeliveryStatus($item, $supplier, CorpOrderItem::DELIVERY_BUY_ORDER_PLACED, true));
        $this->assertTrue($this->service->canChangeDeliveryStatus($item, $supplier, CorpOrderItem::DELIVERY_DELIVERED, false));
    }

    public function testArchivingKeepsOpenItemsOpen(): void
    {
        $order = $this->_createOrder(1, CorpOrder::STATUS_OPEN);
        $item = $order->getItems()->first();

        $this->service->completeOrder($order);

        $this->assertSame(CorpOrder::STATUS_FULFILLED, $order->getStatus());
        $this->assertFalse($item->isFulfilled());
        $this->assertNull($item->getFulfiller());
    }

    public function testCustomPriceIsSpreadOverItemsByJitaValue(): void
    {
        $items = [
            ['typeId' => 34, 'name' => 'Tritanium', 'quantity' => 100, 'unitPrice' => 5.0, 'unitVolume' => 0.01],
            ['typeId' => 35, 'name' => 'Pyerite', 'quantity' => 50, 'unitPrice' => 30.0, 'unitVolume' => 0.01],
        ];

        $order = $this->service->createOrder(new User(), CorpOrder::TYPE_SELL, 'Fit', 90, null, $items, false, null, true, 1000.0);

        // Jita value 2000 ISK, fixed price 1000 ISK
        $this->assertTrue($order->isCompleteOnly());
        $this->assertSame('1000.00', $order->getTotalPrice());
        $this->assertSame(50, $order->getPercentToJita());
        $this->assertSame('250.00', $order->getItems()->first()->getTotalPrice());
        $this->assertSame('750.00', $order->getItems()->last()->getTotalPrice());

        $this->service->acceptOrder($order, new User());
        $this->assertSame('1000.00', $order->getTotalPrice());
    }

    public function testCustomPriceIsIgnoredWithoutCompleteOnly(): void
    {
        $items = [['typeId' => 34, 'name' => 'Tritanium', 'quantity' => 100, 'unitPrice' => 5.0, 'unitVolume' => 0.01]];

        $order = $this->service->createOrder(new User(), CorpOrder::TYPE_SELL, 'Loot', 90, null, $items, false, null, false, 1000.0);

        $this->assertNull($order->getCustomPrice());
        $this->assertSame('450.00', $order->getTotalPrice());
    }

    /**
     * @return array{CorpOrderItem, User, User}
     */
    private function _createAcceptedItem(string $type): array
    {
        $customer = new User();
        $supplier = new User();
        $order = $this->_createOrder(1, CorpOrder::STATUS_OPEN);
        $order->setType($type);
        $order->setUser($customer);
        $item = $order->getItems()->first();
        $this->service->fulfillItem($item, $supplier);

        return [$item, $customer, $supplier];
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
        $item->setName('Tritanium');
        $item->setAmount(100);
        $item->setUnitPrice('5.00');
        $item->setTotalPrice('500.00');
        $order->addItem($item);

        (new \ReflectionProperty(CorpOrder::class, 'id'))->setValue($order, $orderId);

        return $order;
    }
}
