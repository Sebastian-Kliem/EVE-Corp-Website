<?php

namespace App\Service\Cron;

use App\Entity\EveCharacter;
use App\Entity\EveCharacterAsset;
use App\Entity\EveCharacterPi;
use App\Entity\EveCorporationAsset;
use App\Entity\EveStructure;
use App\Service\Esi\EsiClient;
use App\Service\LocationService;
use App\Service\SdeService;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ManagerRegistry;
use Psr\Log\LoggerInterface;

class UpdateCharacterPiTask implements CronTaskInterface
{
    private Connection $sdeConnection;

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly EsiClient $esiClient,
        private readonly SdeService $sdeService,
        private readonly LocationService $locationService,
        private readonly LoggerInterface $logger,
        ManagerRegistry $doctrine
    ) {
        $this->sdeConnection = $doctrine->getConnection('sde');
    }

    public function getCommandName(): string
    {
        return 'character:sync-pi';
    }

    public function execute(): void
    {
        $characterRepository = $this->entityManager->getRepository(EveCharacter::class);
        /** @var EveCharacter[] $characters */
        $characters = $characterRepository->findAll();

        $this->logger->info(sprintf('[Cron] Starting sync-pi for %d characters.', count($characters)));

        foreach ($characters as $character) {
            if (empty($character->getRefreshToken())) {
                continue;
            }

            try {
                $this->syncPiForCharacter($character);
            } catch (\Symfony\Contracts\HttpClient\Exception\HttpExceptionInterface $e) {
                if ($e->getResponse()->getStatusCode() === 403) {
                    $this->logger->warning(sprintf(
                        '[Cron] Character %s lacks scope or permission for Planetary Industry.',
                        $character->getName()
                    ));
                    continue;
                }
                $this->logger->error(sprintf(
                    '[Cron] Failed to sync PI for character %s (%d): %s',
                    $character->getName(),
                    $character->getId(),
                    $e->getMessage()
                ));
            } catch (\Exception $e) {
                $this->logger->error(sprintf(
                    '[Cron] Failed to sync PI for character %s (%d): %s',
                    $character->getName(),
                    $character->getId(),
                    $e->getMessage()
                ));
            }
        }

        $this->logger->info('[Cron] Finished sync-pi execution.');
    }

    private function syncPiForCharacter(EveCharacter $character): void
    {
        $this->logger->info(sprintf('[Cron] Syncing PI data for character %s...', $character->getName()));

        // 1. Pre-load and group character assets that are PI materials in all locations
        $charAssets = $this->entityManager->getRepository(EveCharacterAsset::class)->findBy(['character' => $character]);
        
        // Map to quickly find parent assets (containers)
        $assetsByItemId = [];
        foreach ($charAssets as $asset) {
            $assetsByItemId[$asset->getItemId()] = $asset;
        }

        $pocoAssetsMap = []; // locationId => [ [type_id, name, quantity, container], ... ]
        foreach ($charAssets as $asset) {
            $category = $this->sdeService->getItemCategory($asset->getTypeId());
            if ($category === 'pi') {
                // Resolve nested container path to find the real location (Station, Citadel, POCO)
                $realLocId = $asset->getLocationId();
                $containerPath = [];
                $visited = []; // Prevent infinite loops
                while (isset($assetsByItemId[$realLocId]) && !in_array($realLocId, $visited, true)) {
                    $visited[] = $realLocId;
                    $containerAsset = $assetsByItemId[$realLocId];
                    
                    $cName = $containerAsset->getCustomName();
                    if (!$cName) {
                        $cName = $this->sdeService->getItemName($containerAsset->getTypeId());
                    }
                    $containerPath[] = $cName;
                    
                    $realLocId = $containerAsset->getLocationId();
                }

                $containerName = null;
                if (!empty($containerPath)) {
                    $containerName = implode(' > ', array_reverse($containerPath));
                }

                $pocoAssetsMap[$realLocId][] = [
                    'type_id' => $asset->getTypeId(),
                    'name' => $this->sdeService->getItemName($asset->getTypeId()),
                    'quantity' => $asset->getQuantity(),
                    'container' => $containerName,
                ];
            }
        }

        // 2. Fetch basic planet list for character
        $planets = $this->esiClient->request(
            'GET',
            sprintf('characters/%d/planets/', $character->getId()),
            [],
            $character
        );

        $planetData = [];

        foreach ($planets as $p) {
            $planetId = (int)$p['planet_id'];

            // A. Resolve planet celestial info from SDE mapDenormalize
            $planetSde = $this->sdeConnection->fetchAssociative(
                'SELECT itemName, solarSystemID FROM mapDenormalize WHERE itemID = :id LIMIT 1',
                ['id' => $planetId]
            );

            $planetName = $planetSde ? $planetSde['itemName'] : 'Planet #' . $planetId;
            $solarSystemId = $planetSde ? (int)$planetSde['solarSystemID'] : 0;
            $solarSystemName = $solarSystemId > 0 ? $this->sdeService->getLocationName($solarSystemId) : 'Unbekannt';

            // B. Fetch detailed planet layout
            $details = $this->esiClient->request(
                'GET',
                sprintf('characters/%d/planets/%d/', $character->getId(), $planetId),
                [],
                $character
            );

            // C. Process pins (structures)
            $pins = $details['pins'] ?? [];
            $routes = $details['routes'] ?? [];

            $processedPins = [];

            foreach ($pins as $pin) {
                $pinId = (string)$pin['pin_id'];
                $typeId = (int)$pin['type_id'];
                $typeName = $this->sdeService->getItemName($typeId);

                // Identify pin categories
                $category = 'other';
                if (stripos($typeName, 'Command Center') !== false) {
                    $category = 'command_center';
                } elseif (stripos($typeName, 'Launchpad') !== false) {
                    $category = 'launchpad';
                } elseif (stripos($typeName, 'Storage') !== false || stripos($typeName, 'Silo') !== false) {
                    $category = 'storage';
                } elseif (stripos($typeName, 'Extractor') !== false) {
                    $category = 'extractor';
                } elseif (stripos($typeName, 'Industry') !== false || stripos($typeName, 'Factory') !== false || stripos($typeName, 'Facility') !== false || stripos($typeName, 'Plant') !== false) {
                    $category = 'factory';
                }

                // Parse contents
                $contents = [];
                if (!empty($pin['contents'])) {
                    foreach ($pin['contents'] as $content) {
                        $cTypeId = (int)$content['type_id'];
                        $volume = (float)$this->sdeConnection->fetchOne(
                            'SELECT volume FROM invTypes WHERE typeID = :id LIMIT 1',
                            ['id' => $cTypeId]
                        );
                        $contents[] = [
                            'type_id' => $cTypeId,
                            'name' => $this->sdeService->getItemName($cTypeId),
                            'quantity' => (int)$content['amount'],
                            'volume' => $volume,
                        ];
                    }
                }

                // Extractor details
                $extractorInfo = null;
                if ($category === 'extractor' && isset($pin['extractor_details'])) {
                    $ext = $pin['extractor_details'];
                    $prodTypeId = (int)($ext['product_type_id'] ?? 0);
                    $extractorInfo = [
                        'product_type_id' => $prodTypeId,
                        'product_name' => $prodTypeId > 0 ? $this->sdeService->getItemName($prodTypeId) : 'Nichts',
                        'cycle_time' => (int)($ext['cycle_time'] ?? 0),
                        'qty_per_cycle' => (int)($ext['qty_per_cycle'] ?? 0),
                        'heads_count' => count($ext['heads'] ?? []),
                    ];
                }

                // Factory details (schematic)
                $factoryInfo = null;
                if ($category === 'factory' && isset($pin['schematic_id'])) {
                    $schematicId = (int)$pin['schematic_id'];
                    $schematic = $this->sdeService->getSchematicDetails($schematicId);
                    if ($schematic) {
                        $factoryInfo = [
                            'schematic_id' => $schematicId,
                            'name' => $schematic['name'],
                            'cycle_time' => $schematic['cycleTime'],
                            'inputs' => $schematic['inputs'],
                            'outputs' => $schematic['outputs'],
                        ];
                    }
                }

                $processedPins[$pinId] = [
                    'pin_id' => $pinId,
                    'type_id' => $typeId,
                    'name' => $typeName,
                    'category' => $category,
                    'contents' => $contents,
                    'extractor_info' => $extractorInfo,
                    'factory_info' => $factoryInfo,
                    'last_cycle_start' => isset($pin['last_cycle_start']) ? $pin['last_cycle_start'] : null,
                    'expiry_time' => isset($pin['expiry_time']) ? $pin['expiry_time'] : null,
                ];
            }

            // D. Trace routes to link factories and launchpads
            $launchpadInputs = [];
            $launchpadOutputs = [];

            foreach ($routes as $route) {
                $sourceId = (string)$route['source_pin_id'];
                $destId = (string)$route['destination_pin_id'];
                $qty = (int)$route['quantity'];
                $cTypeId = (int)$route['content_type_id'];
                $materialName = $this->sdeService->getItemName($cTypeId);

                $sourcePin = $processedPins[$sourceId] ?? null;
                $destPin = $processedPins[$destId] ?? null;

                if ($sourcePin && $destPin) {
                    if (in_array($sourcePin['category'], ['launchpad', 'storage']) && $destPin['category'] === 'factory') {
                        $launchpadInputs[$sourceId][] = [
                            'factory_id' => $destId,
                            'factory_name' => $destPin['name'],
                            'schematic_name' => $destPin['factory_info']['name'] ?? 'Unbekannt',
                            'material_id' => $cTypeId,
                            'material_name' => $materialName,
                            'quantity' => $qty,
                        ];
                    }
                    if ($sourcePin['category'] === 'factory' && in_array($destPin['category'], ['launchpad', 'storage'])) {
                        $launchpadOutputs[$destId][] = [
                            'factory_id' => $sourceId,
                            'factory_name' => $sourcePin['name'],
                            'schematic_name' => $sourcePin['factory_info']['name'] ?? 'Unbekannt',
                            'material_id' => $cTypeId,
                            'material_name' => $materialName,
                            'quantity' => $qty,
                        ];
                    }
                }
            }

            foreach ($processedPins as $pinId => &$pinRef) {
                if (in_array($pinRef['category'], ['launchpad', 'storage'])) {
                    $pinRef['supplied_inputs'] = $launchpadInputs[$pinId] ?? [];
                    $pinRef['received_outputs'] = $launchpadOutputs[$pinId] ?? [];
                }
            }
            unset($pinRef);

            // E. Try to find Customs Office (POCO) materials for this planet in database
            $pocoMaterials = [];
            $pocoName = 'Zollamt (POCO)';
            $pocoResolved = false;
            $pocoId = null;
            
            $pocoAsset = $this->entityManager->getRepository(EveCorporationAsset::class)->createQueryBuilder('ca')
                ->where('ca.customName LIKE :planetName')
                ->setParameter('planetName', '%' . $planetName . '%')
                ->setMaxResults(1)
                ->getQuery()
                ->getOneOrNullResult();

            if ($pocoAsset) {
                $pocoName = $pocoAsset->getCustomName();
                $pocoId = $pocoAsset->getItemId();
                $pocoResolved = true;
            } else {
                $pocoStructure = $this->entityManager->getRepository(EveStructure::class)->createQueryBuilder('s')
                    ->where('s.name LIKE :planetName')
                    ->setParameter('planetName', '%' . $planetName . '%')
                    ->setMaxResults(1)
                    ->getQuery()
                    ->getOneOrNullResult();

                if ($pocoStructure) {
                    $pocoName = $pocoStructure->getName();
                    $pocoId = (int)$pocoStructure->getId();
                    $pocoResolved = true;
                }
            }

            if ($pocoId && isset($pocoAssetsMap[$pocoId])) {
                $pocoMaterials = $pocoAssetsMap[$pocoId];
                unset($pocoAssetsMap[$pocoId]); // Removed so it won't show up in unassigned
            }

            $planetData[] = [
                'planet_id' => $planetId,
                'name' => $planetName,
                'type' => $p['planet_type'],
                'solar_system_name' => $solarSystemName,
                'solar_system_id' => $solarSystemId,
                'upgrade_level' => (int)$p['upgrade_level'],
                'num_pins' => (int)$p['num_pins'],
                'last_update' => $p['last_update'],
                'pins' => array_values($processedPins),
                'routes' => $routes,
                'poco' => [
                    'name' => $pocoName,
                    'contents' => $pocoMaterials,
                    'resolved' => $pocoResolved,
                ]
            ];
        }

        // 3. Gather remaining items in pocoAssetsMap as unassigned POCOs
        $unassignedPocos = [];
        foreach ($pocoAssetsMap as $locId => $materials) {
            $resolved = $this->locationService->resolveLocation($locId, $character);
            
            $unassignedPocos[] = [
                'location_id' => $locId,
                'name' => $resolved['name'],
                'solar_system_name' => $resolved['systemName'],
                'contents' => $materials,
            ];
        }

        // 4. Save to Database
        $piRepo = $this->entityManager->getRepository(EveCharacterPi::class);
        $piEntry = $piRepo->findOneBy(['character' => $character]);
        if (!$piEntry) {
            $piEntry = new EveCharacterPi();
            $piEntry->setCharacter($character);
        }
        $piEntry->setPiData([
            'planets' => $planetData,
            'unassigned_pocos' => $unassignedPocos,
        ]);
        $piEntry->setLastUpdated(new \DateTimeImmutable());

        $this->entityManager->persist($piEntry);
        $this->entityManager->flush();

        $this->logger->info(sprintf(
            '[Cron] Successfully synced PI data for character %s (%d planets, %d unassigned POCOs).',
            $character->getName(),
            count($planetData),
            count($unassignedPocos)
        ));
    }
}
