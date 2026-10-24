<?php

namespace App\Tests\Service\Market;

use App\Repository\MarketHistoryRepository;
use App\Service\Market\MarketHistoryImporter;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

class MarketHistoryImporterTest extends TestCase
{
    private const HEADER = "average,date,highest,lowest,order_count,volume,http_last_modified,region_id,type_id\n";

    private string $archiveDirectory;

    protected function setUp(): void
    {
        $this->archiveDirectory = sys_get_temp_dir() . '/wh-toolbox-history-test-' . uniqid();
    }

    protected function tearDown(): void
    {
        exec('rm -rf ' . escapeshellarg($this->archiveDirectory));
    }

    public function testOnlyChangedDaysAreImportedAndOldDaysOnlyForHubRegions(): void
    {
        $recentDay = self::HEADER
            . "3.93,2026-10-22,3.94,3.90,2685,4600242037,2026-10-23T11:05:37Z,10000002,34\n"
            . "550.0,2026-10-22,550.0,550.0,1,8000,2026-10-23T11:05:37Z,10000001,20\n";
        $oldDay = self::HEADER
            . "4.10,2026-06-01,4.20,4.00,2000,3000000000,2026-06-02T11:05:37Z,10000002,34\n"
            . "600.0,2026-06-01,600.0,600.0,1,10,2026-06-02T11:05:37Z,10000001,20\n";

        $requestedUrls = [];
        $httpClient = new MockHttpClient(function (string $method, string $url) use (&$requestedUrls, $recentDay, $oldDay): MockResponse {
            $requestedUrls[] = $url;
            if (str_ends_with($url, 'totals.json')) {
                // 2026-10-21 was imported with this row count before and is skipped
                return new MockResponse('{"2026-06-01":2,"2026-10-21":5,"2026-10-22":2,"2026-10-23":0,"2025-01-01":9}');
            }

            return new MockResponse(bzcompress(str_contains($url, '2026-10-22') ? $recentDay : $oldDay));
        });

        mkdir($this->archiveDirectory, 0775, true);
        file_put_contents($this->archiveDirectory . '/import-state.json', '{"2026-10-21":5}');

        $writtenRows = [];
        $repository = $this->createStub(MarketHistoryRepository::class);
        $repository->method('upsertRows')->willReturnCallback(function (iterable $rows) use (&$writtenRows): int {
            $count = 0;
            foreach ($rows as $row) {
                $writtenRows[] = $row;
                $count++;
            }
            return $count;
        });

        $importer = new MarketHistoryImporter($httpClient, $repository, new MockClock('2026-10-24 12:00:00', 'UTC'), $this->archiveDirectory);
        $importedDays = $importer->importPendingDays(10);

        // Newest first; 2025-01-01 is beyond the retention, 2026-10-23 has no rows yet
        $this->assertSame([['date' => '2026-10-22', 'rows' => 2], ['date' => '2026-06-01', 'rows' => 1]], $importedDays);
        $this->assertSame(10000001, $writtenRows[1]['region_id']);
        // The old day keeps only The Forge, the non hub region 10000001 is beyond its 90 days
        $this->assertSame([10000002, '2026-06-01', 3000000000], [$writtenRows[2]['region_id'], $writtenRows[2]['date'], $writtenRows[2]['volume']]);
        $this->assertCount(3, $writtenRows);
        $this->assertFileExists($this->archiveDirectory . '/2026/market-history-2026-10-22.csv.bz2');
        $this->assertSame(['2026-06-01' => 2, '2026-10-21' => 5, '2026-10-22' => 2], json_decode((string)file_get_contents($this->archiveDirectory . '/import-state.json'), true));
        $this->assertCount(3, $requestedUrls);
    }
}
