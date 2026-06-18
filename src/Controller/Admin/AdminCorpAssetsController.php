<?php

namespace App\Controller\Admin;

use App\Entity\CorpAssetVisibility;
use App\Entity\EveCharacter;
use App\Entity\EveCorporationAsset;
use App\Service\Esi\EsiClient;
use App\Service\LocationService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/admin')]
#[IsGranted('ROLE_CEO')]
class AdminCorpAssetsController extends AbstractController
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly LocationService $locationService,
        private readonly EsiClient $esiClient
    ) {}

    #[Route('/corp-assets-visibility', name: 'app_admin_corp_assets_visibility', methods: ['GET', 'POST'])]
    public function manageVisibility(Request $request): Response
    {
        // 1. Fetch all unique locations and their corporation IDs from EveCorporationAsset
        $corpAssets = $this->entityManager->getRepository(EveCorporationAsset::class)->findAll();

        $assetsByItemId = [];
        foreach ($corpAssets as $asset) {
            $assetsByItemId[(string)$asset->getItemId()] = $asset;
        }

        $locationIds = [];
        $corpIds = [];
        foreach ($corpAssets as $asset) {
            $corpIds[] = $asset->getCorporationId();

            // Resolve the physical base location (NPC Station or owned structure)
            $baseLocId = $this->getBaseLocationId((string)$asset->getLocationId(), $assetsByItemId);

            $baseLocNum = (int)$baseLocId;
            $isNpcStation = ($baseLocNum >= 60000000 && $baseLocNum < 64000000);
            $isOwnedStructure = ($baseLocNum >= 1000000000000 && isset($assetsByItemId[$baseLocId]));

            if ($isNpcStation || $isOwnedStructure) {
                $locationIds[] = $baseLocId;
            }
        }
        $locationIds = array_unique($locationIds);
        $corpIds = array_unique($corpIds);

        // 2. Fetch division names for each corporation using its sync character
        $corpDivisions = [];
        foreach ($corpIds as $corpId) {
            $syncCharacter = $this->entityManager->getRepository(EveCharacter::class)->createQueryBuilder('c')
                ->where('c.corporationId = :corpId')
                ->andWhere('c.lastCorpAssetsUpdate IS NOT NULL')
                ->setParameter('corpId', $corpId)
                ->orderBy('c.lastCorpAssetsUpdate', 'DESC')
                ->setMaxResults(1)
                ->getQuery()
                ->getOneOrNullResult();

            $divisionNames = [];
            if ($syncCharacter) {
                try {
                    $divData = $this->esiClient->request('GET', sprintf('corporations/%d/divisions/', $corpId), [], $syncCharacter);
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
            $corpDivisions[$corpId] = $divisionNames;
        }

        // 3. Resolve location names and systems
        // We'll find one sync character in general or use the first available one to resolve locations
        $anySyncCharacter = $this->entityManager->getRepository(EveCharacter::class)->createQueryBuilder('c')
            ->where('c.lastCorpAssetsUpdate IS NOT NULL')
            ->orderBy('c.lastCorpAssetsUpdate', 'DESC')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();

        $resolvedLocations = [];
        foreach ($locationIds as $locationId) {
            $resolved = $this->locationService->resolveLocation($locationId, $anySyncCharacter);
            $resolvedLocations[$locationId] = [
                'id' => $locationId,
                'name' => $resolved['name'],
                'systemName' => $resolved['systemName']
            ];
        }

        // Sort resolved locations by system name, then by location name
        uasort($resolvedLocations, function ($a, $b) {
            $sysCompare = strcasecmp($a['systemName'], $b['systemName']);
            if ($sysCompare !== 0) {
                return $sysCompare;
            }
            return strcasecmp($a['name'], $b['name']);
        });

        // 4. Fetch existing visibility settings
        $visibilities = $this->entityManager->getRepository(CorpAssetVisibility::class)->findAll();
        $visibilityMap = [];
        foreach ($visibilities as $visibility) {
            $allowedUsers = [];
            foreach ($visibility->getUsers() as $user) {
                $allowedUsers[] = $user->getUsername();
            }
            $visibilityMap[$visibility->getLocationId()][$visibility->getLocationFlag()] = [
                'visible' => $visibility->isVisible(),
                'users' => $allowedUsers
            ];
        }

        // Fetch all users for the autocomplete component
        $users = $this->entityManager->getRepository(\App\Entity\User::class)->findAll();
        $allUsers = array_map(fn($u) => $u->getUsername(), $users);
        natcasesort($allUsers);
        $allUsers = array_values($allUsers);

        // 5. Handle Form Submission (POST)
        if ($request->isMethod('POST')) {
            // CSRF protection
            if (!$this->isCsrfTokenValid('admin_corp_assets_visibility', $request->request->get('_token'))) {
                $this->addFlash('error', 'Ungültiges CSRF-Token.');
                return $this->redirectToRoute('app_admin_corp_assets_visibility');
            }

            $submittedVisibility = $request->request->all('visibility');

            // Reset all visibilities or delete old entries to do a clean write
            $visRepo = $this->entityManager->getRepository(CorpAssetVisibility::class);
            $allVis = $visRepo->findAll();
            foreach ($allVis as $v) {
                $this->entityManager->remove($v);
            }
            $this->entityManager->flush();

            // Insert new visibilities
            if (is_array($submittedVisibility)) {
                foreach ($submittedVisibility as $locId => $flags) {
                    if (!is_array($flags)) {
                        continue;
                    }
                    foreach ($flags as $flag => $data) {
                        if (is_array($data) && isset($data['visible']) && $data['visible'] === '1') {
                            $v = new CorpAssetVisibility();
                            $v->setLocationId((string)$locId);
                            $v->setLocationFlag((string)$flag);
                            $v->setIsVisible(true);

                            if (isset($data['users']) && is_array($data['users'])) {
                                foreach ($data['users'] as $username) {
                                    $user = $this->entityManager->getRepository(\App\Entity\User::class)->findOneBy(['username' => $username]);
                                    if ($user) {
                                        $v->addUser($user);
                                    }
                                }
                            }

                            $this->entityManager->persist($v);
                        }
                    }
                }
            }
            $this->entityManager->flush();

            $this->addFlash('success', 'Sichtbarkeits-Freigaben wurden erfolgreich gespeichert.');
            return $this->redirectToRoute('app_admin_corp_assets_visibility');
        }

        // 6. Map divisions for rendering (Hangar 1-7)
        $flagsToMap = [
            'CorpSAG1' => 1,
            'CorpSAG2' => 2,
            'CorpSAG3' => 3,
            'CorpSAG4' => 4,
            'CorpSAG5' => 5,
            'CorpSAG6' => 6,
            'CorpSAG7' => 7
        ];

        return $this->render('admin/admin_corp_assets/corp_assets_visibility.html.twig', [
            'locations' => $resolvedLocations,
            'flagsToMap' => $flagsToMap,
            'corpDivisions' => $corpDivisions,
            'defaultDivisions' => !empty($corpDivisions) ? reset($corpDivisions) : [],
            'visibilityMap' => $visibilityMap,
            'allUsers' => $allUsers
        ]);
    }

    /**
     * Resolves the physical base location (NPC Station ID or Upwell Structure ID)
     * for a given location ID, by traversing up the asset containment tree.
     */
    private function getBaseLocationId(string $locationId, array $assetsByItemId): string
    {
        $currentId = $locationId;

        while (true) {
            $currentIdNum = (int)$currentId;

            if ($currentIdNum >= 60000000 && $currentIdNum < 64000000) {
                return $currentId;
            }

            if ($currentIdNum >= 30000000 && $currentIdNum < 32000000) {
                return $currentId;
            }

            if (isset($assetsByItemId[$currentId])) {
                $parentAsset = $assetsByItemId[$currentId];
                $parentId = $parentAsset->getLocationId();
                $parentIdNum = (int)$parentId;

                if ($parentIdNum >= 30000000 && $parentIdNum < 32000000) {
                    return $currentId;
                }

                $currentId = $parentId;
            } else {
                break;
            }
        }

        return $currentId;
    }
}
