<?php

namespace App\Service\Cron;

use App\Entity\EveCharacter;
use App\Entity\EveKillmail;
use App\Service\Esi\EsiClient;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;

class UpdateCharacterKillmailsTask implements CronTaskInterface
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly EsiClient $esiClient,
        private readonly LoggerInterface $logger
    ) {}

    public function getCommandName(): string
    {
        return 'character:sync-killmails';
    }

    public function execute(): void
    {
        $characterRepository = $this->entityManager->getRepository(EveCharacter::class);
        /** @var EveCharacter[] $characters */
        $characters = $characterRepository->findAll();

        $this->logger->info(sprintf('[Cron] Starting sync-killmails for %d characters.', count($characters)));

        foreach ($characters as $character) {
            if (empty($character->getRefreshToken())) {
                continue;
            }

            try {
                $this->syncKillmails($character);
            } catch (\Exception $e) {
                $this->logger->error(sprintf(
                    '[Cron] Failed to sync killmails for character %s (%d): %s',
                    $character->getName(),
                    $character->getId(),
                    $e->getMessage()
                ));
            }
        }

        $this->logger->info('[Cron] Finished sync-killmails execution.');
    }

    private function syncKillmails(EveCharacter $character): void
    {
        $this->logger->debug(sprintf('[Cron] Syncing killmails for character %s...', $character->getName()));

        try {
            // Fetch recent killmails list from ESI
            // ESI returns an array of { killmail_id, killmail_hash }
            $response = $this->esiClient->request(
                'GET',
                sprintf('characters/%d/killmails/recent/', $character->getId()),
                [],
                $character
            );
        } catch (\Symfony\Contracts\HttpClient\Exception\HttpExceptionInterface $e) {
            // If character does not have active/correct scopes, ESI returns 403 Forbidden.
            if ($e->getResponse()->getStatusCode() === 403) {
                $this->logger->warning(sprintf('[Cron] Character %s lacks permission/scopes for killmails.', $character->getName()));
                return;
            }
            throw $e;
        }

        if (empty($response) || !is_array($response)) {
            $character->setLastKillmailsUpdate(new \DateTimeImmutable());
            $this->entityManager->flush();
            $this->logger->info(sprintf('[Cron] No killmails found for character %s.', $character->getName()));
            return;
        }

        // Fetch recent stored killmail IDs to check for duplicates efficiently
        $killmailRepository = $this->entityManager->getRepository(EveKillmail::class);
        $existingKillmails = $killmailRepository->findBy(
            ['character' => $character],
            ['killmailTime' => 'DESC'],
            100
        );
        $existingIds = array_map(fn(EveKillmail $k) => (string)$k->getKillmailId(), $existingKillmails);

        $newKillmailsCount = 0;
        $batchSize = 25;
        $i = 0;

        foreach ($response as $recent) {
            $killmailId = (string)$recent['killmail_id'];
            $killmailHash = $recent['killmail_hash'];

            // Since ESI returns from newest to oldest, we can stop importing once we hit an already imported killmail.
            if (in_array($killmailId, $existingIds, true)) {
                $this->logger->debug(sprintf('[Cron] Hit already imported killmail %s for character %s. Stopping sync.', $killmailId, $character->getName()));
                break;
            }

            try {
                // Fetch full killmail details
                $details = $this->esiClient->request(
                    'GET',
                    sprintf('killmails/%s/%s/', $killmailId, $killmailHash),
                    [],
                    $character
                );

                if (empty($details) || !is_array($details)) {
                    continue;
                }

                $killmail = new EveKillmail();
                $killmail->setCharacter($character);
                $killmail->setKillmailId($killmailId);
                $killmail->setKillmailHash($killmailHash);
                
                $time = new \DateTimeImmutable($details['killmail_time']);
                $killmail->setKillmailTime($time);
                $killmail->setSolarSystemId((int)$details['solar_system_id']);

                // Parse victim details
                $victim = $details['victim'] ?? [];
                $killmail->setVictimCharacterId(isset($victim['character_id']) ? (int)$victim['character_id'] : null);
                $killmail->setVictimCorporationId(isset($victim['corporation_id']) ? (int)$victim['corporation_id'] : null);
                $killmail->setVictimAllianceId(isset($victim['alliance_id']) ? (int)$victim['alliance_id'] : null);
                $killmail->setVictimShipTypeId(isset($victim['ship_type_id']) ? (int)$victim['ship_type_id'] : null);

                // Determine if it is a loss or a kill
                $isLoss = false;
                if (isset($victim['character_id']) && (int)$victim['character_id'] === $character->getId()) {
                    $isLoss = true;
                }
                $killmail->setIsLoss($isLoss);

                $isKill = false;
                $attackers = $details['attackers'] ?? [];
                foreach ($attackers as $attacker) {
                    if (isset($attacker['character_id']) && (int)$attacker['character_id'] === $character->getId()) {
                        $isKill = true;
                        break;
                    }
                }
                $killmail->setIsKill($isKill);
                
                $killmail->setData($details);

                $this->entityManager->persist($killmail);
                $newKillmailsCount++;
                $i++;

                if (($i % $batchSize) === 0) {
                    $this->entityManager->flush();
                }
            } catch (\Exception $e) {
                $this->logger->error(sprintf('[Cron] Failed to fetch details for killmail %s: %s', $killmailId, $e->getMessage()));
            }
        }

        $character->setLastKillmailsUpdate(new \DateTimeImmutable());
        $this->entityManager->flush();

        if ($newKillmailsCount > 0) {
            $this->logger->info(sprintf('[Cron] Synchronized %d new killmails for character %s.', $newKillmailsCount, $character->getName()));
        }
    }
}
