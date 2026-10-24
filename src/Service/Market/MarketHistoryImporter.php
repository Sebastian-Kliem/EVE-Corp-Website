<?php

namespace App\Service\Market;

use App\Entity\Market\MarketHistory;
use App\Repository\MarketHistoryRepository;
use Psr\Clock\ClockInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Imports the daily market history dumps of EVE Ref (same data as ESI, one file per day for all regions).
 * The downloaded files are kept as archive, so the history survives if EVE Ref goes away.
 */
class MarketHistoryImporter
{
    private const BASE_URL = 'https://data.everef.net/market-history/';
    private const USER_AGENT = 'WH-Toolbox/1.0 (Contact: Sebastian Kliem)';
    // EVE Ref publishes the rows per day here; a day changes while it is still being completed
    private const TOTALS_FILE = 'totals.json';
    private const STATE_FILE = 'import-state.json';

    private const CSV_COLUMNS = ['average', 'date', 'highest', 'lowest', 'order_count', 'volume', 'region_id', 'type_id'];

    public function __construct(
        private readonly HttpClientInterface $httpClient,
        private readonly MarketHistoryRepository $marketHistoryRepository,
        private readonly ClockInterface $clock,
        #[Autowire('%kernel.project_dir%/var/market-history')]
        private readonly string $archiveDirectory
    ) {}

    /**
     * Imports every day within the retention whose row count at EVE Ref differs from the last import, newest first.
     *
     * @return list<array{date: string, rows: int}> Imported days
     */
    public function importPendingDays(int $maxDays): array
    {
        $totals = $this->_loadTotals();
        $state = $this->_loadState();

        $importedDays = [];
        foreach ($this->_pendingDates($totals, $state) as $date) {
            if (count($importedDays) >= $maxDays) {
                break;
            }
            $importedDays[] = ['date' => $date, 'rows' => $this->_importDay($date)];
            $state[$date] = $totals[$date];
            // Saved after every day, an aborted run does not repeat finished days
            $this->_saveState($state);
        }

        return $importedDays;
    }

    public function deleteExpired(): int
    {
        $today = $this->_today();

        return $this->marketHistoryRepository->deleteExpired(
            MarketHub::regionIds(),
            $today->modify(sprintf('-%d days', MarketHistory::RETENTION_DAYS_HUB_REGION)),
            $today->modify(sprintf('-%d days', MarketHistory::RETENTION_DAYS_OTHER_REGION))
        );
    }

    /**
     * @param array<string, int> $totals
     * @param array<string, int> $state
     * @return list<string> Dates (Y-m-d), newest first
     */
    private function _pendingDates(array $totals, array $state): array
    {
        $oldestDate = $this->_today()->modify(sprintf('-%d days', MarketHistory::RETENTION_DAYS_HUB_REGION))->format('Y-m-d');

        $pendingDates = [];
        foreach ($totals as $date => $rowCount) {
            if ($date < $oldestDate || $rowCount <= 0) {
                continue;
            }
            if (($state[$date] ?? null) !== $rowCount) {
                $pendingDates[] = $date;
            }
        }
        rsort($pendingDates);

        return $pendingDates;
    }

    private function _importDay(string $date): int
    {
        $file = $this->_downloadDay($date);
        $allRegions = $date >= $this->_today()->modify(sprintf('-%d days', MarketHistory::RETENTION_DAYS_OTHER_REGION))->format('Y-m-d');

        return $this->marketHistoryRepository->upsertRows($this->_readRows($file, $allRegions));
    }

    private function _downloadDay(string $date): string
    {
        $relativePath = sprintf('%s/market-history-%s.csv.bz2', substr($date, 0, 4), $date);
        $targetFile = $this->archiveDirectory . '/' . $relativePath;
        if (!is_dir(dirname($targetFile))) {
            mkdir(dirname($targetFile), 0775, true);
        }

        $content = $this->httpClient->request('GET', self::BASE_URL . $relativePath, [
            'headers' => ['User-Agent' => self::USER_AGENT],
        ])->getContent();

        // Written next to the archive file first, a failed download never replaces a good file
        $temporaryFile = $targetFile . '.part';
        file_put_contents($temporaryFile, $content);
        rename($temporaryFile, $targetFile);

        return $targetFile;
    }

    /**
     * @return \Generator<int, array<string, mixed>>
     */
    private function _readRows(string $file, bool $allRegions): \Generator
    {
        $handle = fopen('compress.bzip2://' . $file, 'r');
        if ($handle === false) {
            throw new \RuntimeException(sprintf('Cannot open the market history file %s.', $file));
        }

        try {
            $header = fgetcsv($handle, escape: '');
            if ($header === false) {
                return;
            }
            $columnIndexes = $this->_columnIndexes($header);

            while (($values = fgetcsv($handle, escape: '')) !== false) {
                $regionId = (int)$values[$columnIndexes['region_id']];
                if (!$allRegions && !MarketHub::isHubRegion($regionId)) {
                    continue;
                }

                yield [
                    'region_id' => $regionId,
                    'type_id' => (int)$values[$columnIndexes['type_id']],
                    'date' => $values[$columnIndexes['date']],
                    'average' => $values[$columnIndexes['average']],
                    'highest' => $values[$columnIndexes['highest']],
                    'lowest' => $values[$columnIndexes['lowest']],
                    'volume' => (int)$values[$columnIndexes['volume']],
                    'order_count' => (int)$values[$columnIndexes['order_count']],
                ];
            }
        } finally {
            fclose($handle);
        }
    }

    /**
     * @param list<string|null> $header
     * @return array<string, int> Column => index
     */
    private function _columnIndexes(array $header): array
    {
        $columnIndexes = [];
        foreach (self::CSV_COLUMNS as $column) {
            $index = array_search($column, $header, true);
            if ($index === false) {
                throw new \RuntimeException(sprintf('Column %s is missing in the market history file.', $column));
            }
            $columnIndexes[$column] = $index;
        }

        return $columnIndexes;
    }

    /**
     * @return array<string, int> Date => row count
     */
    private function _loadTotals(): array
    {
        $totals = $this->httpClient->request('GET', self::BASE_URL . self::TOTALS_FILE, [
            'headers' => ['User-Agent' => self::USER_AGENT],
        ])->toArray();

        $rowCounts = [];
        foreach ($totals as $date => $rowCount) {
            $rowCounts[(string)$date] = (int)$rowCount;
        }

        return $rowCounts;
    }

    /**
     * @return array<string, int> Date => row count of the last import
     */
    private function _loadState(): array
    {
        $stateFile = $this->archiveDirectory . '/' . self::STATE_FILE;
        if (!is_file($stateFile)) {
            return [];
        }

        $state = json_decode((string)file_get_contents($stateFile), true);

        return is_array($state) ? $state : [];
    }

    /**
     * @param array<string, int> $state
     */
    private function _saveState(array $state): void
    {
        if (!is_dir($this->archiveDirectory)) {
            mkdir($this->archiveDirectory, 0775, true);
        }
        ksort($state);
        file_put_contents($this->archiveDirectory . '/' . self::STATE_FILE, json_encode($state, JSON_PRETTY_PRINT));
    }

    private function _today(): \DateTimeImmutable
    {
        return $this->clock->now()->setTimezone(new \DateTimeZone('UTC'))->setTime(0, 0);
    }
}
