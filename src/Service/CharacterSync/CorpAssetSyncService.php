<?php

namespace App\Service\CharacterSync;

use App\Entity\EveCharacter;
use App\Entity\EveCorporationAsset;
use App\Repository\EveCorporationAssetRepository;
use App\Service\Esi\EsiClient;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;

/**
 * Replaces a corporation's stored assets with the current ESI state, using a character with the needed roles.
 */
class CorpAssetSyncService
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly EsiClient $esiClient,
        private readonly EveCorporationAssetRepository $corpAssetRepository,
        private readonly AssetDetailsFetcher $assetDetailsFetcher,
        private readonly LoggerInterface $logger
    ) {}

    public function sync(EveCharacter $character): void
    {
        $corpId = $character->getCorporationId();
        if (!$corpId || $character->isInNpcCorporation()) {
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

        $namesMap = $this->assetDetailsFetcher->fetchNames(
            sprintf('corporations/%d/assets/names/', $corpId),
            $character,
            $this->assetDetailsFetcher->collectCustomizableItemIds($allAssets),
            sprintf('corporation asset names for corp %d using %s', $corpId, $character->getName())
        );
        $blueprintsMap = $this->assetDetailsFetcher->fetchBlueprints(
            sprintf('corporations/%d/blueprints/', $corpId),
            $character,
            sprintf('corporation blueprints for corp %d', $corpId)
        );

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
}
