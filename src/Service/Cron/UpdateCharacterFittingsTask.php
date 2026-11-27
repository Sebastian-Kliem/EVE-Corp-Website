<?php

namespace App\Service\Cron;

use App\Entity\EveCharacter;
use App\Service\Esi\EsiMissingScopeException;
use App\Service\Fitting\FittingIngameSync;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;

/**
 * Imports the personal in-game fittings of all characters into the fitting library.
 */
class UpdateCharacterFittingsTask implements CronTaskInterface
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly FittingIngameSync $ingameSync,
        private readonly LoggerInterface $logger
    ) {}

    public function getCommandName(): string
    {
        return 'character:sync-fittings';
    }

    public function execute(): void
    {
        /** @var EveCharacter[] $characters */
        $characters = $this->entityManager->getRepository(EveCharacter::class)->findSyncableCharacters();
        $this->logger->info(sprintf('[Cron] Starting sync-fittings for %d characters.', count($characters)));

        foreach ($characters as $character) {
            if ($character->getUser() === null || !$this->ingameSync->canRead($character)) {
                continue;
            }

            try {
                $result = $this->ingameSync->importForCharacter($character);
                $this->logger->debug(sprintf('[Cron] Fittings of %s: %d in game, %d new, %d removed.', $character->getName(), $result['total'], $result['created'], $result['removed']));
            } catch (EsiMissingScopeException $e) {
                continue;
            } catch (\Exception $e) {
                $this->logger->error(sprintf('[Cron] Failed to sync fittings for character %s (%d): %s', $character->getName(), $character->getId(), $e->getMessage()));
            }
        }

        $this->logger->info('[Cron] Finished sync-fittings execution.');
    }
}
