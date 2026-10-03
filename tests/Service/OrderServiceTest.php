<?php

namespace App\Tests\Service;

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
            $this->createMock(EntityManagerInterface::class),
            $this->createMock(ItemParserService::class),
            $this->createMock(JitaPriceService::class),
            $this->createMock(SdeService::class)
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

    public function testMissingTitleDoesNotMatch(): void
    {
        $this->assertFalse($this->service->contractTitleMatchesOrder(null, 1));
        $this->assertFalse($this->service->contractTitleMatchesOrder('Fuel delivery', 1));
    }
}
