<?php

namespace App\Service\Cron;

use App\Repository\MarketTypeStatsRepository;
use App\Service\Market\MarketHistoryImporter;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;

/**
 * Keeps market_history in sync with the EVE Ref dumps: new and still growing days, the initial backfill and the retention.
 */
class UpdateMarketHistoryTask implements CronTaskInterface
{
    // Limits one run to a few minutes, the initial backfill of 400 days spreads over several hourly runs
    private const MAX_DAYS_PER_RUN = 60;

    public function __construct(
        private readonly MarketHistoryImporter $importer,
        private readonly MarketTypeStatsRepository $typeStatsRepository,
        private readonly ClockInterface $clock,
        private readonly CronLogWriter $cronLogWriter,
        private readonly LoggerInterface $logger
    ) {}

    public function getCommandName(): string
    {
        return 'market:import-history';
    }

    public function execute(): void
    {
        $startTime = microtime(true);
        $importedDays = $this->importer->importPendingDays(self::MAX_DAYS_PER_RUN);
        $deletedRows = $this->importer->deleteExpired();

        $statsRows = null;
        if ($importedDays !== [] || $this->typeStatsRepository->isEmpty()) {
            $statsRows = $this->typeStatsRepository->rebuild($this->clock->now());
        }

        if ($importedDays === [] && $deletedRows === 0 && $statsRows === null) {
            return;
        }

        $writtenRows = 0;
        foreach ($importedDays as $importedDay) {
            $writtenRows += $importedDay['rows'];
        }
        $dateRange = $importedDays === [] ? '' : sprintf(' (%s to %s)', end($importedDays)['date'], $importedDays[0]['date']);

        $message = sprintf(
            '[Market] History: %d days imported%s, %d rows written, %d expired rows deleted%s in %.1f s (peak memory %d MB).',
            count($importedDays),
            $dateRange,
            $writtenRows,
            $deletedRows,
            $statsRows === null ? '' : sprintf(', %d type stats rebuilt', $statsRows),
            microtime(true) - $startTime,
            intdiv(memory_get_peak_usage(true), 1024 * 1024)
        );
        $this->logger->info($message);
        $this->cronLogWriter->write($message, 'info');
    }
}
