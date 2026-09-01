<?php

namespace App\Controller\Corp;

use App\Entity\EveCharacter;
use App\Entity\EveCorporationAsset;
use App\Entity\EveCorporationStarbase;
use App\Entity\EveCorporationStructure;
use App\Entity\User;
use App\Service\Esi\EsiClient;
use App\Service\SdeService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/corp/structures')]
#[IsGranted('ROLE_MEMBER')]
class CorpStructureController extends AbstractController
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly SdeService $sdeService,
        private readonly EsiClient $esiClient
    ) {}

    #[Route('', name: 'app_corp_structures', methods: ['GET'])]
    public function index(): Response
    {
        $currentUser = $this->getUser();
        if (!$currentUser instanceof User) {
            return $this->redirectToRoute('app_login');
        }

        // 1. Fetch characters of the current user
        $characters = $this->entityManager->getRepository(EveCharacter::class)->findBy([
            'user' => $currentUser
        ]);

        $corpIds = [];
        foreach ($characters as $char) {
            $corpId = (int)$char->getCorporationId();
            // Skip NPC corporations (typically IDs < 2000000)
            if ($corpId >= 2000000) {
                $corpIds[] = $corpId;
            }
        }
        $corpIds = array_values(array_unique($corpIds));

        $corpsData = [];

        if (!empty($corpIds)) {
            $structureRepo = $this->entityManager->getRepository(EveCorporationStructure::class);
            $starbaseRepo = $this->entityManager->getRepository(EveCorporationStarbase::class);
            $assetRepo = $this->entityManager->getRepository(EveCorporationAsset::class);

            foreach ($corpIds as $corpId) {
                // Resolve Corporation name and ticker
                $corpName = 'Corporation ' . $corpId;
                $corpTicker = '';
                try {
                    $corpInfo = $this->esiClient->request('GET', sprintf('corporations/%d/', $corpId));
                    if (isset($corpInfo['name'])) {
                        $corpName = $corpInfo['name'];
                    }
                    if (isset($corpInfo['ticker'])) {
                        $corpTicker = $corpInfo['ticker'];
                    }
                } catch (\Exception $e) {
                    // Fallback to default name if ESI request fails
                }

                // Fetch Upwell structures
                $structures = $structureRepo->findBy(
                    ['corporationId' => (string)$corpId],
                    ['solarSystemName' => 'ASC', 'name' => 'ASC']
                );

                // Fetch Starbases (POS)
                $starbases = $starbaseRepo->findBy(
                    ['corporationId' => (string)$corpId],
                    ['solarSystemName' => 'ASC']
                );

                // Collect structure IDs to resolve fitted modules/rigs from EveCorporationAsset
                $structureIds = [];
                foreach ($structures as $structure) {
                    $structureIds[] = (int)$structure->getId();
                }

                $fittingsByStructureId = [];
                if (!empty($structureIds)) {
                    $fittedAssets = $assetRepo->createQueryBuilder('a')
                        ->where('a.corporationId = :corpId')
                        ->andWhere('a.locationId IN (:structureIds)')
                        ->setParameter('corpId', $corpId)
                        ->setParameter('structureIds', $structureIds)
                        ->getQuery()
                        ->getResult();

                    // Collect module item IDs to fetch loaded charges (ammo, crystals, fuel, scripts)
                    $moduleItemIds = [];
                    /** @var EveCorporationAsset $asset */
                    foreach ($fittedAssets as $asset) {
                        if ($asset->getLocationFlag() !== 'QuantumCoreRoom') {
                            $moduleItemIds[] = $asset->getItemId();
                        }
                    }

                    $chargesByModuleId = [];
                    if (!empty($moduleItemIds)) {
                        $childAssets = $assetRepo->createQueryBuilder('ca')
                            ->where('ca.corporationId = :corpId')
                            ->andWhere('ca.locationId IN (:moduleItemIds)')
                            ->setParameter('corpId', $corpId)
                            ->setParameter('moduleItemIds', $moduleItemIds)
                            ->getQuery()
                            ->getResult();

                        /** @var EveCorporationAsset $ca */
                        foreach ($childAssets as $ca) {
                            $parentItemId = $ca->getLocationId();
                            $chargeTypeId = $ca->getTypeId();
                            $chargesByModuleId[$parentItemId][] = [
                                'itemId' => $ca->getItemId(),
                                'typeId' => $chargeTypeId,
                                'typeName' => $this->sdeService->getItemName($chargeTypeId),
                                'quantity' => (int)$ca->getQuantity(),
                                'locationFlag' => $ca->getLocationFlag(),
                            ];
                        }
                    }

                    /** @var EveCorporationAsset $asset */
                    foreach ($fittedAssets as $asset) {
                        $locId = (string)$asset->getLocationId();
                        $flag = $asset->getLocationFlag();

                        // Skip Quantum Core as requested by user
                        if ($flag === 'QuantumCoreRoom') {
                            continue;
                        }

                        $typeId = $asset->getTypeId();
                        $typeName = $this->sdeService->getItemName($typeId);
                        $quantity = (int)$asset->getQuantity();

                        $slotIndex = null;
                        if (preg_match('/(\d+)$/', $flag, $matches)) {
                            $slotIndex = (int)$matches[1];
                        }

                        $slotCategory = 'other';
                        if (str_starts_with($flag, 'StructureServiceSlot')) {
                            $slotCategory = 'services';
                        } elseif (str_starts_with($flag, 'RigSlot')) {
                            $slotCategory = 'rigs';
                        } elseif (str_starts_with($flag, 'HiSlot')) {
                            $slotCategory = 'high';
                        } elseif (str_starts_with($flag, 'MedSlot')) {
                            $slotCategory = 'medium';
                        } elseif (str_starts_with($flag, 'LowSlot')) {
                            $slotCategory = 'low';
                        } elseif ($flag === 'StructureFuel') {
                            $slotCategory = 'fuel';
                        } elseif (str_starts_with($flag, 'FighterTube') || $flag === 'FighterBay') {
                            $slotCategory = 'fighters';
                        } elseif (
                            in_array($flag, ['Cargo', 'CargoHold', 'AutoFit', 'SecondaryStorage'], true) ||
                            (!str_starts_with($flag, 'CorpSAG') && !in_array($flag, ['CorpDeliveries', 'OfficeFolder', 'Hangar', 'HangarAll'], true) && $typeId !== 27)
                        ) {
                            $slotCategory = 'cargo';
                        }

                        $fittingsByStructureId[$locId][$slotCategory][] = [
                            'itemId' => $asset->getItemId(),
                            'typeId' => $typeId,
                            'typeName' => $typeName,
                            'locationFlag' => $flag,
                            'slotIndex' => $slotIndex,
                            'quantity' => $quantity,
                            'charges' => $chargesByModuleId[$asset->getItemId()] ?? [],
                        ];
                    }
                }

                // Format Upwell structures
                $defaultFittings = [
                    'services' => [],
                    'rigs' => [],
                    'high' => [],
                    'medium' => [],
                    'low' => [],
                    'fuel' => [],
                    'fighters' => [],
                    'cargo' => [],
                    'other' => [],
                ];

                $structuresData = [];
                foreach ($structures as $s) {
                    $sId = (string)$s->getId();
                    $structureFittings = array_merge($defaultFittings, $fittingsByStructureId[$sId] ?? []);

                    $structuresData[] = [
                        'id' => $sId,
                        'name' => $s->getName() ?? $s->getTypeName() ?? ('Struktur #' . $sId),
                        'typeId' => $s->getTypeId(),
                        'typeName' => $s->getTypeName(),
                        'solarSystemId' => $s->getSolarSystemId(),
                        'solarSystemName' => $s->getSolarSystemName(),
                        'state' => $s->getState(),
                        'fuelExpires' => $s->getFuelExpires()?->format('c'),
                        'services' => $s->getServices() ?? [],
                        'reinforceHour' => $s->getReinforceHour(),
                        'lastUpdated' => $s->getLastUpdated()?->format('c'),
                        'fittings' => $structureFittings,
                    ];
                }

                // Format Starbases (POS)
                $starbasesData = [];
                foreach ($starbases as $sb) {
                    $sbId = (string)$sb->getId();
                    $starbasesData[] = [
                        'id' => $sbId,
                        'typeId' => $sb->getTypeId(),
                        'typeName' => $sb->getTypeName(),
                        'solarSystemId' => $sb->getSolarSystemId(),
                        'solarSystemName' => $sb->getSolarSystemName(),
                        'state' => $sb->getState(),
                        'onlinedSince' => $sb->getOnlinedSince()?->format('c'),
                        'reinforcedUntil' => $sb->getReinforcedUntil()?->format('c'),
                        'fuels' => $sb->getFuels() ?? [],
                        'modules' => $sb->getModules() ?? [],
                        'lastUpdated' => $sb->getLastUpdated()?->format('c'),
                    ];
                }

                $corpsData[] = [
                    'corporation' => [
                        'id' => $corpId,
                        'name' => $corpName,
                        'ticker' => $corpTicker,
                    ],
                    'structures' => $structuresData,
                    'starbases' => $starbasesData,
                ];
            }

            // Sort corps alphabetically by corporation name
            usort($corpsData, fn($a, $b) => strcasecmp($a['corporation']['name'], $b['corporation']['name']));
        }

        return $this->render('corp/structures.html.twig', [
            'corpsData' => $corpsData,
        ]);
    }
}
