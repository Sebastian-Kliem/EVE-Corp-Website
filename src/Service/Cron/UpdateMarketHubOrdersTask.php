<?php

namespace App\Service\Cron;

use App\Repository\MarketPriceRepository;
use App\Service\Esi\EsiClient;
use App\Service\Market\MarketHub;
use App\Service\Market\MarketOrderBookAggregator;
use App\Service\Trading\TradingOrderMonitor;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;

/**
 * Pulls the order books of the trade hub regions and stores their key figures in market_price.
 */
class UpdateMarketHubOrdersTask implements CronTaskInterface
{
    // Jita changes fastest, the other hubs are refreshed less often
    private const REFRESH_SECONDS_JITA = 600;
    private const REFRESH_SECONDS_OTHER_HUBS = 900;
    // A run that starts a little early must not skip a region for a whole interval
    private const REFRESH_TOLERANCE_SECONDS = 60;

    public function __construct(
        private readonly EsiClient $esiClient,
        private readonly MarketOrderBookAggregator $aggregator,
        private readonly MarketPriceRepository $marketPriceRepository,
        private readonly TradingOrderMonitor $tradingOrderMonitor,
        private readonly ClockInterface $clock,
        private readonly CronLogWriter $cronLogWriter,
        private readonly LoggerInterface $logger
    ) {}

    public function getCommandName(): string
    {
        return 'market:sync-hub-orders';
    }

    public function execute(): void
    {
        foreach (MarketHub::HUBS as $stationId => $hub) {
            if (!$this->_isDue($hub['regionId'], $stationId)) {
                continue;
            }

            try {
                $this->_syncRegion($hub['regionId'], $stationId, $hub['name']);
            } catch (\Exception $e) {
                // One failing region must not keep the other hubs from updating
                $this->_log(sprintf('[Market] Failed to sync the order book of %s (region %d): %s', $hub['name'], $hub['regionId'], $e->getMessage()), 'error');
            }
        }
    }

    private function _isDue(int $regionId, int $stationId): bool
    {
        $lastFetchedAt = $this->marketPriceRepository->findLastFetchedAt($regionId);
        if ($lastFetchedAt === null) {
            return true;
        }

        $refreshSeconds = $stationId === MarketHub::JITA ? self::REFRESH_SECONDS_JITA : self::REFRESH_SECONDS_OTHER_HUBS;

        return $this->clock->now()->getTimestamp() - $lastFetchedAt->getTimestamp() >= $refreshSeconds - self::REFRESH_TOLERANCE_SECONDS;
    }

    private function _syncRegion(int $regionId, int $stationId, string $hubName): void
    {
        $startTime = microtime(true);
        $fetchedAt = $this->clock->now();

        $result = $this->_fetchOrderBook($regionId, $stationId);
        if (!$result['consistent']) {
            // Pages from two ESI snapshots mix old and new orders, one retry usually gets a clean book
            $this->_log(sprintf('[Market] Order book of %s changed during the fetch, fetching again.', $hubName), 'info');
            $fetchedAt = $this->clock->now();
            $result = $this->_fetchOrderBook($regionId, $stationId);
        }

        $orderCount = $this->aggregator->getOrderCount();
        $checkedOrders = $this->tradingOrderMonitor->storeStationResults($stationId, $this->aggregator->getWatchedOrders(), $fetchedAt);
        $rowCount = $this->marketPriceRepository->replaceRegion($regionId, $this->aggregator->buildRows($fetchedAt));

        $this->_log(sprintf(
            '[Market] %s: %d pages, %d orders, %d price rows, %d trading orders checked in %.1f s (peak memory %d MB%s).',
            $hubName,
            $result['pages'],
            $orderCount,
            $rowCount,
            $checkedOrders,
            microtime(true) - $startTime,
            intdiv(memory_get_peak_usage(true), 1024 * 1024),
            $result['consistent'] ? '' : ', pages from different ESI snapshots'
        ), 'info');
    }

    /**
     * @return array{pages: int, expiresAt: ?\DateTimeImmutable, consistent: bool}
     */
    private function _fetchOrderBook(int $regionId, int $stationId): array
    {
        $this->aggregator->start($regionId, $stationId);
        $this->aggregator->watchStationTypes($this->tradingOrderMonitor->prepareStation($stationId));

        return $this->esiClient->streamPublicPages(
            sprintf('markets/%d/orders/', $regionId),
            ['order_type' => 'all'],
            function (array $orders): void {
                $this->aggregator->addOrders($orders);
            }
        );
    }

    private function _log(string $message, string $level): void
    {
        $this->logger->log($level, $message);
        $this->cronLogWriter->write($message, $level);
    }
}
