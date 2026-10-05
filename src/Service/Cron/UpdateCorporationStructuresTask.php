<?php

namespace App\Service\Cron;

use App\Entity\EveCharacter;
use App\Entity\EveCorporationStructure;
use App\Entity\EveCorporationStarbase;
use App\Entity\EveStructure;
use App\Service\Discord\StructureAlertService;
use App\Service\Esi\CorporationAccessResolver;
use App\Service\Esi\EsiClient;
use App\Service\Esi\EsiMissingScopeException;
use App\Service\SdeService;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ManagerRegistry;
use Psr\Log\LoggerInterface;
use Symfony\Contracts\HttpClient\Exception\HttpExceptionInterface;

class UpdateCorporationStructuresTask implements CronTaskInterface
{
    // Requirements from the ESI spec; Director is always accepted by the resolver
    private const STRUCTURE_ROLES = ['Station_Manager'];
    private const STRUCTURE_SCOPE = 'esi-corporations.read_structures.v1';
    private const STARBASE_ROLES = [];
    private const STARBASE_SCOPE = 'esi-corporations.read_starbases.v1';

    private EntityManagerInterface $entityManager;
    private array $corporationNames = [];

    public function __construct(
        private readonly ManagerRegistry $doctrine,
        EntityManagerInterface $entityManager,
        private readonly EsiClient $esiClient,
        private readonly SdeService $sdeService,
        private readonly StructureAlertService $structureAlertService,
        private readonly CorporationAccessResolver $corporationAccessResolver,
        private readonly LoggerInterface $logger
    ) {
        $this->entityManager = $entityManager;
    }

    public function getCommandName(): string
    {
        return 'corporation:sync-structures';
    }

    public function execute(): void
    {
        $structureCharacters = $this->corporationAccessResolver->getCharactersByCorporation(self::STRUCTURE_ROLES, self::STRUCTURE_SCOPE);
        $starbaseCharacters = $this->corporationAccessResolver->getCharactersByCorporation(self::STARBASE_ROLES, self::STARBASE_SCOPE);
        $corporationIds = array_unique(array_merge(array_keys($structureCharacters), array_keys($starbaseCharacters)));

        $this->logger->info(sprintf('[Cron] Starting corporation structures sync for %d corporations with authorized characters.', count($corporationIds)));

        foreach ($corporationIds as $corpId) {
            $this->ensureEntityManagerOpen();
            $this->_syncWithFirstAuthorizedCharacter($corpId, 'Upwell structures', $structureCharacters[$corpId] ?? [], $this->syncUpwellStructures(...));

            $this->ensureEntityManagerOpen();
            $this->_syncWithFirstAuthorizedCharacter($corpId, 'starbases', $starbaseCharacters[$corpId] ?? [], $this->syncStarbases(...));
        }

        $this->logger->info('[Cron] Finished corporation structures sync execution.');
    }

    /**
     * Tries the corporation's authorized characters in order until one can read the endpoint.
     *
     * @param EveCharacter[] $characters
     */
    private function _syncWithFirstAuthorizedCharacter(int $corpId, string $label, array $characters, callable $sync): void
    {
        if (empty($characters)) {
            $this->logger->info(sprintf('[Cron] No character with the required role and scope for %s of corp %d.', $label, $corpId));
            return;
        }

        foreach ($characters as $character) {
            $this->logger->info(sprintf('[Cron] Syncing %s for corp %d using %s...', $label, $corpId, $character->getName()));
            try {
                $sync($corpId, $character);
                return;
            } catch (EsiMissingScopeException $e) {
                $this->logger->warning(sprintf('[Cron] %s cannot read %s of corp %d: %s Trying next character.', $character->getName(), $label, $corpId, $e->getMessage()));
            } catch (HttpExceptionInterface $e) {
                // 403 means the stored roles are outdated; any other error would hit the next character as well
                if ($e->getResponse()->getStatusCode() !== 403) {
                    $this->logger->error(sprintf('[Cron] Failed to sync %s for corp %d using %s: %s', $label, $corpId, $character->getName(), $e->getMessage()));
                    return;
                }
                $this->logger->warning(sprintf('[Cron] %s lacks the in-game role for %s of corp %d (HTTP 403). Trying next character.', $character->getName(), $label, $corpId));
            } catch (\Exception $e) {
                $this->logger->error(sprintf('[Cron] Failed to sync %s for corp %d using %s: %s', $label, $corpId, $character->getName(), $e->getMessage()));
                return;
            }
            $this->ensureEntityManagerOpen();
        }

        $this->logger->warning(sprintf('[Cron] None of the %d authorized characters could read %s of corp %d.', count($characters), $label, $corpId));
    }

    private function _getCorporationName(int $corpId): ?string
    {
        if (!array_key_exists($corpId, $this->corporationNames)) {
            try {
                $corporationData = $this->esiClient->request('GET', sprintf('corporations/%d/', $corpId));
                $this->corporationNames[$corpId] = $corporationData['name'] ?? null;
            } catch (\Exception $e) {
                $this->corporationNames[$corpId] = null;
            }
        }

        return $this->corporationNames[$corpId];
    }

    private function ensureEntityManagerOpen(): void
    {
        if (!$this->entityManager->isOpen()) {
            $this->entityManager = $this->_resetEntityManager();
        }
    }

    private function syncUpwellStructures(int $corpId, EveCharacter $director): void
    {
        $structuresData = $this->esiClient->requestAllPages(
            sprintf('corporations/%d/structures/', $corpId),
            [],
            $director
        )['data'];

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
                
                $corporationName = $this->_getCorporationName($corpId);
                if ($corporationName !== null) {
                    $globalStructure->setOwnerName($corporationName);
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
        $starbasesData = $this->esiClient->requestAllPages(
            sprintf('corporations/%d/starbases/', $corpId),
            [],
            $director
        )['data'];

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

    private function _resetEntityManager(): EntityManagerInterface
    {
        $entityManager = $this->doctrine->resetManager();
        if (!$entityManager instanceof EntityManagerInterface) {
            throw new \LogicException('Default Doctrine manager is not an ORM EntityManager.');
        }

        return $entityManager;
    }
}
