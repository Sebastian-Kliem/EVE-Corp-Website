<?php

namespace App\Controller\Profile;

use App\Entity\EveAccount;
use App\Entity\EveCharacter;
use App\Entity\EveCharacterAsset;
use App\Entity\EveCharacterValueSnapshot;
use App\Entity\EveCharacterWalletJournalEntry;
use App\Entity\EveCorporationAsset;
use App\Entity\User;
use App\Service\LocationService;
use App\Service\SdeService;
use App\Service\JitaPriceService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted('ROLE_MEMBER')]
class EveAccountController extends AbstractController
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly JitaPriceService $jitaPriceService
    ) {}

    #[Route('/profile/eve-account/create', name: 'app_eve_account_create', methods: ['POST'])]
    #[IsGranted('IS_AUTHENTICATED_FULLY')]
    public function create(Request $request): Response
    {
        if (!$this->isCsrfTokenValid('eve_account_create', $request->request->get('_token'))) {
            $this->addFlash('error', 'Ungültiges CSRF-Token.');
            return $this->redirectToRoute('app_profile_characters');
        }

        $name = trim((string) $request->request->get('name'));
        if (empty($name)) {
            $this->addFlash('error', 'Account-Name darf nicht leer sein.');
            return $this->redirectToRoute('app_profile_characters');
        }

        $currentUser = $this->getUser();
        if (!$currentUser instanceof User) {
            return $this->redirectToRoute('app_login');
        }

        $account = new EveAccount();
        $account->setName($name);
        $account->setUser($currentUser);

        $this->entityManager->persist($account);
        $this->entityManager->flush();

        $this->addFlash('success', sprintf('EVE Account "%s" erfolgreich erstellt.', $name));

        return $this->redirectToRoute('app_profile_characters');
    }

    #[Route('/profile/eve-account/{id}/update', name: 'app_eve_account_update', methods: ['POST'])]
    #[IsGranted('IS_AUTHENTICATED_FULLY')]
    public function update(int $id, Request $request): Response
    {
        $account = $this->entityManager->getRepository(EveAccount::class)->find($id);

        if (!$account || $account->getUser() !== $this->getUser()) {
            throw $this->createAccessDeniedException('Zugriff verweigert.');
        }

        if (!$this->isCsrfTokenValid('eve_account_update_' . $id, $request->request->get('_token'))) {
            $this->addFlash('error', 'Ungültiges CSRF-Token.');
            return $this->redirectToRoute('app_profile_characters');
        }

        $name = trim((string) $request->request->get('name'));
        $isOmega = (bool) $request->request->get('isOmega');

        if (empty($name)) {
            $this->addFlash('error', 'Account-Name darf nicht leer sein.');
            return $this->redirectToRoute('app_profile_characters');
        }

        $account->setName($name);
        $account->setIsOmega($isOmega);

        $this->entityManager->flush();

        $this->addFlash('success', sprintf('Account "%s" erfolgreich aktualisiert.', $name));

        return $this->redirectToRoute('app_profile_characters');
    }

    #[Route('/profile/eve-account/{id}/delete', name: 'app_eve_account_delete', methods: ['POST'])]
    #[IsGranted('IS_AUTHENTICATED_FULLY')]
    public function delete(int $id, Request $request): Response
    {
        $account = $this->entityManager->getRepository(EveAccount::class)->find($id);

        if (!$account || $account->getUser() !== $this->getUser()) {
            throw $this->createAccessDeniedException('Zugriff verweigert.');
        }

        if (!$this->isCsrfTokenValid('eve_account_delete_' . $id, $request->request->get('_token'))) {
            $this->addFlash('error', 'Ungültiges CSRF-Token.');
            return $this->redirectToRoute('app_profile_characters');
        }

        // Reassign characters to null
        foreach ($account->getCharacters() as $character) {
            $character->setAccount(null);
        }

        $accountName = $account->getName();

        $this->entityManager->remove($account);
        $this->entityManager->flush();

        $this->addFlash('success', sprintf('Account "%s" wurde gelöscht. Zuvor verknüpfte Charaktere sind nun nicht zugewiesen.', $accountName));

        return $this->redirectToRoute('app_profile_characters');
    }

    #[Route('/profile/eve-character/{id}/assign', name: 'app_eve_character_assign', methods: ['POST'])]
    #[IsGranted('IS_AUTHENTICATED_FULLY')]
    public function assignCharacter(int $id, Request $request): Response
    {
        $character = $this->entityManager->getRepository(EveCharacter::class)->find($id);

        if (!$character || $character->getUser() !== $this->getUser()) {
            throw $this->createAccessDeniedException('Zugriff verweigert.');
        }

        if (!$this->isCsrfTokenValid('eve_character_assign_' . $id, $request->request->get('_token'))) {
            $this->addFlash('error', 'Ungültiges CSRF-Token.');
            return $this->redirectToRoute('app_profile_characters');
        }

        $accountId = $request->request->get('accountId');

        if (empty($accountId) || $accountId === 'unassigned') {
            $character->setAccount(null);
            $this->addFlash('success', sprintf('Charakter "%s" ist nun keinem Account mehr zugewiesen.', $character->getName()));
        } else {
            $account = $this->entityManager->getRepository(EveAccount::class)->find((int) $accountId);
            if (!$account || $account->getUser() !== $this->getUser()) {
                $this->addFlash('error', 'Ausgewählter Account ist ungültig.');
                return $this->redirectToRoute('app_profile_characters');
            }

            $character->setAccount($account);
            $this->addFlash('success', sprintf('Charakter "%s" dem Account "%s" zugewiesen.', $character->getName(), $account->getName()));
        }

        $this->entityManager->flush();

        return $this->redirectToRoute('app_profile_characters');
    }

    #[Route('/dashboard/eve-character/{id}/assets', name: 'app_dashboard_eve_character_assets', methods: ['GET'])]
    public function showAssets(int $id, SdeService $sdeService, LocationService $locationService): Response
    {
        $character = $this->entityManager->getRepository(EveCharacter::class)->find($id);

        if (!$character || $character->getUser() !== $this->getUser()) {
            throw $this->createAccessDeniedException('Zugriff verweigert.');
        }

        $assets = $this->entityManager->getRepository(EveCharacterAsset::class)->findBy(
            ['character' => $character],
            ['locationId' => 'ASC']
        );

        // Group assets by location
        $groupedAssets = [];
        foreach ($assets as $asset) {
            $locationId = $asset->getLocationId();
            if (!isset($groupedAssets[$locationId])) {
                $resolved = $locationService->resolveLocation($locationId, $character);
                $groupedAssets[$locationId] = [
                    'name' => $resolved['name'],
                    'items' => [],
                ];
            }
            
            $groupedAssets[$locationId]['items'][] = [
                'typeId' => $asset->getTypeId(),
                'name' => $sdeService->getItemName($asset->getTypeId()),
                'customName' => $asset->getCustomName(),
                'quantity' => $asset->getQuantity(),
                'locationFlag' => $asset->getLocationFlag(),
                'isBlueprintCopy' => $asset->isBlueprintCopy(),
                'isBlueprint' => $sdeService->isBlueprint($asset->getTypeId()),
                'isSingleton' => $asset->isSingleton(),
            ];
        }

        // Sort items inside each location (ships first, then containers, then others)
        foreach ($groupedAssets as &$group) {
            usort($group['items'], function ($a, $b) use ($sdeService) {
                $aIsShip = $sdeService->isShip($a['typeId']);
                $bIsShip = $sdeService->isShip($b['typeId']);
                if ($aIsShip && !$bIsShip) return -1;
                if (!$aIsShip && $bIsShip) return 1;

                $aIsContainer = $sdeService->isContainer($a['typeId']);
                $bIsContainer = $sdeService->isContainer($b['typeId']);
                if ($aIsContainer && !$bIsContainer) return -1;
                if (!$aIsContainer && $bIsContainer) return 1;

                return strcasecmp($a['name'], $b['name']);
            });
        }
        unset($group);

        // Sort groups by location name
        uasort($groupedAssets, function ($a, $b) {
            return strcasecmp($a['name'], $b['name']);
        });

        return $this->render('profile/eve_account/character_assets.html.twig', [
            'character' => $character,
            'groupedAssets' => $groupedAssets,
        ]);
    }

    #[Route('/dashboard/eve-character/{id}/details', name: 'app_dashboard_eve_character_details', methods: ['GET'])]
    public function showDetails(
        int $id,
        SdeService $sdeService,
        LocationService $locationService,
        \App\Service\Esi\EsiClient $esiClient
    ): Response {
        $character = $this->entityManager->getRepository(EveCharacter::class)->find($id);

        if (!$character || $character->getUser() !== $this->getUser()) {
            throw $this->createAccessDeniedException('Zugriff verweigert.');
        }

        // Fetch corporation and alliance names from ESI
        $corporationName = null;
        if ($character->getCorporationId()) {
            try {
                $corpData = $esiClient->request('GET', sprintf('corporations/%d/', $character->getCorporationId()));
                $corporationName = $corpData['name'] ?? null;
            } catch (\Exception $e) {
                // Keep null
            }
        }

        $allianceName = null;
        if ($character->getAllianceId()) {
            try {
                $allianceData = $esiClient->request('GET', sprintf('alliances/%d/', $character->getAllianceId()));
                $allianceName = $allianceData['name'] ?? null;
            } catch (\Exception $e) {
                // Keep null
            }
        }

        // Fetch active ship and current location from ESI if token is valid
        $activeShip = null;
        $currentLocation = null;
        if ($character->isTokenValid()) {
            try {
                $locData = $esiClient->request('GET', sprintf('characters/%d/location/', $character->getId()), [], $character);
                if (isset($locData['solar_system_id'])) {
                    $resolved = $locationService->resolveLocation($locData['solar_system_id'], $character);
                    $currentLocation = $resolved['name'];
                }
            } catch (\Exception $e) {
                // Ignore
            }

            try {
                $shipData = $esiClient->request('GET', sprintf('characters/%d/ship/', $character->getId()), [], $character);
                if (isset($shipData['ship_type_id'])) {
                    $typeName = $sdeService->getItemName($shipData['ship_type_id']);
                    $activeShip = $shipData['ship_name'] ? sprintf('%s (%s)', $shipData['ship_name'], $typeName) : $typeName;
                }
            } catch (\Exception $e) {
                // Ignore
            }
        }

        // Resolve implant names
        $implantNames = [];
        foreach ($character->getImplants() as $typeId) {
            $implantNames[$typeId] = $sdeService->getItemName($typeId);
        }

        // Resolve skill names in queue
        $skillNames = [];
        foreach ($character->getSkillQueue() as $item) {
            if (isset($item['skill_id'])) {
                $skillNames[$item['skill_id']] = $sdeService->getItemName($item['skill_id']);
            }
        }

        return $this->render('profile/eve_account/character_details.html.twig', [
            'character' => $character,
            'corporationName' => $corporationName,
            'allianceName' => $allianceName,
            'activeShip' => $activeShip,
            'currentLocation' => $currentLocation,
            'implantNames' => $implantNames,
            'skillNames' => $skillNames,
        ]);
    }

    #[Route('/dashboard/assets', name: 'app_dashboard_assets_overview', methods: ['GET'])]
    public function assetsOverview(
        LocationService $locationService,
        SdeService $sdeService,
        \App\Service\Esi\EsiClient $esiClient
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
                    $items[] = $this->buildAssetTreeNode($asset, $nestedAssets, $sdeService, $prices);
                }

                $items = $this->groupAndSortNodes($items, $sdeService, $locationId);

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
                $price = $prices[$asset->getTypeId()] ?? 0.0;
                $totalAssetVal += ($price * $asset->getQuantity());
            }

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

                    $corpAssetsByItemId = [];
                    foreach ($corpAssets as $asset) {
                        $corpAssetsByItemId[$asset->getItemId()] = $asset;
                    }

                    $corpNestedAssets = [];
                    foreach ($corpAssets as $asset) {
                        $parentId = $asset->getLocationId();
                        if (isset($corpAssetsByItemId[$parentId])) {
                            $corpNestedAssets[$parentId][] = $asset;
                        }
                    }

                    $personalRoots = [];
                    // Hangars
                    foreach ($personalHangars as $h) {
                        if ((int)$h['corporationId'] === $corpId) {
                            $locId = (int)$h['locationId'];
                            $flag = $h['locationFlag'];
                            foreach ($corpAssets as $asset) {
                                if ($asset->getLocationId() === $locId && $asset->getLocationFlag() === $flag) {
                                    $personalRoots[] = $asset;
                                }
                            }
                        }
                    }

                    // Containers
                    foreach ($personalContainers as $c) {
                        if ((int)$c['corporationId'] === $corpId) {
                            $itemId = (int)$c['itemId'];
                            if (isset($corpAssetsByItemId[$itemId])) {
                                $personalRoots[] = $corpAssetsByItemId[$itemId];
                            }
                        }
                    }

                    // Try to find the sync character for this corp to resolve structure locations
                    $syncCharacter = $this->entityManager->getRepository(EveCharacter::class)->createQueryBuilder('c')
                        ->where('c.corporationId = :corpId')
                        ->andWhere('c.lastCorpAssetsUpdate IS NOT NULL')
                        ->setParameter('corpId', $corpId)
                        ->orderBy('c.lastCorpAssetsUpdate', 'DESC')
                        ->setMaxResults(1)
                        ->getQuery()
                        ->getOneOrNullResult();

                    // Group personal roots by location
                    $personalRootsByLocation = [];
                    foreach ($personalRoots as $root) {
                        $personalRootsByLocation[$root->getLocationId()][] = $root;
                    }

                    foreach ($personalRootsByLocation as $locationId => $roots) {
                        $locIndex = -1;
                        foreach ($locations as $idx => $loc) {
                            if ($loc['id'] === $locationId) {
                                $locIndex = $idx;
                                break;
                            }
                        }

                        $resolved = $locationService->resolveLocation($locationId, $syncCharacter ?? $character);
                        $locationName = $resolved['name'];
                        $systemName = $resolved['systemName'];

                        $items = [];
                        foreach ($roots as $root) {
                            $items[] = $this->buildAssetTreeNodeFromCorpAsset($root, $corpNestedAssets, $sdeService, $prices);
                        }

                        $items = $this->groupAndSortNodes($items, $sdeService, $locationId);

                        if ($locIndex >= 0) {
                            $locations[$locIndex]['items'] = array_merge($locations[$locIndex]['items'], $items);
                            $locations[$locIndex]['items'] = $this->groupAndSortNodes($locations[$locIndex]['items'], $sdeService, $locationId);
                        } else {
                            $locations[] = [
                                'id' => $locationId,
                                'name' => $locationName,
                                'systemName' => $systemName,
                                'items' => $items,
                            ];
                        }

                        foreach ($items as $item) {
                            $totalAssetVal += $this->calculateNodeValue($item);
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
                ->orderBy('s.snapshotDate', 'ASC')
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
                ->orderBy('j.date', 'DESC')
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

        return $this->render('profile/eve_account/assets_overview.html.twig', [
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

    #[Route('/dashboard/value-history', name: 'app_dashboard_value_history', methods: ['GET'])]
    public function valueHistory(): Response
    {
        return $this->redirectToRoute('app_dashboard_assets_overview');
    }

    private function buildAssetTreeNode(EveCharacterAsset $asset, array $nestedAssets, SdeService $sdeService, array $prices): array
    {
        $itemId = $asset->getItemId();
        $typeId = $asset->getTypeId();
        $children = [];
        if (isset($nestedAssets[$itemId])) {
            foreach ($nestedAssets[$itemId] as $childAsset) {
                $children[] = $this->buildAssetTreeNode($childAsset, $nestedAssets, $sdeService, $prices);
            }
            $children = $this->groupAndSortNodes($children, $sdeService, $itemId);
        }

        return [
            'itemId' => $itemId,
            'typeId' => $typeId,
            'name' => $sdeService->getItemName($typeId),
            'customName' => $asset->getCustomName(),
            'quantity' => $asset->getQuantity(),
            'locationFlag' => $asset->getLocationFlag(),
            'isBlueprintCopy' => $asset->isBlueprintCopy(),
            'isBlueprint' => $sdeService->isBlueprint($typeId),
            'isSingleton' => $asset->isSingleton(),
            'price' => $asset->isBlueprintCopy() ? 0.0 : ($prices[$typeId] ?? 0.0),
            'category' => $sdeService->getItemCategory($typeId),
            'materialEfficiency' => $asset->getMaterialEfficiency(),
            'timeEfficiency' => $asset->getTimeEfficiency(),
            'runs' => $asset->getRuns(),
            'children' => $children,
        ];
    }

    private function buildAssetTreeNodeFromCorpAsset(EveCorporationAsset $asset, array $nestedAssets, SdeService $sdeService, array $prices): array
    {
        $itemId = $asset->getItemId();
        $typeId = $asset->getTypeId();
        $children = [];
        if (isset($nestedAssets[$itemId])) {
            foreach ($nestedAssets[$itemId] as $childAsset) {
                $children[] = $this->buildAssetTreeNodeFromCorpAsset($childAsset, $nestedAssets, $sdeService, $prices);
            }
            $children = $this->groupAndSortNodes($children, $sdeService, $itemId);
        }

        return [
            'itemId' => $itemId,
            'typeId' => $typeId,
            'name' => $sdeService->getItemName($typeId),
            'customName' => $asset->getCustomName(),
            'quantity' => $asset->getQuantity(),
            'locationFlag' => $asset->getLocationFlag(),
            'isBlueprintCopy' => $asset->isBlueprintCopy() ?? false,
            'isBlueprint' => $sdeService->isBlueprint($typeId),
            'isSingleton' => $asset->isSingleton(),
            'price' => ($asset->isBlueprintCopy() ?? false) ? 0.0 : ($prices[$typeId] ?? 0.0),
            'category' => $sdeService->getItemCategory($typeId),
            'materialEfficiency' => $asset->getMaterialEfficiency(),
            'timeEfficiency' => $asset->getTimeEfficiency(),
            'runs' => $asset->getRuns(),
            'children' => $children,
        ];
    }

    private function calculateNodeValue(array $node): float
    {
        $val = ($node['price'] ?? 0.0) * ($node['quantity'] ?? 1);
        if (!empty($node['children'])) {
            foreach ($node['children'] as $child) {
                $val += $this->calculateNodeValue($child);
            }
        }
        return $val;
    }

    #[Route('/dashboard/corp-assets', name: 'app_dashboard_corp_assets_overview', methods: ['GET'])]
    public function corpAssetsOverview(LocationService $locationService, SdeService $sdeService, \App\Service\Esi\EsiClient $esiClient): Response
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
                ->orderBy('c.lastCorpAssetsUpdate', 'DESC')
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
            $divisionNames = [];
            if ($syncCharacter) {
                try {
                    $divData = $esiClient->request('GET', sprintf('corporations/%d/divisions/', $corpId), [], $syncCharacter);
                    if (isset($divData['hangar']) && is_array($divData['hangar'])) {
                        foreach ($divData['hangar'] as $div) {
                            $name = $div['name'];
                            if (!preg_match('/^Hangar\s*\d+$/ui', $name)) {
                                $name = preg_replace('/\s*\d+$/u', '', $name);
                            }
                            $divisionNames[(int) $div['division']] = $name;
                        }
                    }
                } catch (\Exception $e) {
                    // Ignore
                }
            }

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
                    $node = $this->buildCorpAssetTreeNode($asset, $nestedAssets, $sdeService, $divisionNames, $isCeoOrAdmin, $visibilityMap, $prices);
                    
                    // If this is an Office (typeId 27) and has no visible children (divisions) left after filtering, skip it
                    if ($node['typeId'] === 27 && empty($node['children'])) {
                        continue;
                    }

                    $groupedByDivision[$folderName][] = $node;
                }

                foreach ($groupedByDivision as $folderName => &$items) {
                    $items = $this->groupAndSortNodes($items, $sdeService, $locationId);
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

    private function buildCorpAssetTreeNode(
        EveCorporationAsset $asset, 
        array $nestedAssets, 
        SdeService $sdeService, 
        array $divisionNames = [],
        bool $isCeoOrAdmin = true,
        array $visibilityMap = [],
        array $prices = []
    ): array {
        $itemId = $asset->getItemId();
        $typeId = $asset->getTypeId();
        
        $children = [];
        if (isset($nestedAssets[$itemId])) {
            $hasOffice = false;
            $officeAsset = null;
            foreach ($nestedAssets[$itemId] as $childAsset) {
                if ($childAsset->getTypeId() === 27) {
                    $hasOffice = true;
                    $officeAsset = $childAsset;
                }
            }

            if ($hasOffice && $officeAsset !== null) {
                // Bypass the office node completely: directly add the office's children (hangars/deliveries)
                $officeNode = $this->buildCorpAssetTreeNode($officeAsset, $nestedAssets, $sdeService, $divisionNames, $isCeoOrAdmin, $visibilityMap, $prices);
                $children = $officeNode['children'];
            } else {
                foreach ($nestedAssets[$itemId] as $childAsset) {
                    // Filter out structure fittings, rigs, core, and fuel blocks
                    $flag = $childAsset->getLocationFlag();
                    if (in_array($flag, [
                        'QuantumCoreRoom',
                        'StructureFuel',
                        'StructureServiceSlot0',
                        'StructureServiceSlot1',
                        'StructureServiceSlot2',
                        'StructureServiceSlot3',
                        'StructureServiceSlot4',
                        'StructureServiceSlot5',
                        'StructureServiceSlot6',
                        'StructureServiceSlot7',
                        'RigSlot0',
                        'RigSlot1',
                        'RigSlot2',
                    ], true)) {
                        continue;
                    }

                    $children[] = $this->buildCorpAssetTreeNode($childAsset, $nestedAssets, $sdeService, $divisionNames, $isCeoOrAdmin, $visibilityMap, $prices);
                }
            }
            
            // If this is an Office (typeId 27), group its children by division!
            if ($typeId === 27) {
                $getDivisionName = function (string $flag) use ($divisionNames) {
                    if (preg_match('/^CorpSAG(\d)$/', $flag, $matches)) {
                        $divIndex = (int) $matches[1];
                        return $divisionNames[$divIndex] ?? 'Hangar ' . $divIndex;
                    }
                    if ($flag === 'CorpDeliveries') {
                        return 'Lieferungen (Deliveries)';
                    }
                    return null;
                };

                $groupedByDiv = [];
                $nonDivChildren = [];

                foreach ($children as $child) {
                    $flag = $child['locationFlag'] ?? '';
                    $divName = $getDivisionName($flag);

                    if ($divName !== null) {
                        if (!$isCeoOrAdmin) {
                            $locId = $asset->getLocationId(); // Office's locationId is the structure ID
                            if (!isset($visibilityMap[$locId][$flag])) {
                                continue; // Hangar is not approved for normal users
                            }
                        }
                        $groupedByDiv[$divName][] = $child;
                    } else {
                        $nonDivChildren[] = $child;
                    }
                }

                foreach ($groupedByDiv as $divName => &$items) {
                    $items = $this->groupAndSortNodes($items, $sdeService, $itemId);
                }
                unset($items);

                $divNodes = [];
                $virtualIdCounter = 1;
                foreach ($groupedByDiv as $divName => $items) {
                    $divNodes[] = [
                        'itemId' => -($itemId * 10 + $virtualIdCounter++), // unique negative ID
                        'typeId' => 0, // special type ID for division folders
                        'name' => $divName,
                        'quantity' => count($items),
                        'locationFlag' => 'Division',
                        'isBlueprintCopy' => false,
                        'isSingleton' => false,
                        'children' => $items,
                    ];
                }

                usort($divNodes, function ($a, $b) use ($divisionNames) {
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
                    return $getDivOrder($a['name']) <=> $getDivOrder($b['name']);
                });

                $children = array_merge($divNodes, $nonDivChildren);
            } else {
                $children = $this->groupAndSortNodes($children, $sdeService, $itemId);
            }
        }

        return [
            'itemId' => $itemId,
            'typeId' => $typeId,
            'name' => $sdeService->getItemName($typeId),
            'customName' => $asset->getCustomName(),
            'quantity' => $asset->getQuantity(),
            'locationFlag' => $asset->getLocationFlag(),
            'isBlueprintCopy' => $asset->isBlueprintCopy(),
            'isBlueprint' => $sdeService->isBlueprint($typeId),
            'isSingleton' => $asset->isSingleton(),
            'price' => $asset->isBlueprintCopy() ? 0.0 : ($prices[$typeId] ?? 0.0),
            'category' => $sdeService->getItemCategory($typeId),
            'materialEfficiency' => $asset->getMaterialEfficiency(),
            'timeEfficiency' => $asset->getTimeEfficiency(),
            'runs' => $asset->getRuns(),
            'children' => $children,
        ];
    }

    /**
     * Groups and sorts assets.
     * 1. Ships are grouped under a virtual folder "Schiffe (Ships)".
     * 2. Containers with content are placed next.
     * 3. All other items are placed last.
     * Each sub-group is sorted alphabetically by name.
     */
    private function groupAndSortNodes(array $nodes, SdeService $sdeService, int $parentId): array
    {
        $shipNodes = [];
        $containerWithContentNodes = [];
        $otherNodes = [];

        foreach ($nodes as $node) {
            $typeId = $node['typeId'] ?? 0;
            $hasChildren = !empty($node['children']);

            if ($sdeService->isShip($typeId)) {
                $shipNodes[] = $node;
            } elseif ($sdeService->isContainer($typeId) && $hasChildren) {
                $containerWithContentNodes[] = $node;
            } else {
                $otherNodes[] = $node;
            }
        }

        // Sort each category alphabetically by name
        usort($shipNodes, function ($a, $b) {
            return strcasecmp($a['name'], $b['name']);
        });
        usort($containerWithContentNodes, function ($a, $b) {
            return strcasecmp($a['name'], $b['name']);
        });
        usort($otherNodes, function ($a, $b) {
            return strcasecmp($a['name'], $b['name']);
        });

        $finalNodes = [];

        // 1. Ships (under a virtual folder if there are any)
        if (count($shipNodes) > 0) {
            $finalNodes[] = [
                'itemId' => -((int)abs($parentId) * 10 + 9999), // unique virtual ID
                'typeId' => 0, // special type ID for virtual folder
                'name' => 'Schiffe (Ships)',
                'quantity' => count($shipNodes),
                'locationFlag' => 'Group',
                'isBlueprintCopy' => false,
                'isSingleton' => false,
                'children' => $shipNodes,
                'price' => 0.0,
            ];
        }

        // 2. Containers with content
        foreach ($containerWithContentNodes as $node) {
            $finalNodes[] = $node;
        }

        // 3. Other items
        foreach ($otherNodes as $node) {
            $finalNodes[] = $node;
        }

        return $finalNodes;
    }

    #[Route('/profile/eve-character/{id}/delete', name: 'app_eve_character_delete', methods: ['POST'])]
    #[IsGranted('IS_AUTHENTICATED_FULLY')]
    public function deleteCharacter(int $id, Request $request): Response
    {
        $character = $this->entityManager->getRepository(EveCharacter::class)->find($id);

        if (!$character || $character->getUser() !== $this->getUser()) {
            throw $this->createAccessDeniedException('Zugriff verweigert.');
        }

        if (!$this->isCsrfTokenValid('eve_character_delete_' . $id, $request->request->get('_token'))) {
            $this->addFlash('error', 'Ungültiges CSRF-Token.');
            return $this->redirectToRoute('app_profile_characters');
        }

        $charName = $character->getName();

        $this->entityManager->remove($character);
        $this->entityManager->flush();

        $this->addFlash('success', sprintf('Charakter "%s" wurde erfolgreich entfernt.', $charName));

        return $this->redirectToRoute('app_profile_characters');
    }

    #[Route('/profile/eve-character/{id}/tags', name: 'app_eve_character_tags', methods: ['POST'])]
    public function updateTags(int $id, Request $request): Response
    {
        $character = $this->entityManager->getRepository(EveCharacter::class)->find($id);

        if (!$character || $character->getUser() !== $this->getUser()) {
            throw $this->createAccessDeniedException('Zugriff verweigert.');
        }

        if (!$this->isCsrfTokenValid('eve_character_tags_' . $id, $request->request->get('_token'))) {
            $this->addFlash('error', 'Ungültiges CSRF-Token.');
            return $this->redirectToRoute('app_profile_characters');
        }

        $submittedTags = $request->request->all('tags'); // Checkbox values
        $customTagsStr = $request->request->get('custom_tags', ''); // text input string

        $tags = [];
        foreach ($submittedTags as $tag) {
            $tag = trim($tag);
            if ($tag !== '' && !in_array($tag, $tags, true)) {
                $tags[] = $tag;
            }
        }

        if ($customTagsStr) {
            // Support comma, semicolon, space, or newlines as separators
            $customTags = preg_split('/[,;\n]+/', $customTagsStr);
            foreach ($customTags as $tag) {
                $tag = trim($tag);
                if ($tag !== '' && !in_array($tag, $tags, true)) {
                    $tags[] = $tag;
                }
            }
        }

        $character->setTags($tags);
        $this->entityManager->flush();

        $this->addFlash('success', sprintf('Tags für Charakter "%s" wurden erfolgreich aktualisiert.', $character->getName()));

        return $this->redirectToRoute('app_profile_characters');
    }
}
