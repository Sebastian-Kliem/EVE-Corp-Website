<?php

namespace App\Service\Cron;

use App\Entity\EveCharacter;
use App\Entity\EveCharacterAsset;
use App\Entity\EveCorporationAsset;
use App\Repository\EveCharacterAssetRepository;
use App\Repository\EveCorporationAssetRepository;
use App\Service\Esi\EsiClient;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;

class UpdateCharacterDataTask implements CronTaskInterface
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly EsiClient $esiClient,
        private readonly EveCharacterAssetRepository $assetRepository,
        private readonly EveCorporationAssetRepository $corpAssetRepository,
        private readonly LoggerInterface $logger
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

        // Perform asset database update in a transaction
        $this->entityManager->wrapInTransaction(function() use ($character, $allAssets) {
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

                $this->entityManager->persist($asset);
                
                $i++;
                if (($i % $batchSize) === 0) {
                    $this->entityManager->flush();
                }
            }

            $character->setLastAssetsUpdate(new \DateTimeImmutable());
            $this->entityManager->flush();
        });

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

        // Perform asset database update in a transaction
        $this->entityManager->wrapInTransaction(function() use ($corpId, $allAssets, $character) {
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
