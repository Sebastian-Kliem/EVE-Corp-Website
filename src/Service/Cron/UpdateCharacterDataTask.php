<?php

namespace App\Service\Cron;

use App\Entity\EveCharacter;
use App\Service\CharacterSync\CharacterAssetSyncService;
use App\Service\CharacterSync\CharacterProfileSyncService;
use App\Service\CharacterSync\CorpAssetSyncService;
use App\Service\CharacterSync\MarketOrderSyncService;
use App\Service\CharacterSync\WalletSyncService;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ManagerRegistry;
use Psr\Log\LoggerInterface;

/**
 * Runs the per-character ESI syncs (profile, wallet, orders, corp and character assets) in their required order.
 */
class UpdateCharacterDataTask implements CronTaskInterface
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private readonly ManagerRegistry $doctrine,
        private readonly CharacterProfileSyncService $profileSyncService,
        private readonly WalletSyncService $walletSyncService,
        private readonly MarketOrderSyncService $marketOrderSyncService,
        private readonly CorpAssetSyncService $corpAssetSyncService,
        private readonly CharacterAssetSyncService $characterAssetSyncService,
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
        $characters = $characterRepository->findSyncableCharacters();

        $this->logger->info(sprintf('[Cron] Starting sync-wallet-assets for %d characters.', count($characters)));

        $characterIds = [];
        foreach ($characters as $character) {
            $characterIds[] = $character->getId();
        }

        $syncedCorpIds = [];

        foreach ($characterIds as $characterId) {
            // A failed flush closes the EntityManager, which must not block the remaining characters
            $character = $this->_loadCharacter($characterId);
            if ($character === null) {
                continue;
            }

            if (empty($character->getRefreshToken())) {
                $this->logger->warning(sprintf('[Cron] Skipping character %s (%d): No refresh token.', $character->getName(), $character->getId()));
                continue;
            }

            // Sync Wallet, Journal, Market Transactions & Orders
            try {
                $this->profileSyncService->syncRoles($character);
                $this->walletSyncService->syncBalance($character);
                $this->walletSyncService->syncJournal($character);
                $this->walletSyncService->syncMarketTransactions($character);
                $this->marketOrderSyncService->sync($character);
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
                $this->profileSyncService->syncSkillsAttributesImplants($character);
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
                    $this->corpAssetSyncService->sync($character);
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
                $this->characterAssetSyncService->sync($character);
            } catch (\Exception $e) {
                $this->logger->error(sprintf(
                    '[Cron] Failed to sync assets for character %s (%d): %s',
                    $character->getName(),
                    $character->getId(),
                    $e->getMessage()
                ));
            }
        }

        $this->_ensureEntityManagerOpen();
        $this->logger->info('[Cron] Finished sync-wallet-assets execution.');
    }

    private function _loadCharacter(int $characterId): ?EveCharacter
    {
        if ($this->entityManager->isOpen()) {
            return $this->entityManager->find(EveCharacter::class, $characterId);
        }

        $this->logger->warning(sprintf('[Cron] EntityManager was closed by a failed write. Resetting before character %d.', $characterId));
        $this->_ensureEntityManagerOpen();

        return $this->entityManager->find(EveCharacter::class, $characterId);
    }

    private function _ensureEntityManagerOpen(): void
    {
        if ($this->entityManager->isOpen()) {
            return;
        }

        $entityManager = $this->doctrine->resetManager();
        if (!$entityManager instanceof EntityManagerInterface) {
            throw new \LogicException('Default Doctrine manager is not an ORM EntityManager.');
        }
        $this->entityManager = $entityManager;
    }
}
