<?php

namespace App\Service\Cron;

use App\Entity\EveCharacter;
use App\Entity\EveCorporationStructure;
use App\Entity\EveCorporationStarbase;
use App\Entity\EveStructure;
use App\Service\Discord\StructureAlertService;
use App\Service\Esi\EsiClient;
use App\Service\SdeService;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;

class UpdateCorporationStructuresTask implements CronTaskInterface
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly EsiClient $esiClient,
        private readonly SdeService $sdeService,
        private readonly StructureAlertService $structureAlertService,
        private readonly LoggerInterface $logger
    ) {}

    public function getCommandName(): string
    {
        return 'corporation:sync-structures';
    }

    public function execute(): void
    {
        $characterRepository = $this->entityManager->getRepository(EveCharacter::class);
        /** @var EveCharacter[] $allCharacters */
        $allCharacters = $characterRepository->findAll();

        // 1. Group active director characters by corporation
        $directorsByCorp = [];
        foreach ($allCharacters as $char) {
            if (empty($char->getRefreshToken()) || !$char->isTokenValid()) {
                continue;
            }

            $corpId = $char->getCorporationId();
            if ($corpId && $char->isDirector()) {
                $directorsByCorp[$corpId][] = $char;
            }
        }

        $this->logger->info(sprintf('[Cron] Starting corporation structures sync for %d corporations with directors.', count($directorsByCorp)));

        foreach ($directorsByCorp as $corpId => $directors) {
            // Use the first director character to fetch data
            $director = $directors[0];

            $this->logger->info(sprintf('[Cron] Syncing structures/starbases for corp %d using director %s...', $corpId, $director->getName()));

            // A. Sync Upwell Structures
            try {
                $this->syncUpwellStructures($corpId, $director);
            } catch (\Exception $e) {
                $this->logger->error(sprintf(
                    '[Cron] Failed to sync Upwell structures for corp %d using director %s: %s',
                    $corpId,
                    $director->getName(),
                    $e->getMessage()
                ));
            }

            // B. Sync Starbases (POS)
            try {
                $this->syncStarbases($corpId, $director);
            } catch (\Exception $e) {
                $this->logger->error(sprintf(
                    '[Cron] Failed to sync Starbases for corp %d using director %s: %s',
                    $corpId,
                    $director->getName(),
                    $e->getMessage()
                ));
            }
        }

        $this->logger->info('[Cron] Finished corporation structures sync execution.');
    }

    private function syncUpwellStructures(int $corpId, EveCharacter $director): void
    {
        $structuresData = $this->esiClient->request(
            'GET',
            sprintf('corporations/%d/structures/', $corpId),
            [],
            $director
        );

        if (!is_array($structuresData)) {
            $this->logger->warning(sprintf('[Cron] ESI returned invalid structures data for corp %d.', $corpId));
            return;
        }

        $structureRepo = $this->entityManager->getRepository(EveCorporationStructure::class);
        $globalStructureRepo = $this->entityManager->getRepository(EveStructure::class);
        $now = new \DateTimeImmutable();

        $syncedStructureIds = [];
        foreach ($structuresData as $sData) {
            $structureId = (string)$sData['structure_id'];
            $structIdInt = (int)$structureId;

            // Skip NPC stations (typically IDs between 60000000 and 64000000, or < 1000000000000) where offices might be rented
            if ($structIdInt < 1000000000000) {
                continue;
            }

            $syncedStructureIds[] = $structureId;
            $typeId = (int)$sData['type_id'];
            $systemId = (int)$sData['system_id'];
            $name = $sData['name'] ?? null;
            $state = $sData['state'] ?? 'unknown';
            $reinforceHour = isset($sData['reinforce_hour']) ? (int)$sData['reinforce_hour'] : null;

            $fuelExpires = null;
            if (isset($sData['fuel_expires'])) {
                try {
                    $fuelExpires = new \DateTimeImmutable($sData['fuel_expires']);
                } catch (\Exception $e) {
                    // Keep null
                }
            }

            $services = [];
            if (isset($sData['services']) && is_array($sData['services'])) {
                foreach ($sData['services'] as $service) {
                    $services[] = [
                        'name' => $service['name'] ?? 'unknown',
                        'state' => $service['state'] ?? 'unknown',
                    ];
                }
            }

            // Find or create corp structure
            $structure = $structureRepo->find($structureId);
            if (!$structure) {
                $structure = new EveCorporationStructure();
                $structure->setId($structureId);
            }

            $structure->setCorporationId((string)$corpId);
            $structure->setName($name);
            $structure->setTypeId($typeId);
            $structure->setTypeName($this->sdeService->getItemName($typeId));
            $structure->setSolarSystemId($systemId);
            $structure->setSolarSystemName($this->sdeService->getLocationName($systemId));
            $structure->setState($state);
            $structure->setFuelExpires($fuelExpires);
            $structure->setServices($services);
            $structure->setReinforceHour($reinforceHour);
            $structure->setLastUpdated($now);

            // Check fuel warnings and state transitions
            $this->structureAlertService->checkUpwellStructure($structure);

            $this->entityManager->persist($structure);

            // Also populate the global location cache (EveStructure) so this known location is available
            // to others resolving this location (e.g. in asset overviews) without querying ESI universe endpoint
            if ($name) {
                $globalStructure = $globalStructureRepo->find($structureId);
                if (!$globalStructure) {
                    $globalStructure = new EveStructure();
                    $globalStructure->setId($structureId);
                }
                $globalStructure->setName($name);
                $globalStructure->setSolarSystemId($systemId);
                $globalStructure->setSolarSystemName($structure->getSolarSystemName());
                $globalStructure->setOwnerId((string)$corpId);
                
                // Fetch corp owner name if possible (or keep null / default)
                if ($director->getAccount() && $director->getAccount()->getName()) {
                    $globalStructure->setOwnerName($director->getAccount()->getName());
                }

                $globalStructure->setLastUpdated($now);
                $this->entityManager->persist($globalStructure);
            }
        }

        // Clean up structures that no longer exist for this corp in ESI
        $existingStructures = $structureRepo->findBy(['corporationId' => (string)$corpId]);
        foreach ($existingStructures as $existing) {
            if (!in_array($existing->getId(), $syncedStructureIds, true)) {
                $this->entityManager->remove($existing);
            }
        }

        $this->entityManager->flush();
        $this->logger->info(sprintf('[Cron] Successfully updated %d Upwell structures for corp %d.', count($structuresData), $corpId));
    }

    private function syncStarbases(int $corpId, EveCharacter $director): void
    {
        $starbasesData = $this->esiClient->request(
            'GET',
            sprintf('corporations/%d/starbases/', $corpId),
            [],
            $director
        );

        if (!is_array($starbasesData)) {
            $this->logger->warning(sprintf('[Cron] ESI returned invalid starbases data for corp %d.', $corpId));
            return;
        }

        $starbaseRepo = $this->entityManager->getRepository(EveCorporationStarbase::class);
        $now = new \DateTimeImmutable();

        $syncedStarbaseIds = [];
        foreach ($starbasesData as $sData) {
            $starbaseId = (string)$sData['starbase_id'];
            $syncedStarbaseIds[] = $starbaseId;
            $typeId = (int)$sData['type_id'];
            $systemId = (int)$sData['system_id'];
            $state = $sData['state'] ?? 'offline';

            // Find or create starbase record
            $starbase = $starbaseRepo->find($starbaseId);
            if (!$starbase) {
                $starbase = new EveCorporationStarbase();
                $starbase->setId($starbaseId);
            }

            $starbase->setCorporationId((string)$corpId);
            $starbase->setTypeId($typeId);
            $starbase->setTypeName($this->sdeService->getItemName($typeId));
            $starbase->setSolarSystemId($systemId);
            $starbase->setSolarSystemName($this->sdeService->getLocationName($systemId));
            $starbase->setState($state);
            $starbase->setLastUpdated($now);

            // Fetch starbase details (requires role and specific system_id query parameter)
            try {
                $details = $this->esiClient->request(
                    'GET',
                    sprintf('corporations/%d/starbases/%s/', $corpId, $starbaseId),
                    [
                        'query' => ['system_id' => $systemId]
                    ],
                    $director
                );

                if (is_array($details)) {
                    $fuels = [];
                    if (isset($details['fuels']) && is_array($details['fuels'])) {
                        foreach ($details['fuels'] as $f) {
                            $fuels[] = [
                                'typeId' => (int)$f['type_id'],
                                'typeName' => $this->sdeService->getItemName((int)$f['type_id']),
                                'quantity' => (int)$f['quantity'],
                            ];
                        }
                    }
                    $starbase->setFuels($fuels);

                    if (isset($details['use_alliance_standings'])) {
                        // Details can also include onlined_since / reinforced_until at times, or settings
                    }
                }
            } catch (\Exception $e) {
                $this->logger->warning(sprintf(
                    '[Cron] Could not fetch details for starbase %s of corp %d: %s',
                    $starbaseId,
                    $corpId,
                    $e->getMessage()
                ));
            }

            // Optional: resolve POS modules from corporation assets in the same solar system
            // In EVE, POS modules have groupIDs like:
            // Sentry Gun, Battery, Shield Hardener, Silo, Assembly Array, Laboratory, etc.
            // We can search the database for EveCorporationAsset objects in this corporation
            // that are POS modules and located in the same solarSystemId.
            // For now, we leave the modules field empty or allow it to be hydrated later.

            // Check fuel warnings and state transitions
            $this->structureAlertService->checkStarbase($starbase);

            $this->entityManager->persist($starbase);
        }

        // Clean up starbases that no longer exist for this corp in ESI
        $existingStarbases = $starbaseRepo->findBy(['corporationId' => (string)$corpId]);
        foreach ($existingStarbases as $existing) {
            if (!in_array($existing->getId(), $syncedStarbaseIds, true)) {
                $this->entityManager->remove($existing);
            }
        }

        $this->entityManager->flush();
        $this->logger->info(sprintf('[Cron] Successfully updated %d starbases for corp %d.', count($starbasesData), $corpId));
    }
}
