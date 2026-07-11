<?php

namespace App\Controller\Corp;

use App\Entity\User;
use App\Entity\EveCharacter;
use App\Entity\EveCharacterAsset;
use App\Entity\EveCharacterIndustryJob;
use App\Entity\EveCorporationAsset;
use App\Service\Esi\EsiClient;

use App\Service\LocationService;
use App\Service\SdeService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/corp/blueprints')]
#[IsGranted('ROLE_MEMBER')]
class BlueprintVaultController extends AbstractController
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly SdeService $sdeService,
        private readonly LocationService $locationService
    ) {}

    #[Route('', name: 'app_corp_blueprints', methods: ['GET'])]
    public function index(EsiClient $esiClient): Response
    {
        $currentUser = $this->getUser();
        if (!$currentUser instanceof User) {
            return $this->redirectToRoute('app_login');
        }

        // Get the unique corporation IDs associated with the current user's characters
        $currentUserCharacters = $this->entityManager->getRepository(EveCharacter::class)->findBy(['user' => $currentUser]);
        $userCorpIds = [];
        foreach ($currentUserCharacters as $char) {
            if ($char->getCorporationId()) {
                $userCorpIds[] = $char->getCorporationId();
            }
        }
        $userCorpIds = array_unique($userCorpIds);

        // Fetch all users in the system to resolve personal corp assets and settings
        $allUsers = $this->entityManager->getRepository(User::class)->findAll();

        // Get all users who enabled blueprint sharing
        $sharingUsers = array_filter($allUsers, fn($u) => $u->isShareBlueprints());
        
        $blueprintsData = [];
        $blueprintTypeIds = $this->sdeService->getAllBlueprintTypeIds();

        if (!empty($blueprintTypeIds)) {
            // Find a character for each corporation to resolve structure locations
            $charByCorp = [];
            $allCharacters = $this->entityManager->getRepository(EveCharacter::class)->findAll();
            foreach ($allCharacters as $char) {
                if ($char->getCorporationId()) {
                    $charByCorp[$char->getCorporationId()] = $char;
                }
            }

            // Resolve corporation names helper
            $corpNames = [];
            $getCorpName = function (int $corpId) use ($esiClient, &$corpNames, $charByCorp) {
                if (isset($corpNames[$corpId])) {
                    return $corpNames[$corpId];
                }
                $syncChar = $charByCorp[$corpId] ?? null;
                if ($syncChar) {
                    try {
                        $corpData = $esiClient->request('GET', sprintf('corporations/%d/', $corpId), [], $syncChar);
                        if (isset($corpData['name'])) {
                            $corpNames[$corpId] = $corpData['name'];
                            return $corpData['name'];
                        }
                    } catch (\Exception $e) {
                        // Ignore
                    }
                }
                return 'Corp #' . $corpId;
            };

            $groupedBlueprints = [];

            // 1. Process personal blueprints from sharing users
            if (!empty($sharingUsers)) {
                $sharingCharacters = $this->entityManager->getRepository(EveCharacter::class)->findBy(['user' => $sharingUsers]);
                
                if (!empty($sharingCharacters)) {
                    $assets = $this->entityManager->getRepository(EveCharacterAsset::class)->createQueryBuilder('a')
                        ->where('a.character IN (:characters)')
                        ->andWhere('a.typeId IN (:blueprintTypeIds)')
                        ->setParameter('characters', $sharingCharacters)
                        ->setParameter('blueprintTypeIds', $blueprintTypeIds)
                        ->getQuery()
                        ->getResult();

                    // Fetch active/paused industry jobs for these characters (activities 3, 4, 5)
                    $jobs = $this->entityManager->getRepository(EveCharacterIndustryJob::class)->createQueryBuilder('j')
                        ->where('j.character IN (:characters)')
                        ->andWhere('j.activityId IN (:activities)')
                        ->andWhere('j.status IN (:statuses)')
                        ->setParameter('characters', $sharingCharacters)
                        ->setParameter('activities', [3, 4, 5])
                        ->setParameter('statuses', ['active', 'paused'])
                        ->getQuery()
                        ->getResult();

                    // Map active jobs by blueprintId
                    $jobsByBlueprintId = [];
                    /** @var EveCharacterIndustryJob $job */
                    foreach ($jobs as $job) {
                        if ($job->getBlueprintId()) {
                            $jobsByBlueprintId[$job->getBlueprintId()] = $job;
                        }
                    }

                    /** @var EveCharacterAsset $asset */
                    foreach ($assets as $asset) {
                        $char = $asset->getCharacter();
                        $ownerUser = $char->getUser();
                        $typeId = $asset->getTypeId();
                        $itemId = (string)$asset->getItemId();
                        $isBpo = !$asset->isBlueprintCopy();
                        $me = $asset->getMaterialEfficiency() ?? 0;
                        $te = $asset->getTimeEfficiency() ?? 0;
                        $runs = $asset->getRuns() ?? -1;

                        $resolvedLoc = $this->locationService->resolveLocation($asset->getLocationId(), $char);
                        $locationName = $resolvedLoc['name'];
                        
                        $jobData = null;
                        if (isset($jobsByBlueprintId[$itemId])) {
                            /** @var EveCharacterIndustryJob $job */
                            $job = $jobsByBlueprintId[$itemId];
                            $jobData = [
                                'activityId' => $job->getActivityId(),
                                'endDate' => $job->getEndDate()->format('c'),
                                'runs' => $job->getRuns(),
                                'status' => $job->getStatus(),
                            ];
                        }

                        $jobKey = $jobData ? '_job_' . $itemId : '';
                        $groupKey = sprintf(
                            '%d_%d_%d_%d_%s_%s_%d%s',
                            $typeId,
                            $isBpo ? 1 : 0,
                            $me,
                            $te,
                            $char->getName(),
                            $locationName,
                            $runs,
                            $jobKey
                        );

                        if (isset($groupedBlueprints[$groupKey])) {
                            $groupedBlueprints[$groupKey]['quantity'] += (int)$asset->getQuantity();
                        } else {
                            $prodInfo = $this->sdeService->getBlueprintProductInfo($typeId);
                            $groupedBlueprints[$groupKey] = [
                                'itemId' => $itemId,
                                'typeId' => $typeId,
                                'productId' => $prodInfo['productId'],
                                'name' => $this->sdeService->getItemName($typeId),
                                'category' => $prodInfo['category'],
                                'ownerCharacterName' => $char->getName(),
                                'ownerUserName' => $ownerUser ? $ownerUser->getUsername() : 'Unknown',
                                'locationName' => $locationName,
                                'systemName' => $resolvedLoc['systemName'],
                                'isBpo' => $isBpo,
                                'me' => $me,
                                'te' => $te,
                                'runs' => $runs,
                                'quantity' => (int)$asset->getQuantity(),
                                'activeJob' => $jobData,
                            ];
                        }
                    }
                }
            }

            // 2. Process corporation blueprints for user corporations
            if (!empty($userCorpIds)) {
                // Build maps of personal hangars/containers to handle privacy and ownership
                $personalHangarsMap = [];
                $personalContainersMap = [];
                foreach ($allUsers as $u) {
                    foreach ($u->getPersonalCorpHangars() as $conf) {
                        $key = sprintf('%d_%d_%s', $conf['corporationId'], $conf['locationId'], $conf['locationFlag']);
                        $personalHangarsMap[$key] = $u;
                    }
                    foreach ($u->getPersonalCorpContainers() as $conf) {
                        $key = sprintf('%d_%d', $conf['corporationId'], $conf['itemId']);
                        $personalContainersMap[$key] = $u;
                    }
                }

                $corpAssets = $this->entityManager->getRepository(EveCorporationAsset::class)->createQueryBuilder('c')
                    ->where('c.corporationId IN (:userCorpIds)')
                    ->andWhere('c.typeId IN (:blueprintTypeIds)')
                    ->setParameter('userCorpIds', $userCorpIds)
                    ->setParameter('blueprintTypeIds', $blueprintTypeIds)
                    ->getQuery()
                    ->getResult();

                /** @var EveCorporationAsset $corpAsset */
                foreach ($corpAssets as $corpAsset) {
                    $corpId = $corpAsset->getCorporationId();
                    $typeId = $corpAsset->getTypeId();
                    $itemId = (string)$corpAsset->getItemId();
                    $isBpo = !$corpAsset->isBlueprintCopy();
                    $me = $corpAsset->getMaterialEfficiency() ?? 0;
                    $te = $corpAsset->getTimeEfficiency() ?? 0;
                    $runs = $corpAsset->getRuns() ?? -1;

                    // Check if inside a personal container first
                    $ownerUser = null;
                    $containerKey = sprintf('%d_%d', $corpId, $corpAsset->getLocationId());
                    if (isset($personalContainersMap[$containerKey])) {
                        $ownerUser = $personalContainersMap[$containerKey];
                    }

                    // Check if inside a personal hangar
                    if (!$ownerUser) {
                        $hangarKey = sprintf('%d_%d_%s', $corpId, $corpAsset->getLocationId(), $corpAsset->getLocationFlag());
                        if (isset($personalHangarsMap[$hangarKey])) {
                            $ownerUser = $personalHangarsMap[$hangarKey];
                        }
                    }

                    // If it belongs to a personal hangar/container:
                    if ($ownerUser) {
                        // If that user has disabled blueprint sharing, hide it from the vault!
                        if (!$ownerUser->isShareBlueprints()) {
                            continue;
                        }

                        $ownerLabel = '👤 ' . $ownerUser->getUsername();
                        $ownerType = 'Hangar von ' . $ownerUser->getUsername();
                    } else {
                        // Standard corp blueprint
                        $ownerLabel = '🏢 ' . $getCorpName($corpId);
                        $ownerType = 'Corporation';
                    }

                    $syncChar = $charByCorp[$corpId] ?? null;
                    $resolvedLoc = $this->locationService->resolveLocation($corpAsset->getLocationId(), $syncChar);
                    $locationName = $resolvedLoc['name'];

                    $groupKey = sprintf(
                        '%d_%d_%d_%d_%s_%s_%d',
                        $typeId,
                        $isBpo ? 1 : 0,
                        $me,
                        $te,
                        $ownerLabel,
                        $locationName,
                        $runs
                    );

                    if (isset($groupedBlueprints[$groupKey])) {
                        $groupedBlueprints[$groupKey]['quantity'] += (int)$corpAsset->getQuantity();
                    } else {
                        $prodInfo = $this->sdeService->getBlueprintProductInfo($typeId);
                        $groupedBlueprints[$groupKey] = [
                            'itemId' => $itemId,
                            'typeId' => $typeId,
                            'productId' => $prodInfo['productId'],
                            'name' => $this->sdeService->getItemName($typeId),
                            'category' => $prodInfo['category'],
                            'ownerCharacterName' => $ownerLabel,
                            'ownerUserName' => $ownerType,
                            'locationName' => $locationName,
                            'systemName' => $resolvedLoc['systemName'],
                            'isBpo' => $isBpo,
                            'me' => $me,
                            'te' => $te,
                            'runs' => $runs,
                            'quantity' => (int)$corpAsset->getQuantity(),
                            'activeJob' => null,
                        ];
                    }
                }
            }

            $blueprintsData = array_values($groupedBlueprints);
        }

        // Sort blueprints by name
        usort($blueprintsData, fn($a, $b) => strcasecmp($a['name'], $b['name']));

        return $this->render('corp/blueprints.html.twig', [
            'blueprints' => $blueprintsData,
        ]);
    }
}
