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
        // Skip execution during EVE Online downtime window (10:50 - 11:30 UTC / Eve Time)
        $nowUtc = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        $timeStr = $nowUtc->format('H:i');
        if ($timeStr >= '10:50' && $timeStr <= '11:30') {
            $this->logger->info(sprintf('[Cron] Skipping sync-wallet-assets: Current EVE time %s is within downtime window (10:50 - 11:30 UTC).', $timeStr));
            return;
        }

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

            // Sync Wallet, Journal & Market Transactions
            try {
                $this->syncRoles($character);
                $this->syncWallet($character);
                $this->syncWalletJournal($character);
                $this->syncMarketTransactions($character);
            } catch (\Exception $e) {
                $this->logger->error(sprintf(
                    '[Cron] Failed to sync wallet/journal for character %s (%d): %s',
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

    private function syncRoles(EveCharacter $character): void
    {
        $this->logger->debug(sprintf('[Cron] Syncing roles for character %s...', $character->getName()));
        
        try {
            $rolesData = $this->esiClient->request(
                'GET',
                sprintf('characters/%d/roles/', $character->getId()),
                [],
                $character
            );
            $roles = $rolesData['roles'] ?? [];
            $character->setRoles($roles);
            $this->entityManager->flush();
            
            $this->logger->info(sprintf(
                '[Cron] Successfully updated roles for character %s: %s',
                $character->getName(),
                implode(', ', $roles)
            ));
        } catch (\Exception $e) {
            $this->logger->error(sprintf(
                '[Cron] Failed to sync roles for character %s (%d): %s',
                $character->getName(),
                $character->getId(),
                $e->getMessage()
            ));
        }
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

    private function syncWalletJournal(EveCharacter $character): void
    {
        $this->logger->debug(sprintf('[Cron] Syncing wallet journal for character %s...', $character->getName()));
        
        $page = 1;
        $insertedCount = 0;
        $repo = $this->entityManager->getRepository(\App\Entity\EveCharacterWalletJournalEntry::class);

        while (true) {
            try {
                $journalData = $this->esiClient->request(
                    'GET',
                    sprintf('characters/%d/wallet/journal/', $character->getId()),
                    [
                        'query' => ['page' => $page]
                    ],
                    $character
                );

                if (empty($journalData)) {
                    break;
                }

                $hasExisting = false;
                foreach ($journalData as $entryData) {
                    $refId = (string) $entryData['id'];
                    
                    // Check if entry already exists in DB
                    $existing = $repo->findOneBy([
                        'character' => $character,
                        'refId' => $refId
                    ]);

                    if ($existing) {
                        $hasExisting = true;
                        continue;
                    }

                    $entry = new \App\Entity\EveCharacterWalletJournalEntry();
                    $entry->setCharacter($character);
                    $entry->setRefId($refId);
                    $entry->setDate(new \DateTimeImmutable($entryData['date']));
                    $entry->setRefType($entryData['ref_type']);
                    $entry->setAmount(number_format((float) ($entryData['amount'] ?? 0.0), 2, '.', ''));
                    $entry->setBalance(number_format((float) ($entryData['balance'] ?? 0.0), 2, '.', ''));
                    $entry->setDescription($entryData['description'] ?? null);
                    $entry->setFirstPartyId($entryData['first_party_id'] ?? null);
                    $entry->setSecondPartyId($entryData['second_party_id'] ?? null);
                    
                    if (isset($entryData['context_id'])) {
                        $entry->setContextId((string) $entryData['context_id']);
                    }
                    $entry->setContextIdType($entryData['context_id_type'] ?? null);
                    $entry->setReason($entryData['reason'] ?? null);
                    
                    if (isset($entryData['tax'])) {
                        $entry->setTax(number_format((float) $entryData['tax'], 2, '.', ''));
                    }
                    $entry->setTaxReceiverId($entryData['tax_receiver_id'] ?? null);

                    $this->entityManager->persist($entry);
                    $insertedCount++;
                }

                $this->entityManager->flush();

                // ESI returns descending chronological order.
                // If we hit any existing transaction, or count is less than full page, stop fetching.
                if ($hasExisting || count($journalData) < 2500) {
                    break;
                }

                $page++;
                if ($page > 10) {
                    break;
                }

            } catch (\Exception $e) {
                $this->logger->error(sprintf(
                    '[Cron] Failed to fetch wallet journal page %d for character %s: %s',
                    $page,
                    $character->getName(),
                    $e->getMessage()
                ));
                break;
            }
        }

        if ($insertedCount > 0) {
            $this->logger->info(sprintf(
                '[Cron] Successfully synchronized %d new wallet journal entries for character %s.',
                $insertedCount,
                $character->getName()
            ));
        }
    }

    private function syncMarketTransactions(EveCharacter $character): void
    {
        $this->logger->debug(sprintf('[Cron] Syncing market transactions for character %s...', $character->getName()));

        $fromId = null;
        $insertedCount = 0;
        $repo = $this->entityManager->getRepository(\App\Entity\EveCharacterMarketTransaction::class);

        while (true) {
            try {
                $query = [];
                if ($fromId !== null) {
                    $query['from_id'] = $fromId;
                }

                $transData = $this->esiClient->request(
                    'GET',
                    sprintf('characters/%d/wallet/transactions/', $character->getId()),
                    [
                        'query' => $query
                    ],
                    $character
                );

                if (empty($transData) || !is_array($transData)) {
                    break;
                }

                $hasExisting = false;
                $lastTransId = null;

                foreach ($transData as $tData) {
                    $transId = (string) $tData['transaction_id'];
                    $lastTransId = $transId;

                    $existing = $repo->findOneBy([
                        'character' => $character,
                        'transactionId' => $transId
                    ]);

                    if ($existing) {
                        $hasExisting = true;
                        continue;
                    }

                    $transaction = new \App\Entity\EveCharacterMarketTransaction();
                    $transaction->setCharacter($character);
                    $transaction->setTransactionId($transId);
                    $transaction->setDate(new \DateTimeImmutable($tData['date']));
                    $transaction->setTypeId((int) $tData['type_id']);
                    $transaction->setQuantity((string) $tData['quantity']);
                    $transaction->setUnitPrice(number_format((float) $tData['unit_price'], 2, '.', ''));
                    $transaction->setIsBuy((bool) $tData['is_buy']);
                    $transaction->setClientId((int) $tData['client_id']);
                    $transaction->setLocationId((string) $tData['location_id']);
                    $transaction->setJournalRefId((string) $tData['journal_ref_id']);

                    $this->entityManager->persist($transaction);
                    $insertedCount++;
                }

                $this->entityManager->flush();

                if ($hasExisting || count($transData) < 2500 || $lastTransId === null) {
                    break;
                }

                $fromId = (string) $lastTransId;

            } catch (\Exception $e) {
                $this->logger->error(sprintf(
                    '[Cron] Failed to fetch market transactions for character %s: %s',
                    $character->getName(),
                    $e->getMessage()
                ));
                break;
            }
        }

        if ($insertedCount > 0) {
            $this->logger->info(sprintf(
                '[Cron] Successfully synchronized %d new market transactions for character %s.',
                $insertedCount,
                $character->getName()
            ));
        }
    }

    private function syncAssets(EveCharacter $character): void
    {
        $this->logger->debug(sprintf('[Cron] Syncing assets for character %s...', $character->getName()));

        $page = 1;
        $allAssets = [];
        $totalPages = 1;

        // Paginate character assets using X-Pages header and retry logic
        while ($page <= $totalPages) {
            try {
                $response = $this->esiClient->requestWithHeaders(
                    'GET',
                    sprintf('characters/%d/assets/', $character->getId()),
                    [
                        'query' => ['page' => $page]
                    ],
                    $character
                );

                if ($page === 1 && ($response['fromCache'] ?? false)) {
                    $this->logger->info(sprintf('[Cron] Assets for character %s are still cached. Skipping update.', $character->getName()));
                    return;
                }

                $assets = $response['data'];
                $headers = $response['headers'];

                if (empty($assets)) {
                    break;
                }

                $allAssets = array_merge($allAssets, $assets);

                if ($page === 1 && isset($headers['x-pages'][0])) {
                    $totalPages = (int)$headers['x-pages'][0];
                }

                $page++;
            } catch (\Symfony\Contracts\HttpClient\Exception\HttpExceptionInterface $e) {
                if ($e->getResponse()->getStatusCode() === 404) {
                    break;
                }
                throw $e;
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
                $trackedTypeIds = $this->getTrackedTypeIds();

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

                    $allTids = array_unique(array_merge(
                        array_keys($newQuantities),
                        array_keys($oldQuantities)
                    ));

                    $now = new \DateTimeImmutable();
                    foreach ($allTids as $tid) {
                        $oldQty = $oldQuantities[$tid] ?? 0;
                        $newQty = $newQuantities[$tid] ?? 0;
                        if ($newQty !== $oldQty) {
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

            // Merge Personal Corporation Assets if this is the primary character for the corporation
            $user = $character->getUser();
            if ($user && $character->getCorporationId()) {
                $allUserChars = $this->entityManager->getRepository(EveCharacter::class)->findBy([
                    'user' => $user
                ]);
                $corpChars = [];
                foreach ($allUserChars as $uc) {
                    if ($uc->getCorporationId() === $character->getCorporationId()) {
                        $corpChars[] = $uc;
                    }
                }
                usort($corpChars, fn($a, $b) => $a->getId() <=> $b->getId());
                
                if (!empty($corpChars) && $corpChars[0]->getId() === $character->getId()) {
                    $personalHangars = $user->getPersonalCorpHangars();
                    $personalContainers = $user->getPersonalCorpContainers();

                    if (!empty($personalHangars) || !empty($personalContainers)) {
                        $corpAssets = $this->entityManager->getRepository(EveCorporationAsset::class)->findBy([
                            'corporationId' => $character->getCorporationId()
                        ]);

                        $corpAssetsByItemId = [];
                        foreach ($corpAssets as $ca) {
                            $corpAssetsByItemId[$ca->getItemId()] = $ca;
                        }

                        $corpNestedAssets = [];
                        foreach ($corpAssets as $ca) {
                            $parentId = $ca->getLocationId();
                            if (isset($corpAssetsByItemId[$parentId])) {
                                $corpNestedAssets[$parentId][] = $ca;
                            }
                        }

                        $personalRoots = [];
                        foreach ($personalHangars as $h) {
                            if ((int)$h['corporationId'] === $character->getCorporationId()) {
                                $locId = (int)$h['locationId'];
                                $flag = $h['locationFlag'];
                                foreach ($corpAssets as $ca) {
                                    if ($ca->getLocationId() === $locId && $ca->getLocationFlag() === $flag) {
                                        $personalRoots[] = $ca;
                                    }
                                }
                            }
                        }

                        foreach ($personalContainers as $c) {
                            if ((int)$c['corporationId'] === $character->getCorporationId()) {
                                $itemId = (int)$c['itemId'];
                                if (isset($corpAssetsByItemId[$itemId])) {
                                    $personalRoots[] = $corpAssetsByItemId[$itemId];
                                }
                            }
                        }

                        $calcVal = null;
                        $calcVal = function($ca, $nested) use (&$calcVal, $prices) {
                            $val = 0.0;
                            if (!($ca->isBlueprintCopy() ?? false)) {
                                $price = $prices[$ca->getTypeId()] ?? 0.0;
                                $val += ($price * $ca->getQuantity());
                            }
                            if (isset($nested[$ca->getItemId()])) {
                                foreach ($nested[$ca->getItemId()] as $child) {
                                    $val += $calcVal($child, $nested);
                                }
                            }
                            return $val;
                        };

                        $personalAssetVal = 0.0;
                        foreach ($personalRoots as $root) {
                            $personalAssetVal += $calcVal($root, $corpNestedAssets);
                        }

                        $totalAssetVal += $personalAssetVal;
                    }
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
        $totalPages = 1;

        while ($page <= $totalPages) {
            try {
                $response = $this->esiClient->requestWithHeaders(
                    'GET',
                    sprintf('corporations/%d/assets/', $corpId),
                    [
                        'query' => ['page' => $page]
                    ],
                    $character
                );

                if ($page === 1 && ($response['fromCache'] ?? false)) {
                    $this->logger->info(sprintf('[Cron] Corporation assets for corp %d are still cached. Skipping update.', $corpId));
                    return;
                }

                $assets = $response['data'];
                $headers = $response['headers'];

                if (empty($assets)) {
                    break;
                }

                $allAssets = array_merge($allAssets, $assets);

                if ($page === 1 && isset($headers['x-pages'][0])) {
                    $totalPages = (int)$headers['x-pages'][0];
                }

                $page++;
            } catch (\Symfony\Contracts\HttpClient\Exception\HttpExceptionInterface $e) {
                if ($e->getResponse()->getStatusCode() === 404) {
                    break;
                }
                throw $e;
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

    private function getTrackedTypeIds(): array
    {
        $listItems = $this->entityManager->getRepository(TrackingListItem::class)->findAll();
        $trackedTypeIds = [];
        foreach ($listItems as $item) {
            $trackedTypeIds[] = $item->getTypeId();
        }

        $sdeTypeIds = $this->sdeService->getPerformanceTypeIds();
        $trackedTypeIds = array_merge($trackedTypeIds, $sdeTypeIds);

        return array_values(array_unique(array_filter($trackedTypeIds)));
    }
}
