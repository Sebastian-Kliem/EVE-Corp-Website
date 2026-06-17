<?php

namespace App\Controller\Profile;

use App\Entity\User;
use App\Entity\EveCharacter;
use App\Entity\EveCharacterAsset;
use App\Entity\EveCorporationAsset;
use App\Entity\EveStructure;
use App\Service\Esi\EsiClient;
use App\Service\SdeService;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\DBAL\Connection;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/dashboard/pi')]
#[IsGranted('ROLE_MEMBER')]
class ProfilePiController extends AbstractController
{
    private Connection $sdeConnection;

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly EsiClient $esiClient,
        private readonly SdeService $sdeService,
        private readonly \App\Service\LocationService $locationService,
        ManagerRegistry $doctrine
    ) {
        // Get SDE database connection for raw queries
        $this->sdeConnection = $doctrine->getConnection('sde');
    }

    #[Route('', name: 'app_dashboard_pi_overview', methods: ['GET'])]
    public function index(): Response
    {
        $currentUser = $this->getUser();
        if (!$currentUser instanceof User) {
            return $this->redirectToRoute('app_login');
        }

        // Group accounts/characters to display in navigation panels, matching other pages
        $groupedAccounts = [];
        $uncategorized = [];
        foreach ($currentUser->getEveAccounts() as $account) {
            $groupName = $account->getGroupName();
            if ($groupName) {
                $groupedAccounts[$groupName][] = $account;
            } else {
                $uncategorized[] = $account;
            }
        }
        ksort($groupedAccounts);
        if (!empty($uncategorized)) {
            $groupedAccounts['Ungruppiert'] = $uncategorized;
        }

        // Collect all character IDs to pass to frontend so it knows what to load
        $charactersList = [];
        foreach ($currentUser->getEveAccounts() as $account) {
            foreach ($account->getCharacters() as $char) {
                $charactersList[] = [
                    'id' => $char->getId(),
                    'name' => $char->getName(),
                    'accountGroup' => $account->getGroupName() ?: 'Ungruppiert',
                    'accountName' => $account->getName(),
                ];
            }
        }

        // Also add characters directly attached to user if any
        $allCharacters = $this->entityManager->getRepository(EveCharacter::class)->findBy(['user' => $currentUser]);
        foreach ($allCharacters as $char) {
            if ($char->getAccount() === null) {
                $charactersList[] = [
                    'id' => $char->getId(),
                    'name' => $char->getName(),
                    'accountGroup' => 'Ungruppiert',
                    'accountName' => 'Ungruppiert',
                ];
            }
        }

        // De-duplicate character list
        $uniqueCharacters = [];
        foreach ($charactersList as $char) {
            $uniqueCharacters[$char['id']] = $char;
        }
        $charactersList = array_values($uniqueCharacters);

        // Sort by account group, account name, character name
        usort($charactersList, function ($a, $b) {
            $grpCmp = strcasecmp($a['accountGroup'], $b['accountGroup']);
            if ($grpCmp !== 0) return $grpCmp;
            $accCmp = strcasecmp($a['accountName'], $b['accountName']);
            if ($accCmp !== 0) return $accCmp;
            return strcasecmp($a['name'], $b['name']);
        });

        return $this->render('profile/pi_overview.html.twig', [
            'charactersList' => $charactersList,
        ]);
    }

    #[Route('/data', name: 'app_dashboard_pi_data', methods: ['GET'])]
    public function getPiData(): JsonResponse
    {
        $currentUser = $this->getUser();
        if (!$currentUser instanceof User) {
            return new JsonResponse(['error' => 'Unauthorized'], Response::HTTP_UNAUTHORIZED);
        }

        // Fetch all characters associated with this user
        $characters = $this->entityManager->getRepository(EveCharacter::class)->findBy(['user' => $currentUser]);

        $data = [];

        foreach ($characters as $character) {
            if (empty($character->getRefreshToken())) {
                continue;
            }

            try {
                // Pre-load and group character assets that are PI materials in all locations
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

                // Fetch basic planet list for character
                $planets = $this->esiClient->request(
                    'GET',
                    sprintf('characters/%d/planets/', $character->getId()),
                    [],
                    $character
                );

                $planetData = [];

                foreach ($planets as $p) {
                    $planetId = (int)$p['planet_id'];

                    // 1. Resolve planet celestial info from SDE mapDenormalize
                    $planetSde = $this->sdeConnection->fetchAssociative(
                        'SELECT itemName, solarSystemID FROM mapDenormalize WHERE itemID = :id LIMIT 1',
                        ['id' => $planetId]
                    );

                    $planetName = $planetSde ? $planetSde['itemName'] : 'Planet #' . $planetId;
                    $solarSystemId = $planetSde ? (int)$planetSde['solarSystemID'] : 0;
                    $solarSystemName = $solarSystemId > 0 ? $this->sdeService->getLocationName($solarSystemId) : 'Unbekannt';

                    // 2. Fetch detailed planet layout
                    $details = $this->esiClient->request(
                        'GET',
                        sprintf('characters/%d/planets/%d/', $character->getId(), $planetId),
                        [],
                        $character
                    );

                    // 3. Process pins (structures)
                    $pins = $details['pins'] ?? [];
                    $routes = $details['routes'] ?? [];

                    $pinsMap = [];
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

                        $pinData = [
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

                        $pinsMap[$pinId] = $pinData;
                        $processedPins[$pinId] = $pinData;
                    }

                    // 4. Trace routes to link factories and launchpads
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

                    // 5. Try to find Customs Office (POCO) materials for this planet in database
                    $pocoMaterials = [];
                    $pocoName = 'Zollamt (POCO)';
                    $pocoResolved = false;
                    $pocoId = null;
                    
                    // Search in EveCorporationAsset for POCO orbiting this planet
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
                        // Fallback: Search in EveStructure (renamed by user)
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
                        'poco' => [
                            'name' => $pocoName,
                            'contents' => $pocoMaterials,
                            'resolved' => $pocoResolved,
                        ]
                    ];
                }

                // 6. Gather remaining items in pocoAssetsMap as unassigned POCOs
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

                $data[] = [
                    'character_id' => $character->getId(),
                    'character_name' => $character->getName(),
                    'planets' => $planetData,
                    'unassigned_pocos' => $unassignedPocos,
                ];

            } catch (\Exception $e) {
                $data[] = [
                    'character_id' => $character->getId(),
                    'character_name' => $character->getName(),
                    'error' => 'Kein Zugriff oder Fehler beim Abrufen der PI: ' . $e->getMessage(),
                    'planets' => [],
                    'unassigned_pocos' => [],
                ];
            }
        }

        return new JsonResponse($data);
    }
}
