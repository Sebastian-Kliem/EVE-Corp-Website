<?php

namespace App\Controller\Wallet;

use App\Entity\EveAccount;
use App\Entity\EveCharacter;
use App\Entity\EveCharacterAsset;
use App\Entity\EveCharacterMarketOrder;
use App\Entity\EveCharacterValueSnapshot;
use App\Entity\EveCharacterWalletJournalEntry;
use App\Entity\EveCorporationAsset;
use App\Entity\User;
use App\Service\Assets\AssetTreeBuilder;
use App\Service\JitaPriceService;
use App\Service\LocationService;
use App\Service\PersonalCorpAssetService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Combined inventory and wallet view over all characters of the user.
 */
#[IsGranted('ROLE_MEMBER')]
class AssetsOverviewController extends AbstractController
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly JitaPriceService $jitaPriceService,
        private readonly AssetTreeBuilder $assetTreeBuilder
    ) {}

    #[Route('/wallet/assets', name: 'app_dashboard_assets_overview', methods: ['GET'])]
    public function assetsOverview(
        LocationService $locationService,
        \App\Service\Esi\EsiClient $esiClient,
        PersonalCorpAssetService $personalCorpAssetService
    ): Response {
        $currentUser = $this->getUser();
        if (!$currentUser instanceof User) {
            return $this->redirectToRoute('app_login');
        }

        $prices = $this->jitaPriceService->getGlobalPrices();

        $characters = $this->entityManager->getRepository(EveCharacter::class)->findBy([
            'user' => $currentUser
        ]);

        // Group characters by corporation to determine a primary character per corporation
        $charactersByCorp = [];
        foreach ($characters as $char) {
            if ($char->getCorporationId()) {
                $charactersByCorp[$char->getCorporationId()][] = $char;
            }
        }
        foreach ($charactersByCorp as $corpId => &$corpChars) {
            usort($corpChars, fn($a, $b) => $a->getId() <=> $b->getId());
        }
        unset($corpChars);

        $totalWallet = 0.0;
        $characterData = [];
        $currentValues = [];

        foreach ($characters as $character) {
            $walletBalance = (float) ($character->getWalletBalance() ?? 0.0);
            $totalWallet += $walletBalance;

            // Fetch and structure assets
            $assets = $this->entityManager->getRepository(EveCharacterAsset::class)->findBy([
                'character' => $character
            ]);

            $assets = array_filter($assets, function (EveCharacterAsset $a) {
                return $a->getLocationType() !== 'industry_job' && $a->getLocationType() !== 'personal_corp_asset';
            });

            // Fetch active market orders (sell orders as pseudo assets, buy orders as escrow)
            $marketOrders = $this->entityManager->getRepository(EveCharacterMarketOrder::class)->findBy([
                'character' => $character
            ]);
            $escrowTotal = 0.0;
            foreach ($marketOrders as $order) {
                if ($order->isBuy()) {
                    $escrowTotal += (float)($order->getEscrow() ?? 0.0);
                } else {
                    $pseudoAsset = new EveCharacterAsset();
                    $pseudoAsset->setCharacter($character);
                    $pseudoAsset->setItemId((int)$order->getOrderId());
                    $pseudoAsset->setTypeId($order->getTypeId());
                    $pseudoAsset->setQuantity($order->getVolumeRemain());
                    $pseudoAsset->setLocationId((int)$order->getLocationId());
                    $pseudoAsset->setLocationType('station');
                    $pseudoAsset->setLocationFlag('MarketOrder');
                    $pseudoAsset->setIsSingleton(false);
                    $pseudoAsset->setCustomName('[Markt-Verkaufsorder]');
                    $assets[] = $pseudoAsset;
                }
            }

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
            $totalAssetVal = 0.0;
            foreach ($topLevelAssetsByLocation as $locationId => $topAssets) {
                $resolved = $locationService->resolveLocation($locationId, $character);
                $locationName = $resolved['name'];
                $systemName = $resolved['systemName'];

                $items = [];
                foreach ($topAssets as $asset) {
                    $items[] = $this->assetTreeBuilder->buildCharacterAssetNode($asset, $nestedAssets, $prices);
                }

                $items = $this->assetTreeBuilder->groupAndSortNodes($items, $locationId);

                $locations[] = [
                    'id' => $locationId,
                    'name' => $locationName,
                    'systemName' => $systemName,
                    'items' => $items,
                ];
            }

            foreach ($assets as $asset) {
                if ($asset->isBlueprintCopy()) {
                    continue;
                }
                if ($asset->getLocationFlag() === 'MarketOrder') {
                    $typeId = $asset->getTypeId();
                    $jitaBuyPrice = null;
                    try {
                        $priceInfo = $this->jitaPriceService->getAverageJitaPrice($typeId, true);
                        $jitaBuyPrice = $priceInfo['price'];
                    } catch (\Exception $e) {
                        // Ignore
                    }
                    $price = $jitaBuyPrice ?? ($prices[$typeId] ?? 0.0);
                } else {
                    $price = $prices[$asset->getTypeId()] ?? 0.0;
                }
                $totalAssetVal += ($price * $asset->getQuantity());
            }

            // Add escrow of buy orders to assets value
            $totalAssetVal += $escrowTotal;

            // Merge Personal Corporation Assets if this is the primary character for the corporation
            $corpId = $character->getCorporationId();
            $isPrimaryForCorp = false;
            if ($corpId && isset($charactersByCorp[$corpId]) && $charactersByCorp[$corpId][0]->getId() === $character->getId()) {
                $isPrimaryForCorp = true;
            }

            if ($isPrimaryForCorp) {
                $personalHangars = $currentUser->getPersonalCorpHangars();
                $personalContainers = $currentUser->getPersonalCorpContainers();

                if (!empty($personalHangars) || !empty($personalContainers)) {
                    $corpAssets = $this->entityManager->getRepository(EveCorporationAsset::class)->findBy([
                        'corporationId' => $corpId
                    ]);

                    $resolvedCorp = $personalCorpAssetService->resolvePersonalCorpAssets(
                        $corpId,
                        $corpAssets,
                        $personalHangars,
                        $personalContainers
                    );

                    $personalRoots = $resolvedCorp['roots'];
                    $corpAssetsByItemId = $resolvedCorp['byItemId'];
                    $corpNestedAssets = $resolvedCorp['nested'];

                    // Try to find the sync character for this corp to resolve structure locations
                    $syncCharacter = $this->entityManager->getRepository(EveCharacter::class)->createQueryBuilder('c')
                        ->where('c.corporationId = :corpId')
                        ->andWhere('c.lastCorpAssetsUpdate IS NOT NULL')
                        ->setParameter('corpId', $corpId)
                        ->orderBy('c.lastCorpAssetsUpdate', \SortDirection::Descending)
                        ->setMaxResults(1)
                        ->getQuery()
                        ->getOneOrNullResult();

                    // Group personal roots by location
                    $personalRootsByLocation = [];
                    foreach ($personalRoots as $root) {
                        $personalRootsByLocation[$root->getLocationId()][] = $root;
                    }

                    foreach ($personalRootsByLocation as $locationId => $roots) {
                        $resolved = $locationService->resolveLocation($locationId, $syncCharacter ?? $character);
                        $locationName = $resolved['name'];
                        $systemName = $resolved['systemName'];

                        $locIndex = -1;
                        foreach ($locations as $idx => $loc) {
                            if ($loc['id'] === $locationId || $loc['name'] === $locationName) {
                                $locIndex = $idx;
                                break;
                            }
                        }

                        $items = [];
                        foreach ($roots as $root) {
                            $items[] = $this->assetTreeBuilder->buildPersonalCorpAssetNode($root, $corpNestedAssets, $prices);
                        }

                        $items = $this->assetTreeBuilder->groupAndSortNodes($items, $locationId);

                        if ($locIndex >= 0) {
                            $locations[$locIndex]['items'] = array_merge($locations[$locIndex]['items'], $items);
                            $locations[$locIndex]['items'] = $this->assetTreeBuilder->groupAndSortNodes($locations[$locIndex]['items'], $locationId);
                        } else {
                            $locations[] = [
                                'id' => $locationId,
                                'name' => $locationName,
                                'systemName' => $systemName,
                                'items' => $items,
                            ];
                        }

                        foreach ($items as $item) {
                            $totalAssetVal += $this->assetTreeBuilder->calculateNodeValue($item);
                        }
                    }
                }
            }

            $currentValues[] = [
                'characterId' => $character->getId(),
                'wallet' => $walletBalance,
                'assets' => $totalAssetVal,
                'total' => $walletBalance + $totalAssetVal,
            ];

            // Sort locations primarily by system name, then by location name
            usort($locations, function ($a, $b) {
                $sysCompare = strcasecmp($a['systemName'], $b['systemName']);
                if ($sysCompare !== 0) {
                    return $sysCompare;
                }
                return strcasecmp($a['name'], $b['name']);
            });

            $characterData[] = [
                'character' => $character,
                'walletBalance' => $walletBalance,
                'locations' => $locations,
            ];
        }

        // Sort characters by name
        usort($characterData, function ($a, $b) {
            return strcasecmp($a['character']->getName(), $b['character']->getName());
        });

        $characterIds = array_map(fn($c) => $c->getId(), $characters);

        // Fetch snapshots
        $snapshots = [];
        if (!empty($characterIds)) {
            $snapshots = $this->entityManager->getRepository(EveCharacterValueSnapshot::class)->createQueryBuilder('s')
                ->where('s.character IN (:characterIds)')
                ->setParameter('characterIds', $characterIds)
                ->orderBy('s.snapshotDate', \SortDirection::Ascending)
                ->getQuery()
                ->getResult();
        }

        $snapshotsData = [];
        foreach ($snapshots as $snapshot) {
            $snapshotsData[] = [
                'characterId' => $snapshot->getCharacter()->getId(),
                'date' => $snapshot->getSnapshotDate()->format('Y-m-d'),
                'wallet' => (float)$snapshot->getWalletBalance(),
                'assets' => (float)$snapshot->getAssetsValue(),
                'total' => $snapshot->getTotalValue(),
            ];
        }

        $omegaAccountCount = $this->entityManager->getRepository(EveAccount::class)->count([
            'user' => $currentUser,
            'isOmega' => true,
        ]);

        // Fetch wallet journal entries
        $journalEntries = [];
        if (!empty($characterIds)) {
            $journalEntries = $this->entityManager->getRepository(EveCharacterWalletJournalEntry::class)->createQueryBuilder('j')
                ->where('j.character IN (:characterIds)')
                ->setParameter('characterIds', $characterIds)
                ->orderBy('j.date', \SortDirection::Descending)
                ->setMaxResults(500)
                ->getQuery()
                ->getResult();
        }

        $journalEntriesData = [];
        foreach ($journalEntries as $entry) {
            $journalEntriesData[] = [
                'characterId' => $entry->getCharacter()->getId(),
                'characterName' => $entry->getCharacter()->getName(),
                'refId' => $entry->getRefId(),
                'date' => $entry->getDate()->format('d.m.Y H:i:s'),
                'refType' => $entry->getRefType(),
                'amount' => (float)$entry->getAmount(),
                'balance' => (float)$entry->getBalance(),
                'description' => $entry->getDescription(),
                'reason' => $entry->getReason(),
            ];
        }

        $charactersData = [];
        foreach ($characters as $character) {
            $charactersData[] = [
                'id' => $character->getId(),
                'name' => $character->getName(),
                'tags' => $character->getTags(),
            ];
        }

        $globalTotalAssetVal = 0.0;
        foreach ($currentValues as $cv) {
            $globalTotalAssetVal += $cv['assets'];
        }
        $netWorth = $totalWallet + $globalTotalAssetVal;

        // Build characters list for the Performance Ledger React component
        $charactersList = [];
        foreach ($currentUser->getEveAccounts() as $account) {
            foreach ($account->getCharacters() as $char) {
                $charactersList[] = [
                    'id' => $char->getId(),
                    'name' => $char->getName(),
                    'accountGroup' => $account->getGroupName() ?: 'Ungruppiert',
                    'accountName' => $account->getName(),
                    'tags' => $char->getTags(),
                ];
            }
        }
        foreach ($characters as $char) {
            if ($char->getAccount() === null) {
                $charactersList[] = [
                    'id' => $char->getId(),
                    'name' => $char->getName(),
                    'accountGroup' => 'Ungruppiert',
                    'accountName' => 'Ungruppiert',
                    'tags' => $char->getTags(),
                ];
            }
        }
        $uniqueCharacters = [];
        foreach ($charactersList as $char) {
            $uniqueCharacters[$char['id']] = $char;
        }
        $charactersList = array_values($uniqueCharacters);
        usort($charactersList, fn($a, $b) => strcasecmp($a['name'], $b['name']));

        return $this->render('wallet/assets_overview.html.twig', [
            'totalWallet' => $totalWallet,
            'totalAssetVal' => $globalTotalAssetVal,
            'netWorth' => $netWorth,
            'characterData' => $characterData,
            'snapshotsData' => $snapshotsData,
            'currentValues' => $currentValues,
            'omegaAccountCount' => $omegaAccountCount,
            'journalEntriesData' => $journalEntriesData,
            'charactersData' => $charactersData,
            'charactersList' => $charactersList,
        ]);
    }

    #[Route('/wallet/value-history', name: 'app_dashboard_value_history', methods: ['GET'])]
    public function valueHistory(): Response
    {
        return $this->redirectToRoute('app_dashboard_assets_overview');
    }
}
