<?php

namespace App\Tests\Service\Cron;

use App\Repository\PublicContractRepository;
use App\Service\Cron\CronLogWriter;
use App\Service\Cron\UpdatePublicContractsTask;
use App\Service\Esi\EsiClient;
use App\Service\SdeService;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\Clock\MockClock;

class UpdatePublicContractsTaskTest extends TestCase
{
    public function testOnlyItemExchangeContractsAreStoredAndMissingOnesMarkedGone(): void
    {
        $esiClient = $this->_esiClientListing([
            ['contract_id' => 11, 'type' => 'item_exchange', 'issuer_id' => 1, 'issuer_corporation_id' => 2, 'price' => 1000.5, 'volume' => 10.0, 'title' => '', 'date_issued' => '2026-10-20T10:00:00Z', 'date_expired' => '2026-11-20T10:00:00Z', 'start_location_id' => 60003760],
            ['contract_id' => 12, 'type' => 'courier', 'issuer_id' => 1, 'issuer_corporation_id' => 2, 'date_issued' => '2026-10-20T10:00:00Z', 'date_expired' => '2026-11-20T10:00:00Z'],
        ], true);

        $storedRows = [];
        $repository = $this->createMock(PublicContractRepository::class);
        $repository->method('upsertSeen')->willReturnCallback(function (array $rows) use (&$storedRows): void {
            $storedRows = $rows;
        });
        $repository->expects($this->once())->method('markGone')->with(10000002)->willReturn(3);
        $repository->method('findContractIdsWithoutItems')->willReturn([]);

        $this->_createTask($esiClient, $repository)->execute();

        $this->assertCount(1, $storedRows);
        $this->assertSame(11, $storedRows[0]['contract_id']);
        $this->assertSame('1000.50', $storedRows[0]['price']);
        $this->assertNull($storedRows[0]['title']);
        $this->assertSame('2026-10-20 10:00:00', $storedRows[0]['date_issued']);
    }

    public function testListFromDifferentSnapshotsMarksNothingAsGone(): void
    {
        $repository = $this->createMock(PublicContractRepository::class);
        $repository->expects($this->never())->method('markGone');
        $repository->method('findContractIdsWithoutItems')->willReturn([]);

        $this->_createTask($this->_esiClientListing([], false), $repository)->execute();
    }

    private function _esiClientListing(array $contracts, bool $isConsistent): EsiClient
    {
        $esiClient = $this->createStub(EsiClient::class);
        $esiClient->method('streamPublicPages')->willReturnCallback(function (string $path, array $query, callable $onPage) use ($contracts, $isConsistent): array {
            $onPage($contracts, 1);
            return ['pages' => 1, 'expiresAt' => null, 'consistent' => $isConsistent];
        });

        return $esiClient;
    }

    private function _createTask(EsiClient $esiClient, PublicContractRepository $repository): UpdatePublicContractsTask
    {
        $sdeService = $this->createStub(SdeService::class);
        $sdeService->method('getKnownSpaceRegionIds')->willReturn([10000002]);

        return new UpdatePublicContractsTask(
            $esiClient,
            $repository,
            $sdeService,
            new ArrayAdapter(),
            new MockClock('2026-10-24 12:00:00', 'UTC'),
            new CronLogWriter(sys_get_temp_dir() . '/wh-toolbox-test-cron.log', 'error'),
            new NullLogger()
        );
    }
}
