<?php

namespace App\Controller\Corp;

use App\Entity\EveCharacter;
use App\Entity\EveCorporationAsset;
use App\Entity\User;
use App\Service\Assets\AssetTreeBuilder;
use App\Service\CorpDivisionService;
use App\Service\JitaPriceService;
use App\Service\LocationService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Corporation inventory, limited to the hangars a member is allowed to see.
 */
#[IsGranted('ROLE_MEMBER')]
class CorpAssetsController extends AbstractController
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly JitaPriceService $jitaPriceService,
        private readonly AssetTreeBuilder $assetTreeBuilder
    ) {}

    #[Route('/corp/assets', name: 'app_dashboard_corp_assets_overview', methods: ['GET'])]
    public function corpAssetsOverview(LocationService $locationService, \App\Service\Esi\EsiClient $esiClient, CorpDivisionService $corpDivisionService): Response
    {
        $currentUser = $this->getUser();
        if (!$currentUser instanceof User) {
            return $this->redirectToRoute('app_login');
        }

        $prices = $this->jitaPriceService->getGlobalPrices();

        $isCeoOrAdmin = $this->isGranted('ROLE_CEO') || $this->isGranted('ROLE_ADMIN');
        $visibilityMap = [];
        if (!$isCeoOrAdmin) {
            $visibilities = $this->entityManager->getRepository(\App\Entity\CorpAssetVisibility::class)->findBy(['isVisible' => true]);
            foreach ($visibilities as $visibility) {
                $allowedUsers = $visibility->getUsers();
                if ($allowedUsers->isEmpty()) {
                    $visibilityMap[$visibility->getLocationId()][$visibility->getLocationFlag()] = true;
                } else {
                    foreach ($allowedUsers as $allowedUser) {
                        if ($allowedUser->getId() === $currentUser->getId()) {
                            $visibilityMap[$visibility->getLocationId()][$visibility->getLocationFlag()] = true;
                            break;
                        }
                    }
                }
            }
        }

        $characters = $this->entityManager->getRepository(EveCharacter::class)->findBy([
            'user' => $currentUser
        ]);

        $corpIds = [];
        foreach ($characters as $character) {
            if ($character->getCorporationId()) {
                $corpIds[] = $character->getCorporationId();
            }
        }
        $corpIds = array_unique($corpIds);

        $corpData = [];

        foreach ($corpIds as $corpId) {
            // Fetch assets for this corporation
            $assets = $this->entityManager->getRepository(EveCorporationAsset::class)->findBy([
                'corporationId' => $corpId
            ]);

            // Try to find the sync character for this corp
            $syncCharacter = $this->entityManager->getRepository(EveCharacter::class)->createQueryBuilder('c')
                ->where('c.corporationId = :corpId')
                ->andWhere('c.lastCorpAssetsUpdate IS NOT NULL')
                ->setParameter('corpId', $corpId)
                ->orderBy('c.lastCorpAssetsUpdate', \SortDirection::Descending)
                ->setMaxResults(1)
                ->getQuery()
                ->getOneOrNullResult();

            $corpName = 'Corporation ' . $corpId;
            try {
                $corpInfo = $esiClient->request('GET', sprintf('corporations/%d/', $corpId));
                if (isset($corpInfo['name'])) {
                    $corpName = $corpInfo['name'];
                }
            } catch (\Exception $e) {
                // Ignore
            }

            // Fetch division names
            $divisionNames = $syncCharacter ? $corpDivisionService->getHangarDivisionNames((int)$corpId, $syncCharacter) : [];

            $getDivisionName = function (string $flag) use ($divisionNames) {
                if (preg_match('/^CorpSAG(\d)$/', $flag, $matches)) {
                    $divIndex = (int) $matches[1];
                    return $divisionNames[$divIndex] ?? 'Hangar ' . $divIndex;
                }
                if ($flag === 'CorpDeliveries') {
                    return 'Lieferungen (Deliveries)';
                }
                if ($flag === 'Hangar' || $flag === 'HangarAll') {
                    return 'Hangar';
                }
                return $flag;
            };

            // Rebuild tree
            $assetsByItemId = [];
            foreach ($assets as $asset) {
                $assetsByItemId[$asset->getItemId()] = $asset;
            }

            $nestedAssets = [];
            $topLevelAssetsByLocation = [];

            foreach ($assets as $asset) {
                $parentId = $asset->getLocationId();
                if (isset($assetsByItemId[$parentId])) {
                    $nestedAssets[$parentId][] = $asset;
                } else {
                    $topLevelAssetsByLocation[$parentId][] = $asset;
                }
            }

            $locations = [];
            foreach ($topLevelAssetsByLocation as $locationId => $topAssets) {
                $resolved = $locationService->resolveLocation($locationId, $syncCharacter);
                $locationName = $resolved['name'];
                $systemName = $resolved['systemName'];

                $groupedByDivision = [];
                foreach ($topAssets as $asset) {
                    $flag = $asset->getLocationFlag();

                    if (!$isCeoOrAdmin) {
                        if (in_array($flag, ['CorpSAG1', 'CorpSAG2', 'CorpSAG3', 'CorpSAG4', 'CorpSAG5', 'CorpSAG6', 'CorpSAG7', 'CorpDeliveries'], true)) {
                            if (!isset($visibilityMap[$locationId][$flag])) {
                                continue; // Not approved for visibility
                            }
                        }
                    }

                    $folderName = $getDivisionName($flag);
                    $node = $this->assetTreeBuilder->buildCorpAssetNode($asset, $nestedAssets, $divisionNames, $isCeoOrAdmin, $visibilityMap, $prices);

                    // If this is an Office (typeId 27) and has no visible children (divisions) left after filtering, skip it
                    if ($node['typeId'] === 27 && empty($node['children'])) {
                        continue;
                    }

                    $groupedByDivision[$folderName][] = $node;
                }

                foreach ($groupedByDivision as $folderName => &$items) {
                    $items = $this->assetTreeBuilder->groupAndSortNodes($items, $locationId);
                }
                unset($items);

                $divisions = [];
                foreach ($groupedByDivision as $folderName => $items) {
                    if (empty($items)) {
                        continue;
                    }
                    $divisions[] = [
                        'name' => $folderName,
                        'items' => $items,
                    ];
                }

                // If no divisions are visible/left for this location, hide the location entirely
                if (empty($divisions)) {
                    continue;
                }

                // Sort divisions
                usort($divisions, function ($a, $b) use ($divisionNames) {
                    $getDivOrder = function ($name) use ($divisionNames) {
                        if ($name === 'Lieferungen (Deliveries)') {
                            return 8;
                        }
                        foreach ($divisionNames as $idx => $divName) {
                            if ($name === $divName) {
                                return $idx;
                            }
                        }
                        if (preg_match('/^Hangar (\d)$/', $name, $matches)) {
                            return (int) $matches[1];
                        }
                        return 99;
                    };
                    $orderA = $getDivOrder($a['name']);
                    $orderB = $getDivOrder($b['name']);
                    if ($orderA !== $orderB) {
                        return $orderA <=> $orderB;
                    }
                    return strcasecmp($a['name'], $b['name']);
                });

                $locations[] = [
                    'id' => $locationId,
                    'name' => $locationName,
                    'systemName' => $systemName,
                    'divisions' => $divisions,
                ];
            }

            // Sort locations primarily by system name, then by location name
            usort($locations, function ($a, $b) {
                $sysCompare = strcasecmp($a['systemName'], $b['systemName']);
                if ($sysCompare !== 0) {
                    return $sysCompare;
                }
                return strcasecmp($a['name'], $b['name']);
            });

            $corpData[] = [
                'corporation' => [
                    'id' => $corpId,
                    'name' => $corpName,
                    'lastAssetsUpdate' => $syncCharacter && $syncCharacter->getLastCorpAssetsUpdate()
                        ? $syncCharacter->getLastCorpAssetsUpdate()->format('d.m.Y H:i')
                        : null,
                    'syncCharacterName' => $syncCharacter ? $syncCharacter->getName() : null
                ],
                'locations' => $locations,
            ];
        }

        // Sort corps by name
        usort($corpData, function ($a, $b) {
            return strcasecmp($a['corporation']['name'], $b['corporation']['name']);
        });

        return $this->render('profile/eve_account/corp_assets_overview.html.twig', [
            'corpData' => $corpData,
        ]);
    }
}
