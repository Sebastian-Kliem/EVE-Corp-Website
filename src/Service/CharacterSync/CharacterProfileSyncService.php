<?php

namespace App\Service\CharacterSync;

use App\Entity\EveCharacter;
use App\Service\Esi\EsiClient;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;

/**
 * Syncs a character's affiliation, corporation roles, skills, attributes and implants from ESI.
 */
class CharacterProfileSyncService
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly EsiClient $esiClient,
        private readonly LoggerInterface $logger
    ) {}

    public function syncRoles(EveCharacter $character): void
    {
        $this->logger->debug(sprintf('[Cron] Syncing roles and affiliation for character %s...', $character->getName()));

        // Refresh public character affiliation (corporation and alliance)
        try {
            $charData = $this->esiClient->request(
                'GET',
                sprintf('characters/%d/', $character->getId())
            );
            if (is_array($charData) && !empty($charData['corporation_id'])) {
                $character->setCorporationId($charData['corporation_id']);
                $character->setAllianceId($charData['alliance_id'] ?? null);
            }
        } catch (\Exception $e) {
            $this->logger->warning(sprintf(
                '[Cron] Could not refresh public affiliation for character %s (%d): %s',
                $character->getName(),
                $character->getId(),
                $e->getMessage()
            ));
        }

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

    public function syncSkillsAttributesImplants(EveCharacter $character): void
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
}
