<?php

namespace App\Service\CharacterSync;

use App\Entity\EveCharacter;
use App\Service\Esi\EsiClient;
use App\Service\SdeService;
use Psr\Log\LoggerInterface;

/**
 * Fetches custom names and blueprint stats for character and corporation assets.
 */
class AssetDetailsFetcher
{
    public function __construct(
        private readonly EsiClient $esiClient,
        private readonly SdeService $sdeService,
        private readonly LoggerInterface $logger
    ) {}

    /**
     * @param array<int, array<string, mixed>> $assets ESI asset rows
     * @return list<int> Item IDs of singleton ships and containers, the only items that can carry a custom name
     */
    public function collectCustomizableItemIds(array $assets): array
    {
        // Collect singleton item IDs and their type IDs
        $singletonItemIds = [];
        $itemToTypeMap = [];
        foreach ($assets as $assetData) {
            // Merged corp items are owned by the corporation; ESI rejects the whole name batch (404) for them
            if (($assetData['location_type'] ?? null) === 'personal_corp_asset') {
                continue;
            }
            if (!empty($assetData['is_singleton'])) {
                $itemId = (int) $assetData['item_id'];
                $singletonItemIds[] = $itemId;
                $itemToTypeMap[$itemId] = (int) $assetData['type_id'];
            }
        }

        // Filter out only item IDs that are customizable (Ships and Containers) using SdeService
        $customizableTypeIds = $this->sdeService->filterCustomizableTypeIds(array_unique(array_values($itemToTypeMap)));
        $customizableItemIds = [];
        foreach ($singletonItemIds as $itemId) {
            $typeId = $itemToTypeMap[$itemId];
            if (in_array($typeId, $customizableTypeIds, true)) {
                $customizableItemIds[] = $itemId;
            }
        }

        return $customizableItemIds;
    }

    /**
     * @param string $namesPath ESI path of the names endpoint, e.g. characters/123/assets/names/
     * @param string $logContext What failed, used in the warning, e.g. "character asset names for Pilot"
     * @return array<int, string> Item ID => custom name
     */
    public function fetchNames(string $namesPath, EveCharacter $character, array $itemIds, string $logContext): array
    {
        if (empty($itemIds)) {
            return [];
        }

        $namesMap = [];
        // Assets moving between page fetches can yield duplicate IDs, which ESI rejects with 400
        $chunks = array_chunk(array_values(array_unique($itemIds)), 1000);

        foreach ($chunks as $chunk) {
            try {
                $namesData = $this->esiClient->request(
                    'POST',
                    $namesPath,
                    [
                        'json' => $chunk
                    ],
                    $character
                );

                if (is_array($namesData)) {
                    foreach ($namesData as $nameItem) {
                        if (isset($nameItem['item_id']) && isset($nameItem['name'])) {
                            $name = trim($nameItem['name']);
                            if ($name !== '' && $name !== 'None') {
                                $namesMap[(int) $nameItem['item_id']] = $name;
                            }
                        }
                    }
                }
            } catch (\Exception $e) {
                $this->logger->warning(sprintf('[Cron] Failed to fetch %s: %s', $logContext, $e->getMessage()));
            }
        }

        return $namesMap;
    }

    /**
     * @param string $blueprintsPath ESI path of the blueprints endpoint, e.g. characters/123/blueprints/
     * @param string $logContext What failed, used in the error, e.g. "blueprints for character 123"
     * @return array<int, array{me: int, te: int, runs: int}> Item ID => blueprint stats
     */
    public function fetchBlueprints(string $blueprintsPath, EveCharacter $character, string $logContext): array
    {
        // Fetch blueprints to enrich assets with ME/TE/runs (paginated)
        $blueprintsMap = [];
        try {
            $bpResponse = $this->esiClient->requestAllPages($blueprintsPath, [], $character);
            foreach ($bpResponse['data'] as $bp) {
                $blueprintsMap[(int)$bp['item_id']] = [
                    'me' => (int)($bp['material_efficiency'] ?? 0),
                    'te' => (int)($bp['time_efficiency'] ?? 0),
                    'runs' => (int)($bp['runs'] ?? -1),
                ];
            }
        } catch (\Exception $e) {
            $this->logger->error(sprintf('[Cron] Failed to fetch %s: %s', $logContext, $e->getMessage()));
        }

        return $blueprintsMap;
    }
}
