<?php

namespace App\Service\Cron;

use App\Entity\EveCharacter;
use App\Entity\EveCharacterAsset;
use App\Entity\EveCharacterAssetChange;
use App\Entity\EveCharacterValueSnapshot;
use App\Entity\EveCorporationAsset;
use App\Entity\EveCharacterMarketOrder;
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

            // Sync Wallet, Journal, Market Transactions & Orders
            try {
                $this->syncRoles($character);
                $this->syncWallet($character);
                $this->syncWalletJournal($character);
                $this->syncMarketTransactions($character);
                $this->syncMarketOrders($character);
            } catch (\Exception $e) {
                $this->logger->error(sprintf(
                    '[Cron] Failed to sync wallet/journal/orders for character %s (%d): %s',
                    $character->getName(),
                    $character->getId(),
                    $e->getMessage()
                ));
            }

            // Sync Skills, Skill Queue, Attributes & Implants
            try {
                $this->syncSkillsAttributesImplants($character);
            } catch (\Exception $e) {
                $this->logger->error(sprintf(
                    '[Cron] Failed to sync skills/attributes/implants for character %s (%d): %s',
                    $character->getName(),
                    $character->getId(),
                    $e->getMessage()
                ));
            }

            // Sync Corporation Assets first so they are updated in the DB before syncAssets reads them
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

        try {
            $response = $this->esiClient->requestAllPages(
                sprintf('characters/%d/assets/', $character->getId()),
                [],
                $character
            );

            if ($response['fromCache']) {
                $this->logger->info(sprintf('[Cron] Assets for character %s are still cached. Skipping update.', $character->getName()));
                return;
            }

            $allAssets = $response['data'];
        } catch (\Symfony\Contracts\HttpClient\Exception\HttpExceptionInterface $e) {
            if ($e->getResponse()->getStatusCode() === 404) {
                $allAssets = [];
            } else {
                throw $e;
            }
        }

        // Merge Personal Corporation Assets if this is the primary character for the corporation
        try {
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

                        $collectDescendants = null;
                        $collectDescendants = function($ca, $nested, &$collected) use (&$collectDescendants) {
                            $collected[] = $ca;
                            if (isset($nested[$ca->getItemId()])) {
                                foreach ($nested[$ca->getItemId()] as $child) {
                                    $collectDescendants($child, $nested, $collected);
                                }
                            }
                        };

                        $personalAssets = [];
                        foreach ($personalRoots as $root) {
                            $collectDescendants($root, $corpNestedAssets, $personalAssets);
                        }

                        $uniquePersonalAssets = [];
                        $seenItemIds = [];
                        foreach ($personalAssets as $ca) {
                            $itemId = $ca->getItemId();
                            if (!isset($seenItemIds[$itemId])) {
                                $uniquePersonalAssets[] = $ca;
                                $seenItemIds[$itemId] = true;
                            }
                        }
                        $personalAssets = $uniquePersonalAssets;

                        foreach ($personalAssets as $ca) {
                            $allAssets[] = [
                                'item_id' => $ca->getItemId(),
                                'type_id' => $ca->getTypeId(),
                                'quantity' => $ca->getQuantity(),
                                'location_id' => $ca->getLocationId(),
                                'location_type' => 'personal_corp_asset',
                                'location_flag' => $ca->getLocationFlag(),
                                'is_singleton' => (bool)$ca->isSingleton(),
                                'is_blueprint_copy' => (bool)$ca->isBlueprintCopy(),
                                'custom_name' => $ca->getCustomName(),
                                'material_efficiency' => $ca->getMaterialEfficiency(),
                                'time_efficiency' => $ca->getTimeEfficiency(),
                                'runs' => $ca->getRuns(),
                            ];
                        }
                    }
                }
            }
        } catch (\Exception $e) {
            $this->logger->error(sprintf('[Cron] Failed to fetch personal corp assets for character %s: %s', $character->getName(), $e->getMessage()));
        }

        // Append virtual assets for active industry job inputs so starting a job does not count as a loss/offset
        try {
            $activeJobs = $this->entityManager->getRepository(\App\Entity\EveCharacterIndustryJob::class)->findBy([
                'character' => $character,
                'status' => 'active'
            ]);
            foreach ($activeJobs as $job) {
                $details = $this->sdeService->getBlueprintDetails($job->getBlueprintTypeId(), $job->getActivityId());
                if (!empty($details['materials'])) {
                    foreach ($details['materials'] as $mat) {
                        $allAssets[] = [
                            'item_id' => 0, // virtual ID
                            'type_id' => (int)$mat['typeId'],
                            'quantity' => (int)$mat['quantity'] * $job->getRuns(),
                            'location_id' => (int)$job->getBlueprintLocationId(),
                            'location_type' => 'industry_job',
                            'location_flag' => 'IndustryJobInput',
                            'is_singleton' => false,
                        ];
                    }
                }
            }
        } catch (\Exception $e) {
            $this->logger->error(sprintf('[Cron] Failed to fetch active industry job inputs for character %s: %s', $character->getName(), $e->getMessage()));
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
        try {
            $bpResponse = $this->esiClient->requestAllPages(
                sprintf('characters/%d/blueprints/', $character->getId()),
                [],
                $character
            );
            foreach ($bpResponse['data'] as $bp) {
                $blueprintsMap[(int)$bp['item_id']] = [
                    'me' => (int)($bp['material_efficiency'] ?? 0),
                    'te' => (int)($bp['time_efficiency'] ?? 0),
                    'runs' => (int)($bp['runs'] ?? -1),
                ];
            }
        } catch (\Exception $e) {
            $this->logger->error(sprintf('[UpdateCharacterDataTask] Failed to fetch blueprints for character %d: %s', $character->getId(), $e->getMessage()));
        }

        // Perform asset database update in a transaction
        $this->entityManager->wrapInTransaction(function() use ($character, $allAssets, $namesMap, $blueprintsMap) {
            // A. Calculate asset changes (increases) for tracked items
            try {
                $trackedTypeIds = $this->getTrackedTypeIds();

                if ($character->getLastAssetsUpdate() !== null && !empty($trackedTypeIds)) {
                    $oldAssets = $this->assetRepository->findBy(['character' => $character]);
                    
                    // Safety check: If we have no old assets in the database, do not log any changes (treat as first sync/reset)
                    if (!empty($oldAssets)) {
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
                } elseif (isset($assetData['custom_name'])) {
                    $asset->setCustomName($assetData['custom_name']);
                }

                if (isset($blueprintsMap[$assetData['item_id']])) {
                    $asset->setMaterialEfficiency($blueprintsMap[$assetData['item_id']]['me']);
                    $asset->setTimeEfficiency($blueprintsMap[$assetData['item_id']]['te']);
                    $asset->setRuns($blueprintsMap[$assetData['item_id']]['runs']);
                } elseif (isset($assetData['material_efficiency'])) {
                    $asset->setMaterialEfficiency($assetData['material_efficiency']);
                    $asset->setTimeEfficiency($assetData['time_efficiency']);
                    $asset->setRuns($assetData['runs']);
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
                } elseif (isset($assetData['is_blueprint_copy'])) {
                    $isBpc = (bool)$assetData['is_blueprint_copy'];
                }
                
                if (!$isBpc) {
                    $price = $prices[$typeId] ?? 0.0;
                    $totalAssetVal += ($price * $qty);
                }
            }

            // Add active market orders value (escrow for buy orders, items valued at Jita buy for sell orders)
            $marketOrders = $this->entityManager->getRepository(EveCharacterMarketOrder::class)->findBy(['character' => $character]);
            foreach ($marketOrders as $order) {
                if ($order->isBuy()) {
                    $totalAssetVal += (float)($order->getEscrow() ?? 0.0);
                } else {
                    $typeId = $order->getTypeId();
                    $qty = $order->getVolumeRemain();
                    $jitaBuyPrice = null;
                    try {
                        $priceInfo = $this->jitaPriceService->getAverageJitaPrice($typeId, true);
                        $jitaBuyPrice = $priceInfo['price'];
                    } catch (\Exception $e) {
                        // Ignore
                    }
                    $price = $jitaBuyPrice ?? ($prices[$typeId] ?? 0.0);
                    $totalAssetVal += ($price * $qty);
                }
            }

            // Personal Corporation Assets are now merged into $allAssets at the beginning of syncAssets

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

        try {
            $response = $this->esiClient->requestAllPages(
                sprintf('corporations/%d/assets/', $corpId),
                [],
                $character
            );

            if ($response['fromCache']) {
                $this->logger->info(sprintf('[Cron] Corporation assets for corp %d are still cached. Skipping update.', $corpId));
                return;
            }

            $allAssets = $response['data'];
        } catch (\Symfony\Contracts\HttpClient\Exception\HttpExceptionInterface $e) {
            if ($e->getResponse()->getStatusCode() === 404) {
                $allAssets = [];
            } else {
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
        try {
            $bpResponse = $this->esiClient->requestAllPages(
                sprintf('corporations/%d/blueprints/', $corpId),
                [],
                $character
            );
            foreach ($bpResponse['data'] as $bp) {
                $blueprintsMap[(int)$bp['item_id']] = [
                    'me' => (int)($bp['material_efficiency'] ?? 0),
                    'te' => (int)($bp['time_efficiency'] ?? 0),
                    'runs' => (int)($bp['runs'] ?? -1),
                ];
            }
        } catch (\Exception $e) {
            $this->logger->error(sprintf('[UpdateCharacterDataTask] Failed to fetch corporation blueprints for corp %d: %s', $corpId, $e->getMessage()));
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

    private function syncSkillsAttributesImplants(EveCharacter $character): void
    {
        $this->logger->debug(sprintf('[Cron] Syncing skills, attributes, and implants for character %s...', $character->getName()));

        // 1. Fetch Skills
        try {
            $skillsData = $this->esiClient->request(
                'GET',
                sprintf('characters/%d/skills/', $character->getId()),
                [],
                $character
            );
            if (is_array($skillsData)) {
                $character->setSkills($skillsData);
            }
        } catch (\Exception $e) {
            $this->logger->error(sprintf('[Cron] Failed to fetch skills for character %s (%d): %s', $character->getName(), $character->getId(), $e->getMessage()));
        }

        // 2. Fetch Skill Queue
        try {
            $queueData = $this->esiClient->request(
                'GET',
                sprintf('characters/%d/skillqueue/', $character->getId()),
                [],
                $character
            );
            if (is_array($queueData)) {
                $character->setSkillQueue($queueData);
            }
        } catch (\Exception $e) {
            $this->logger->error(sprintf('[Cron] Failed to fetch skill queue for character %s (%d): %s', $character->getName(), $character->getId(), $e->getMessage()));
        }

        // 3. Fetch Attributes
        try {
            $attributesData = $this->esiClient->request(
                'GET',
                sprintf('characters/%d/attributes/', $character->getId()),
                [],
                $character
            );
            if (is_array($attributesData)) {
                $character->setAttributes($attributesData);
            }
        } catch (\Exception $e) {
            $this->logger->error(sprintf('[Cron] Failed to fetch attributes for character %s (%d): %s', $character->getName(), $character->getId(), $e->getMessage()));
        }

        // 4. Fetch Implants
        try {
            $implantsData = $this->esiClient->request(
                'GET',
                sprintf('characters/%d/implants/', $character->getId()),
                [],
                $character
            );
            if (is_array($implantsData)) {
                $character->setImplants($implantsData);
            }
        } catch (\Exception $e) {
            $this->logger->error(sprintf('[Cron] Failed to fetch implants for character %s (%d): %s', $character->getName(), $character->getId(), $e->getMessage()));
        }

        $this->entityManager->flush();
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

    private function syncMarketOrders(EveCharacter $character): void
    {
        $this->logger->debug(sprintf('[Cron] Syncing market orders for character %s...', $character->getName()));

        try {
            $response = $this->esiClient->requestAllPages(
                sprintf('characters/%d/orders/', $character->getId()),
                [],
                $character
            );

            if ($response['fromCache'] ?? false) {
                $this->logger->info(sprintf('[Cron] Market orders for character %s are still cached. Skipping update.', $character->getName()));
                return;
            }

            $ordersData = $response['data'];
        } catch (\Symfony\Contracts\HttpClient\Exception\HttpExceptionInterface $e) {
            if ($e->getResponse()->getStatusCode() === 404) {
                $ordersData = [];
            } else {
                throw $e;
            }
        }

        $this->entityManager->wrapInTransaction(function() use ($character, $ordersData) {
            // Delete all existing active market orders for this character
            $this->entityManager->createQueryBuilder()
                ->delete(EveCharacterMarketOrder::class, 'o')
                ->where('o.character = :character')
                ->setParameter('character', $character)
                ->getQuery()
                ->execute();

            $insertedCount = 0;
            foreach ($ordersData as $oData) {
                $order = new EveCharacterMarketOrder();
                $order->setCharacter($character);
                $order->setOrderId((string)$oData['order_id']);
                $order->setTypeId((int)$oData['type_id']);
                $order->setLocationId((string)$oData['location_id']);
                $order->setVolumeTotal((int)$oData['volume_total']);
                $order->setVolumeRemain((int)$oData['volume_remain']);
                $order->setPrice(number_format((float)$oData['price'], 2, '.', ''));
                if (isset($oData['escrow'])) {
                    $order->setEscrow(number_format((float)$oData['escrow'], 2, '.', ''));
                }
                $order->setIsBuy((bool)($oData['is_buy_order'] ?? false));
                $order->setIssued(new \DateTimeImmutable($oData['issued']));
                $order->setDuration((int)$oData['duration']);
                $order->setRange((string)$oData['range']);
                if (isset($oData['min_volume'])) {
                    $order->setMinVolume((int)$oData['min_volume']);
                }

                $this->entityManager->persist($order);
                $insertedCount++;
            }

            $this->entityManager->flush();

            if ($insertedCount > 0) {
                $this->logger->info(sprintf(
                    '[Cron] Successfully synchronized %d active market orders for character %s.',
                    $insertedCount,
                    $character->getName()
                ));
            }
        });
    }
}
