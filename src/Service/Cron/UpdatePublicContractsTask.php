<?php

namespace App\Service\Cron;

use App\Entity\Market\PublicContract;
use App\Repository\PublicContractRepository;
use App\Service\Esi\EsiClient;
use App\Service\SdeService;
use Psr\Cache\CacheItemPoolInterface;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;

/**
 * Keeps the public item exchange contracts of all known space regions in sync and loads the items of new contracts.
 */
class UpdatePublicContractsTask implements CronTaskInterface
{
    // ESI caches the contract lists for about 30 minutes
    private const LIST_REFRESH_SECONDS = 1800;
    private const LIST_SYNC_CACHE_KEY = 'market_public_contracts_listed_at';
    // One run stays at about a minute (2,000 contracts took 22 s), the initial load of ~50,000 contracts takes about an hour
    private const MAX_ITEM_CONTRACTS_PER_RUN = 5000;
    private const ITEM_SAVE_CHUNK = 200;

    public function __construct(
        private readonly EsiClient $esiClient,
        private readonly PublicContractRepository $contractRepository,
        private readonly SdeService $sdeService,
        private readonly CacheItemPoolInterface $cachePool,
        private readonly ClockInterface $clock,
        private readonly CronLogWriter $cronLogWriter,
        private readonly LoggerInterface $logger
    ) {}

    public function getCommandName(): string
    {
        return 'market:sync-public-contracts';
    }

    public function execute(): void
    {
        if ($this->_isListSyncDue()) {
            $this->_syncContractLists();
        }
        $this->_loadMissingItems();
    }

    private function _isListSyncDue(): bool
    {
        $cacheItem = $this->cachePool->getItem(self::LIST_SYNC_CACHE_KEY);

        return !$cacheItem->isHit() || $this->clock->now()->getTimestamp() - (int)$cacheItem->get() >= self::LIST_REFRESH_SECONDS - 60;
    }

    private function _syncContractLists(): void
    {
        $startTime = microtime(true);
        $listedContracts = 0;
        $goneContracts = 0;
        $failedRegions = 0;

        foreach ($this->sdeService->getKnownSpaceRegionIds() as $regionId) {
            try {
                [$regionContracts, $regionGone] = $this->_syncRegion($regionId);
                $listedContracts += $regionContracts;
                $goneContracts += $regionGone;
            } catch (\Exception $e) {
                // A failed region keeps its contracts unchanged until the next list sync
                $failedRegions++;
                $this->_log(sprintf('[Market] Failed to sync the public contracts of region %d: %s', $regionId, $e->getMessage()), 'error');
            }
        }

        $deletedContracts = $this->contractRepository->deleteGoneBefore(
            $this->clock->now()->modify(sprintf('-%d days', PublicContract::GONE_RETENTION_DAYS))
        );

        $cacheItem = $this->cachePool->getItem(self::LIST_SYNC_CACHE_KEY);
        $cacheItem->set($this->clock->now()->getTimestamp());
        $this->cachePool->save($cacheItem);

        $this->_log(sprintf(
            '[Market] Contracts: %d item exchange contracts listed, %d gone, %d deleted after %d days%s in %.1f s (peak memory %d MB).',
            $listedContracts,
            $goneContracts,
            $deletedContracts,
            PublicContract::GONE_RETENTION_DAYS,
            $failedRegions > 0 ? sprintf(', %d regions failed', $failedRegions) : '',
            microtime(true) - $startTime,
            intdiv(memory_get_peak_usage(true), 1024 * 1024)
        ), 'info');
    }

    /**
     * @return array{0: int, 1: int} Listed and newly gone contracts
     */
    private function _syncRegion(int $regionId): array
    {
        $listedAt = $this->clock->now();
        $listedContracts = 0;

        $result = $this->esiClient->streamPublicPages(
            sprintf('contracts/public/%d/', $regionId),
            [],
            function (array $contracts) use ($regionId, $listedAt, &$listedContracts): void {
                $rows = $this->_buildContractRows($contracts, $regionId, $listedAt);
                $this->contractRepository->upsertSeen($rows);
                $listedContracts += count($rows);
            }
        );

        // A list mixed from two ESI snapshots can miss contracts, so none are marked as gone this time
        if (!$result['consistent']) {
            $this->_log(sprintf('[Market] Contract list of region %d changed during the fetch, gone contracts are detected next time.', $regionId), 'info');
            return [$listedContracts, 0];
        }

        return [$listedContracts, $this->contractRepository->markGone($regionId, $listedAt)];
    }

    /**
     * @param array<int, array<string, mixed>> $contracts ESI public contracts of one page
     * @return list<array<string, mixed>>
     */
    private function _buildContractRows(array $contracts, int $regionId, \DateTimeImmutable $listedAt): array
    {
        $seenAt = $listedAt->format('Y-m-d H:i:s');

        $rows = [];
        foreach ($contracts as $contract) {
            if (($contract['type'] ?? '') !== 'item_exchange') {
                continue;
            }
            $rows[] = [
                'contract_id' => (int)$contract['contract_id'],
                'region_id' => $regionId,
                'start_location_id' => (int)($contract['start_location_id'] ?? 0),
                'issuer_id' => (int)$contract['issuer_id'],
                'issuer_corporation_id' => (int)$contract['issuer_corporation_id'],
                'for_corporation' => !empty($contract['for_corporation']) ? 1 : 0,
                'price' => number_format((float)($contract['price'] ?? 0), 2, '.', ''),
                'volume' => number_format((float)($contract['volume'] ?? 0), 2, '.', ''),
                'title' => ($contract['title'] ?? '') !== '' ? mb_substr((string)$contract['title'], 0, 255) : null,
                'date_issued' => $this->_formatEsiDate((string)$contract['date_issued']),
                'date_expired' => $this->_formatEsiDate((string)$contract['date_expired']),
                'first_seen_at' => $seenAt,
                'last_seen_at' => $seenAt,
            ];
        }

        return $rows;
    }

    private function _loadMissingItems(): void
    {
        $contractIds = $this->contractRepository->findContractIdsWithoutItems(self::MAX_ITEM_CONTRACTS_PER_RUN);
        if ($contractIds === []) {
            return;
        }

        $startTime = microtime(true);
        $loadedContracts = 0;
        $goneContracts = 0;
        foreach (array_chunk($contractIds, self::ITEM_SAVE_CHUNK) as $chunk) {
            $paths = [];
            foreach ($chunk as $contractId) {
                $paths[$contractId] = sprintf('contracts/public/items/%d/', $contractId);
            }

            $itemsByContractId = [];
            foreach ($this->esiClient->requestPublicBatch($paths) as $contractId => $answer) {
                if ($answer['data'] === null) {
                    // Accepted, expired or deleted before its items were loaded
                    $itemsByContractId[$contractId] = [];
                    $goneContracts++;
                    continue;
                }
                $items = $answer['pages'] > 1 ? $this->_fetchAllItemPages($contractId) : $answer['data'];
                $itemsByContractId[$contractId] = $this->_buildItemRows($items, $contractId);
                $loadedContracts++;
            }
            $this->contractRepository->saveItems($itemsByContractId, $this->clock->now());
        }

        $this->_log(sprintf(
            '[Market] Contract items: %d contracts loaded, %d already gone in %.1f s.',
            $loadedContracts,
            $goneContracts,
            microtime(true) - $startTime
        ), 'info');
    }

    /**
     * Rare: contracts with very many items are paginated.
     *
     * @return array<int, array<string, mixed>>
     */
    private function _fetchAllItemPages(int $contractId): array
    {
        $items = [];
        $this->esiClient->streamPublicPages(sprintf('contracts/public/items/%d/', $contractId), [], function (array $pageItems) use (&$items): void {
            foreach ($pageItems as $item) {
                $items[] = $item;
            }
        });

        return $items;
    }

    /**
     * @param array<int, array<string, mixed>> $items ESI contract items
     * @return list<array<string, mixed>>
     */
    private function _buildItemRows(array $items, int $contractId): array
    {
        $rows = [];
        foreach ($items as $item) {
            $rows[] = [
                'contract_id' => $contractId,
                'record_id' => (int)$item['record_id'],
                'type_id' => (int)$item['type_id'],
                'quantity' => (int)$item['quantity'],
                'is_included' => !empty($item['is_included']) ? 1 : 0,
                'is_singleton' => !empty($item['is_singleton']) ? 1 : 0,
                'is_blueprint_copy' => isset($item['is_blueprint_copy']) ? ((bool)$item['is_blueprint_copy'] ? 1 : 0) : null,
                'material_efficiency' => isset($item['material_efficiency']) ? (int)$item['material_efficiency'] : null,
                'time_efficiency' => isset($item['time_efficiency']) ? (int)$item['time_efficiency'] : null,
                'runs' => isset($item['runs']) ? (int)$item['runs'] : null,
                'item_id' => isset($item['item_id']) ? (int)$item['item_id'] : null,
            ];
        }

        return $rows;
    }

    private function _formatEsiDate(string $esiDate): string
    {
        return (new \DateTimeImmutable($esiDate))->setTimezone(new \DateTimeZone(date_default_timezone_get()))->format('Y-m-d H:i:s');
    }

    private function _log(string $message, string $level): void
    {
        $this->logger->log($level, $message);
        $this->cronLogWriter->write($message, $level);
    }
}
