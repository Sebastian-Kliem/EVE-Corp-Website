<?php

namespace App\Controller\Corp;

use App\Entity\User;
use App\Entity\EveCharacter;
use App\Entity\EveCharacterAsset;
use App\Entity\EveCharacterIndustryJob;
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
    public function index(): Response
    {
        $currentUser = $this->getUser();
        if (!$currentUser instanceof User) {
            return $this->redirectToRoute('app_login');
        }

        // 1. Get all users who enabled blueprint sharing
        $sharingUsers = $this->entityManager->getRepository(User::class)->findBy(['shareBlueprints' => true]);
        
        $blueprintsData = [];
        if (!empty($sharingUsers)) {
            // Get all characters for sharing users
            $characters = $this->entityManager->getRepository(EveCharacter::class)->findBy(['user' => $sharingUsers]);
            
            if (!empty($characters)) {
                $blueprintTypeIds = $this->sdeService->getAllBlueprintTypeIds();

                if (!empty($blueprintTypeIds)) {
                    // Fetch all blueprint assets for these characters
                    $assets = $this->entityManager->getRepository(EveCharacterAsset::class)->createQueryBuilder('a')
                        ->where('a.character IN (:characters)')
                        ->andWhere('a.typeId IN (:blueprintTypeIds)')
                        ->setParameter('characters', $characters)
                        ->setParameter('blueprintTypeIds', $blueprintTypeIds)
                        ->getQuery()
                        ->getResult();

                    // Fetch active/paused industry jobs for these characters that involve research or copying (activities 3, 4, 5)
                    $jobs = $this->entityManager->getRepository(EveCharacterIndustryJob::class)->createQueryBuilder('j')
                        ->where('j.character IN (:characters)')
                        ->andWhere('j.activityId IN (:activities)')
                        ->andWhere('j.status IN (:statuses)')
                        ->setParameter('characters', $characters)
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

                    // Process blueprint assets and group identical ones
                    $groupedBlueprints = [];
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

                        // Generate a key to group completely identical blueprints
                        // If it has an active job, keep it separate to display the job status correctly
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
                    $blueprintsData = array_values($groupedBlueprints);
                }
            }
        }

        // Sort blueprints by name
        usort($blueprintsData, fn($a, $b) => strcasecmp($a['name'], $b['name']));

        return $this->render('corp/blueprints.html.twig', [
            'blueprints' => $blueprintsData,
        ]);
    }
}
