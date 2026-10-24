<?php

namespace App\Tests\Service;

use App\Repository\MarketPriceRepository;
use App\Service\Esi\EsiClient;
use App\Service\JitaPriceService;
use App\Service\Market\MarketHub;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;

class JitaPriceServiceTest extends TestCase
{
    private const NOW = '2026-10-24 12:00:00';

    public function testGlobalPricesAreLoadedOncePerService(): void
    {
        $esiClient = $this->createMock(EsiClient::class);
        $esiClient->expects($this->once())->method('request')->with('GET', 'markets/prices/')->willReturn([
            ['type_id' => 34, 'average_price' => 4.2],
            ['type_id' => 35, 'average_price' => 9.1],
        ]);
        $service = $this->_createService($esiClient, $this->createStub(MarketPriceRepository::class));

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
        $service = $this->_createService($esiClient, $this->createStub(MarketPriceRepository::class));

        $this->assertSame([], $service->getGlobalPrices());
        $this->assertSame([34 => 4.2], $service->getGlobalPrices());
    }

    public function testFreshImportedPriceIsUsedWithoutEsiRequest(): void
    {
        $esiClient = $this->createMock(EsiClient::class);
        $esiClient->expects($this->never())->method('requestAllPages');

        $repository = $this->createMock(MarketPriceRepository::class);
        $repository->method('findLastFetchedAt')->willReturn(new \DateTimeImmutable('2026-10-24 11:50:00'));
        // The second call for the same type is served from memory
        $repository->expects($this->once())->method('findStationSide')->with(MarketHub::JITA, 34, false)
            ->willReturn(['price' => '3.93', 'best' => '3.91', 'orderCount' => 39]);

        $service = $this->_createService($esiClient, $repository);
        $service->getAverageJitaPrice(34, false);
        $price = $service->getAverageJitaPrice(34, false);

        $this->assertSame(['price' => 3.93, 'count' => 39, 'warning' => false, 'message' => null], $price);
    }

    public function testFewOrdersAreFlaggedAsUncertain(): void
    {
        $repository = $this->createStub(MarketPriceRepository::class);
        $repository->method('findLastFetchedAt')->willReturn(new \DateTimeImmutable('2026-10-24 11:50:00'));
        $repository->method('findStationSide')->willReturn(['price' => '19870000.00', 'best' => '19870000.00', 'orderCount' => 3]);

        $price = $this->_createService($this->createStub(EsiClient::class), $repository)->getAverageJitaPrice(52190, false);

        $this->assertSame(19870000.0, $price['price']);
        $this->assertTrue($price['warning']);
    }

    public function testTypeWithoutJitaOrdersFallsBackToTheGlobalPrice(): void
    {
        $esiClient = $this->createStub(EsiClient::class);
        $esiClient->method('request')->willReturn([['type_id' => 44992, 'average_price' => 5100000.0]]);

        $repository = $this->createStub(MarketPriceRepository::class);
        $repository->method('findLastFetchedAt')->willReturn(new \DateTimeImmutable('2026-10-24 11:50:00'));
        $repository->method('findStationSide')->willReturn(null);

        $price = $this->_createService($esiClient, $repository)->getAverageJitaPrice(44992, false);

        $this->assertSame(5100000.0, $price['price']);
        $this->assertSame(0, $price['count']);
        $this->assertTrue($price['warning']);
    }

    public function testStaleImportFallsBackToEsi(): void
    {
        $esiClient = $this->createMock(EsiClient::class);
        // An import older than 30 minutes counts as failed
        $esiClient->expects($this->once())->method('requestAllPages')->willReturn(['data' => [], 'fromCache' => false]);
        $esiClient->method('request')->willReturn([]);

        $repository = $this->createMock(MarketPriceRepository::class);
        $repository->method('findLastFetchedAt')->willReturn(new \DateTimeImmutable('2026-10-24 11:00:00'));
        $repository->expects($this->never())->method('findStationSide');

        $this->_createService($esiClient, $repository)->getAverageJitaPrice(34, true);
    }

    private function _createService(EsiClient $esiClient, MarketPriceRepository $repository): JitaPriceService
    {
        return new JitaPriceService($esiClient, $repository, new MockClock(self::NOW, 'UTC'));
    }
}
