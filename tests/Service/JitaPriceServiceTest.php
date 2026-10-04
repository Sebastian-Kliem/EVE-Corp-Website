<?php

namespace App\Tests\Service;

use App\Service\Esi\EsiClient;
use App\Service\JitaPriceService;
use PHPUnit\Framework\TestCase;

class JitaPriceServiceTest extends TestCase
{
    public function testGlobalPricesAreLoadedOncePerService(): void
    {
        $esiClient = $this->createMock(EsiClient::class);
        $esiClient->expects($this->once())->method('request')->with('GET', 'markets/prices/')->willReturn([
            ['type_id' => 34, 'average_price' => 4.2],
            ['type_id' => 35, 'average_price' => 9.1],
        ]);
        $service = new JitaPriceService($esiClient);

        $service->getGlobalPrices();
        $this->assertSame([34 => 4.2, 35 => 9.1], $service->getGlobalPrices());
    }

    public function testFailedLoadIsRetried(): void
    {
        $esiClient = $this->createMock(EsiClient::class);
        $esiClient->expects($this->exactly(2))->method('request')->willReturnOnConsecutiveCalls(
            $this->throwException(new \RuntimeException('ESI is offline')),
            [['type_id' => 34, 'average_price' => 4.2]]
        );
        $service = new JitaPriceService($esiClient);

        $this->assertSame([], $service->getGlobalPrices());
        $this->assertSame([34 => 4.2], $service->getGlobalPrices());
    }
}
