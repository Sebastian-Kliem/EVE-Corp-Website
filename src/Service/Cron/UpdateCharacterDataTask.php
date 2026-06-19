<?php

namespace App\Service\Cron;

use App\Entity\EveCharacter;
use App\Entity\EveCharacterAsset;
use App\Entity\EveCharacterAssetChange;
use App\Entity\EveCharacterValueSnapshot;
use App\Entity\EveCorporationAsset;
use App\Entity\TrackingListItem;
use App\Repository\EveCharacterAssetRepository;
use App\Repository\EveCorporationAssetRepository;
use App\Service\Esi\EsiClient;
use App\Service\SdeService;
use App\Service\JitaPriceService;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;

class UpdateCharacterDataTask implements CronTaskInterface
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly EsiClient $esiClient,
        private readonly EveCharacterAssetRepository $assetRepository,
        private readonly EveCorporationAssetRepository $corpAssetRepository,
        private readonly SdeService $sdeService,
        private readonly LoggerInterface $logger,
        private readonly JitaPriceService $jitaPriceService
    ) {}

    public function getCommandName(): string
    {
        return 'character:sync-wallet-assets';
    }

    public function execute(): void
    {
        $characterRepository = $this->entityManager->getRepository(EveCharacter::class);
        /** @var EveCharacter[] $characters */
        $characters = $characterRepository->findAll();

        $this->logger->info(sprintf('[Cron] Starting sync-wallet-assets for %d characters.', count($characters)));

        $syncedCorpIds = [];

        foreach ($characters as $character) {
            if (empty($character->getRefreshToken())) {
                $this->logger->warning(sprintf('[Cron] Skipping character %s (%d): No refresh token.', $character->getName(), $character->getId()));
                continue;
            }

            // Sync Wallet
            try {
                $this->syncWallet($character);
            } catch (\Exception $e) {
                $this->logger->error(sprintf(
                    '[Cron] Failed to sync wallet for character %s (%d): %s',
                    $character->getName(),
                    $character->getId(),
                    $e->getMessage()
                ));
            }

            // Sync Assets (Inventory)
            try {
                $this->syncAssets($character);
            } catch (\Exception $e) {
                $this->logger->error(sprintf(
                    '[Cron] Failed to sync assets for character %s (%d): %s',
                    $character->getName(),
                    $character->getId(),
                    $e->getMessage()
                ));
            }

            // Sync Corporation Assets
            $corpId = $character->getCorporationId();
            if ($corpId && !in_array($corpId, $syncedCorpIds, true)) {
                try {
                    $this->syncCorpAssets($character);
                    $syncedCorpIds[] = $corpId;
                } catch (\Exception $e) {
                    $this->logger->warning(sprintf(
                        '[Cron] Failed to sync corporation assets for corp %d using character %s: %s',
                        $corpId,
                        $character->getName(),
                        $e->getMessage()
                    ));
                }
            }
        }

        $this->logger->info('[Cron] Finished sync-wallet-assets execution.');
    }

    private function syncWallet(EveCharacter $character): void
    {
        $this->logger->debug(sprintf('[Cron] Syncing wallet for character %s...', $character->getName()));
        
        // GET /characters/{character_id}/wallet/
        // Returns the ISK balance as a float
        $balance = $this->esiClient->request(
            'GET',
            sprintf('characters/%d/wallet/', $character->getId()),
            [],
            $character
        );

        // Convert to string to store as decimal without floating point issues
        $character->setWalletBalance(number_format((float) $balance, 2, '.', ''));
        $character->setLastWalletUpdate(new \DateTimeImmutable());
        
        $this->entityManager->flush();
        
        $this->logger->info(sprintf(
            '[Cron] Successfully updated wallet for character %s to %s ISK.',
            $character->getName(),
            $character->getWalletBalance()
        ));
    }

    private function syncAssets(EveCharacter $character): void
    {
        $this->logger->debug(sprintf('[Cron] Syncing assets for character %s...', $character->getName()));

        $page = 1;
        $allAssets = [];

        // Paginate character assets
        while (true) {
            try {
                $assets = $this->esiClient->request(
                    'GET',
                    sprintf('characters/%d/assets/', $character->getId()),
                    [
                        'query' => ['page' => $page]
                    ],
                    $character
                );

                if (empty($assets)) {
                    break;
                }

                $allAssets = array_merge($allAssets, $assets);
                $page++;
            } catch (\Exception $e) {
                // If page > 1 fails, it likely means we hit the end of pages (e.g. ESI returning 404 or bad page)
                if ($page === 1) {
                    throw $e; // Throw if first page fails, meaning permission/scope issues or API error
                }
                break;
            }
        }

        // Collect singleton item IDs and their type IDs
        $singletonItemIds = [];
        $itemToTypeMap = [];
        foreach ($allAssets as $assetData) {
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

        $namesMap = $this->fetchCharacterAssetNames($character, $customizableItemIds);

        // Fetch blueprints to enrich assets with ME/TE/runs (paginated)
        $blueprintsMap = [];
        $page = 1;
        while (true) {
            try {
                $blueprints = $this->esiClient->request(
                    'GET',
                    sprintf('characters/%d/blueprints/', $character->getId()),
                    [
                        'query' => ['page' => $page]
                    ],
                    $character
                );
                if (empty($blueprints)) {
                    break;
                }
                foreach ($blueprints as $bp) {
                    $blueprintsMap[(int)$bp['item_id']] = [
                        'me' => (int)($bp['material_efficiency'] ?? 0),
                        'te' => (int)($bp['time_efficiency'] ?? 0),
                        'runs' => (int)($bp['runs'] ?? -1),
                    ];
                }
                $page++;
            } catch (\Exception $e) {
                if ($page === 1) {
                    error_log(sprintf('[UpdateCharacterDataTask] Failed to fetch blueprints for character %d: %s', $character->getId(), $e->getMessage()));
                }
                break;
            }
        }

        // Perform asset database update in a transaction
        $this->entityManager->wrapInTransaction(function() use ($character, $allAssets, $namesMap, $blueprintsMap) {
            // A. Calculate asset changes (increases) for tracked items
            try {
                $trackingItemRepository = $this->entityManager->getRepository(TrackingListItem::class);
                $listItems = $trackingItemRepository->findAll();
                $trackedTypeIds = [];
                foreach ($listItems as $item) {
                    $trackedTypeIds[] = $item->getTypeId();
                }
                $trackedTypeIds = array_unique($trackedTypeIds);

                if ($character->getLastAssetsUpdate() !== null && !empty($trackedTypeIds)) {
                    $oldAssets = $this->assetRepository->findBy(['character' => $character]);
                    $oldQuantities = [];
                    foreach ($oldAssets as $oldAsset) {
                        $tid = $oldAsset->getTypeId();
                        if (in_array($tid, $trackedTypeIds, true)) {
                            $oldQuantities[$tid] = ($oldQuantities[$tid] ?? 0) + $oldAsset->getQuantity();
                        }
                    }

                    $newQuantities = [];
                    foreach ($allAssets as $assetData) {
                        $tid = (int) $assetData['type_id'];
                        if (in_array($tid, $trackedTypeIds, true)) {
                            $newQuantities[$tid] = ($newQuantities[$tid] ?? 0) + (int) $assetData['quantity'];
                        }
                    }

                    $now = new \DateTimeImmutable();
                    foreach ($newQuantities as $tid => $newQty) {
                        $oldQty = $oldQuantities[$tid] ?? 0;
                        if ($newQty > $oldQty) {
                            $changeQty = $newQty - $oldQty;

                            $change = new EveCharacterAssetChange();
                            $change->setCharacter($character);
                            $change->setTypeId($tid);
                            $change->setQuantity((string) $changeQty);
                            $change->setLoggedAt($now);

                            $this->entityManager->persist($change);
                        }
                    }
                }
            } catch (\Exception $e) {
                // Log and continue, do not block the main asset sync
                $this->logger->error(sprintf('[Cron] Failed to calculate asset changes for character %s: %s', $character->getName(), $e->getMessage()));
            }

            // 1. Clear existing assets
            $this->assetRepository->clearAssetsForCharacter($character->getId());

            // 2. Insert new assets in batches
            $batchSize = 250;
            $i = 0;
            
            foreach ($allAssets as $assetData) {
                $asset = new EveCharacterAsset();
                $asset->setCharacter($character);
                $asset->setItemId($assetData['item_id']);
                $asset->setTypeId($assetData['type_id']);
                $asset->setQuantity($assetData['quantity']);
                $asset->setLocationId($assetData['location_id']);
                $asset->setLocationType($assetData['location_type']);
                $asset->setLocationFlag($assetData['location_flag']);
                $asset->setIsSingleton((bool) $assetData['is_singleton']);
                
                if (isset($assetData['is_blueprint_copy'])) {
                    $asset->setIsBlueprintCopy((bool) $assetData['is_blueprint_copy']);
                }

                if (isset($namesMap[$assetData['item_id']])) {
                    $asset->setCustomName($namesMap[$assetData['item_id']]);
                }

                if (isset($blueprintsMap[$assetData['item_id']])) {
                    $asset->setMaterialEfficiency($blueprintsMap[$assetData['item_id']]['me']);
                    $asset->setTimeEfficiency($blueprintsMap[$assetData['item_id']]['te']);
                    $asset->setRuns($blueprintsMap[$assetData['item_id']]['runs']);
                }

                $this->entityManager->persist($asset);
                
                $i++;
                if (($i % $batchSize) === 0) {
                    $this->entityManager->flush();
                }
            }

            $character->setLastAssetsUpdate(new \DateTimeImmutable());
            $this->entityManager->flush();
        });

        // 3. Save daily value snapshot
        try {
            $today = new \DateTimeImmutable('today');

            // Calculate total asset value using global prices
            $prices = $this->jitaPriceService->getGlobalPrices();
            $totalAssetVal = 0.0;
            foreach ($allAssets as $assetData) {
                $typeId = (int)$assetData['type_id'];
                $qty = (int)$assetData['quantity'];
                
                $isBpc = false;
                if (isset($blueprintsMap[(int)$assetData['item_id']])) {
                    $isBpc = $blueprintsMap[(int)$assetData['item_id']]['runs'] > 0;
                }
                
                if (!$isBpc) {
                    $price = $prices[$typeId] ?? 0.0;
                    $totalAssetVal += ($price * $qty);
                }
            }

            // Save character value snapshot
            $walletBalance = (float)($character->getWalletBalance() ?? 0.0);
            
            $valSnapshotRepository = $this->entityManager->getRepository(EveCharacterValueSnapshot::class);
            $valSnapshot = $valSnapshotRepository->findOneBy([
                'character' => $character,
                'snapshotDate' => $today,
            ]);
            
            if (!$valSnapshot) {
                $valSnapshot = new EveCharacterValueSnapshot();
                $valSnapshot->setCharacter($character);
                $valSnapshot->setSnapshotDate($today);
            }
            $valSnapshot->setWalletBalance(number_format($walletBalance, 2, '.', ''));
            $valSnapshot->setAssetsValue(number_format($totalAssetVal, 2, '.', ''));
            
            $this->entityManager->persist($valSnapshot);
            $this->entityManager->flush();
            
            $this->logger->info(sprintf(
                '[Cron] Successfully saved value snapshot for character %s. Wallet: %f, Assets: %f',
                $character->getName(),
                $walletBalance,
                $totalAssetVal
            ));
        } catch (\Exception $e) {
            $this->logger->error(sprintf(
                '[Cron] Failed to save value snapshot for character %s: %s',
                $character->getName(),
                $e->getMessage()
            ));
        }

        $this->logger->info(sprintf(
            '[Cron] Successfully updated %d assets for character %s.',
            count($allAssets),
            $character->getName()
        ));
    }

    private function syncCorpAssets(EveCharacter $character): void
    {
        $corpId = $character->getCorporationId();
        if (!$corpId) {
            return;
        }

        $this->logger->info(sprintf('[Cron] Syncing corporation assets for corp %d using character %s...', $corpId, $character->getName()));

        $page = 1;
        $allAssets = [];

        while (true) {
            try {
                $assets = $this->esiClient->request(
                    'GET',
                    sprintf('corporations/%d/assets/', $corpId),
                    [
                        'query' => ['page' => $page]
                    ],
                    $character
                );

                if (empty($assets)) {
                    break;
                }

                $allAssets = array_merge($allAssets, $assets);
                $page++;
            } catch (\Exception $e) {
                if ($page === 1) {
                    throw $e; // Throw if first page fails, meaning missing permissions/roles/etc.
                }
                break;
            }
        }

        // Collect singleton item IDs and their type IDs
        $singletonItemIds = [];
        $itemToTypeMap = [];
        foreach ($allAssets as $assetData) {
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

        $namesMap = $this->fetchCorpAssetNames($character, $corpId, $customizableItemIds);

        // Fetch blueprints to enrich assets with ME/TE/runs (paginated)
        $blueprintsMap = [];
        $page = 1;
        while (true) {
            try {
                $blueprints = $this->esiClient->request(
                    'GET',
                    sprintf('corporations/%d/blueprints/', $corpId),
                    [
                        'query' => ['page' => $page]
                    ],
                    $character
                );
                if (empty($blueprints)) {
                    break;
                }
                foreach ($blueprints as $bp) {
                    $blueprintsMap[(int)$bp['item_id']] = [
                        'me' => (int)($bp['material_efficiency'] ?? 0),
                        'te' => (int)($bp['time_efficiency'] ?? 0),
                        'runs' => (int)($bp['runs'] ?? -1),
                    ];
                }
                $page++;
            } catch (\Exception $e) {
                if ($page === 1) {
                    error_log(sprintf('[UpdateCharacterDataTask] Failed to fetch corporation blueprints for corp %d: %s', $corpId, $e->getMessage()));
                }
                break;
            }
        }

        // Perform asset database update in a transaction
        $this->entityManager->wrapInTransaction(function() use ($corpId, $allAssets, $character, $namesMap, $blueprintsMap) {
            // 1. Clear existing corp assets
            $this->corpAssetRepository->clearAssetsForCorporation($corpId);

            // 2. Insert new assets in batches
            $batchSize = 250;
            $i = 0;
            
            foreach ($allAssets as $assetData) {
                $asset = new EveCorporationAsset();
                $asset->setCorporationId($corpId);
                $asset->setItemId($assetData['item_id']);
                $asset->setTypeId($assetData['type_id']);
                $asset->setQuantity($assetData['quantity']);
                $asset->setLocationId($assetData['location_id']);
                $asset->setLocationType($assetData['location_type']);
                $asset->setLocationFlag($assetData['location_flag']);
                $asset->setIsSingleton((bool) $assetData['is_singleton']);
                
                if (isset($assetData['is_blueprint_copy'])) {
                    $asset->setIsBlueprintCopy((bool) $assetData['is_blueprint_copy']);
                }

                if (isset($namesMap[$assetData['item_id']])) {
                    $asset->setCustomName($namesMap[$assetData['item_id']]);
                }

                if (isset($blueprintsMap[$assetData['item_id']])) {
                    $asset->setMaterialEfficiency($blueprintsMap[$assetData['item_id']]['me']);
                    $asset->setTimeEfficiency($blueprintsMap[$assetData['item_id']]['te']);
                    $asset->setRuns($blueprintsMap[$assetData['item_id']]['runs']);
                }

                $this->entityManager->persist($asset);
                
                $i++;
                if (($i % $batchSize) === 0) {
                    $this->entityManager->flush();
                }
            }

            $character->setLastCorpAssetsUpdate(new \DateTimeImmutable());
            $this->entityManager->flush();
        });

        $this->logger->info(sprintf(
            '[Cron] Successfully updated %d corporation assets for corp %d using character %s.',
            count($allAssets),
            $corpId,
            $character->getName()
        ));
    }

    private function fetchCharacterAssetNames(EveCharacter $character, array $itemIds): array
    {
        if (empty($itemIds)) {
            return [];
        }

        $namesMap = [];
        $chunks = array_chunk($itemIds, 1000);

        foreach ($chunks as $chunk) {
            try {
                $namesData = $this->esiClient->request(
                    'POST',
                    sprintf('characters/%d/assets/names/', $character->getId()),
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
                $this->logger->warning(sprintf(
                    '[Cron] Failed to fetch character asset names for %s: %s',
                    $character->getName(),
                    $e->getMessage()
                ));
            }
        }

        return $namesMap;
    }

    private function fetchCorpAssetNames(EveCharacter $character, int $corpId, array $itemIds): array
    {
        if (empty($itemIds)) {
            return [];
        }

        $namesMap = [];
        $chunks = array_chunk($itemIds, 1000);

        foreach ($chunks as $chunk) {
            try {
                $namesData = $this->esiClient->request(
                    'POST',
                    sprintf('corporations/%d/assets/names/', $corpId),
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
                $this->logger->warning(sprintf(
                    '[Cron] Failed to fetch corporation asset names for corp %d using %s: %s',
                    $corpId,
                    $character->getName(),
                    $e->getMessage()
                ));
            }
        }

        return $namesMap;
    }
}
