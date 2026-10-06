<?php

namespace App\Service\CharacterSync;

use App\Entity\EveCharacter;
use App\Entity\EveCharacterAsset;
use App\Entity\EveCharacterAssetChange;
use App\Entity\EveCharacterIndustryJob;
use App\Entity\EveCorporationAsset;
use App\Entity\TrackingListItem;
use App\Repository\EveCharacterAssetRepository;
use App\Service\Esi\EsiClient;
use App\Service\PersonalCorpAssetService;
use App\Service\SdeService;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;

/**
 * Replaces a character's stored assets with the current ESI state, including personal corp assets
 * and the inputs of active industry jobs, logs changes of tracked items and saves the value snapshot.
 */
class CharacterAssetSyncService
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly EsiClient $esiClient,
        private readonly EveCharacterAssetRepository $assetRepository,
        private readonly SdeService $sdeService,
        private readonly PersonalCorpAssetService $personalCorpAssetService,
        private readonly AssetDetailsFetcher $assetDetailsFetcher,
        private readonly CharacterValueSnapshotService $valueSnapshotService,
        private readonly LoggerInterface $logger
    ) {}

    public function sync(EveCharacter $character): void
    {
        $this->logger->debug(sprintf('[Cron] Syncing assets for character %s...', $character->getName()));

        $allAssets = $this->_fetchAssets($character);
        if ($allAssets === null) {
            return;
        }

        foreach ($this->_collectPersonalCorpAssets($character) as $personalCorpAsset) {
            $allAssets[] = $personalCorpAsset;
        }
        foreach ($this->_collectIndustryJobInputs($character) as $jobInput) {
            $allAssets[] = $jobInput;
        }

        $namesMap = $this->assetDetailsFetcher->fetchNames(
            sprintf('characters/%d/assets/names/', $character->getId()),
            $character,
            $this->assetDetailsFetcher->collectCustomizableItemIds($allAssets),
            sprintf('character asset names for %s', $character->getName())
        );
        $blueprintsMap = $this->assetDetailsFetcher->fetchBlueprints(
            sprintf('characters/%d/blueprints/', $character->getId()),
            $character,
            sprintf('blueprints for character %d', $character->getId())
        );

        $this->_replaceAssets($character, $allAssets, $namesMap, $blueprintsMap);

        // 3. Save daily value snapshot
        $this->valueSnapshotService->saveDailySnapshot($character, $allAssets, $blueprintsMap);

        $this->logger->info(sprintf(
            '[Cron] Successfully updated %d assets for character %s.',
            count($allAssets),
            $character->getName()
        ));
    }

    /**
     * @return array<int, array<string, mixed>>|null ESI asset rows, null while the ESI response is still cached
     */
    private function _fetchAssets(EveCharacter $character): ?array
    {
        try {
            $response = $this->esiClient->requestAllPages(
                sprintf('characters/%d/assets/', $character->getId()),
                [],
                $character
            );

            if ($response['fromCache']) {
                $this->logger->info(sprintf('[Cron] Assets for character %s are still cached. Skipping update.', $character->getName()));
                return null;
            }

            return $response['data'];
        } catch (\Symfony\Contracts\HttpClient\Exception\HttpExceptionInterface $e) {
            if ($e->getResponse()->getStatusCode() === 404) {
                return [];
            }
            throw $e;
        }
    }

    /**
     * Merge Personal Corporation Assets if this is the primary character for the corporation
     *
     * @return list<array<string, mixed>>
     */
    private function _collectPersonalCorpAssets(EveCharacter $character): array
    {
        $personalAssetRows = [];
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

                        $resolvedCorp = $this->personalCorpAssetService->resolvePersonalCorpAssets(
                            (int)$character->getCorporationId(),
                            $corpAssets,
                            $personalHangars,
                            $personalContainers
                        );

                        $personalAssets = [];
                        foreach ($resolvedCorp['roots'] as $root) {
                            $this->_collectDescendants($root, $resolvedCorp['nested'], $personalAssets);
                        }

                        $seenItemIds = [];
                        foreach ($personalAssets as $ca) {
                            $itemId = $ca->getItemId();
                            if (isset($seenItemIds[$itemId])) {
                                continue;
                            }
                            $seenItemIds[$itemId] = true;

                            $personalAssetRows[] = [
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

        return $personalAssetRows;
    }

    /**
     * @param array<int, list<EveCorporationAsset>> $nested Parent item ID => child assets
     * @param list<EveCorporationAsset> $collected
     */
    private function _collectDescendants(EveCorporationAsset $corpAsset, array $nested, array &$collected): void
    {
        $collected[] = $corpAsset;
        if (isset($nested[$corpAsset->getItemId()])) {
            foreach ($nested[$corpAsset->getItemId()] as $child) {
                $this->_collectDescendants($child, $nested, $collected);
            }
        }
    }

    /**
     * Append virtual assets for active industry job inputs so starting a job does not count as a loss/offset
     *
     * @return list<array<string, mixed>>
     */
    private function _collectIndustryJobInputs(EveCharacter $character): array
    {
        $jobInputRows = [];
        try {
            $activeJobs = $this->entityManager->getRepository(EveCharacterIndustryJob::class)->findBy([
                'character' => $character,
                'status' => 'active'
            ]);
            foreach ($activeJobs as $job) {
                $details = $this->sdeService->getBlueprintDetails($job->getBlueprintTypeId(), $job->getActivityId());
                if (!empty($details['materials'])) {
                    foreach ($details['materials'] as $mat) {
                        $jobInputRows[] = [
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

        return $jobInputRows;
    }

    // Perform asset database update in a transaction
    private function _replaceAssets(EveCharacter $character, array $allAssets, array $namesMap, array $blueprintsMap): void
    {
        $this->entityManager->wrapInTransaction(function() use ($character, $allAssets, $namesMap, $blueprintsMap) {
            // A. Calculate asset changes (increases) for tracked items
            try {
                $this->_logTrackedAssetChanges($character, $allAssets);
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
    }

    private function _logTrackedAssetChanges(EveCharacter $character, array $allAssets): void
    {
        $trackedTypeIds = $this->_getTrackedTypeIds();

        if ($character->getLastAssetsUpdate() === null || empty($trackedTypeIds)) {
            return;
        }

        // Plain rows instead of entities: managed old assets would be flushed as bogus UPDATEs after the delete below
        $oldAssets = $this->assetRepository->findTypeQuantitiesForCharacter($character);

        // Safety check: If we have no old assets in the database, do not log any changes (treat as first sync/reset)
        if (empty($oldAssets)) {
            return;
        }

        $oldQuantities = [];
        foreach ($oldAssets as $oldAsset) {
            $tid = $oldAsset['typeId'];
            if (in_array($tid, $trackedTypeIds, true)) {
                $oldQuantities[$tid] = ($oldQuantities[$tid] ?? 0) + $oldAsset['quantity'];
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

    private function _getTrackedTypeIds(): array
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
