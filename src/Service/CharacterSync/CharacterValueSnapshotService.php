<?php

namespace App\Service\CharacterSync;

use App\Entity\EveCharacter;
use App\Entity\EveCharacterValueSnapshot;
use App\Service\JitaPriceService;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;

/**
 * Stores the daily wallet, asset, market order and contract value of a character for the value history.
 */
class CharacterValueSnapshotService
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly JitaPriceService $jitaPriceService,
        private readonly CharacterMarketContractValueService $marketContractValueService,
        private readonly LoggerInterface $logger
    ) {}

    /**
     * @param array<int, array<string, mixed>> $allAssets Asset rows as stored by the asset sync
     * @param array<int, array{me: int, te: int, runs: int}> $blueprintsMap Item ID => blueprint stats
     */
    public function saveDailySnapshot(EveCharacter $character, array $allAssets, array $blueprintsMap): void
    {
        try {
            $today = new \DateTimeImmutable('today');

            $totalAssetVal = $this->_calculateAssetsValue($allAssets, $blueprintsMap);
            $marketValue = $this->marketContractValueService->calculateMarketValue($character)['total'];
            $contractsValue = $this->marketContractValueService->calculateContractsValue($character);

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
            $valSnapshot->setMarketValue(number_format($marketValue, 2, '.', ''));
            $valSnapshot->setContractsValue(number_format($contractsValue, 2, '.', ''));

            $this->entityManager->persist($valSnapshot);
            $this->entityManager->flush();

            $this->logger->info(sprintf(
                '[Cron] Successfully saved value snapshot for character %s. Wallet: %f, Assets: %f, Market: %f, Contracts: %f',
                $character->getName(),
                $walletBalance,
                $totalAssetVal,
                $marketValue,
                $contractsValue
            ));
        } catch (\Exception $e) {
            $this->logger->error(sprintf(
                '[Cron] Failed to save value snapshot for character %s: %s',
                $character->getName(),
                $e->getMessage()
            ));
        }
    }

    // Calculate total asset value using global prices
    private function _calculateAssetsValue(array $allAssets, array $blueprintsMap): float
    {
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

        return $totalAssetVal;
    }
}
