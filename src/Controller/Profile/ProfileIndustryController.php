<?php

namespace App\Controller\Profile;

use App\Entity\User;
use App\Entity\EveCharacter;
use App\Entity\EveCharacterIndustryJob;
use App\Service\LocationService;
use App\Service\SdeService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/dashboard/industry')]
#[IsGranted('ROLE_MEMBER')]
class ProfileIndustryController extends AbstractController
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly SdeService $sdeService,
        private readonly LocationService $locationService
    ) {}

    #[Route('', name: 'app_dashboard_industry_overview', methods: ['GET'])]
    public function index(): Response
    {
        $currentUser = $this->getUser();
        if (!$currentUser instanceof User) {
            return $this->redirectToRoute('app_login');
        }

        // List characters for navigation/sidebars
        $charactersList = [];
        $allCharacters = $this->entityManager->getRepository(EveCharacter::class)->findBy(['user' => $currentUser]);
        foreach ($allCharacters as $char) {
            $charactersList[] = [
                'id' => $char->getId(),
                'name' => $char->getName(),
                'hasToken' => !empty($char->getRefreshToken()),
            ];
        }

        return $this->render('profile/profile_industry/industry_jobs.html.twig', [
            'charactersList' => $charactersList,
        ]);
    }

    #[Route('/data', name: 'app_dashboard_industry_data', methods: ['GET'])]
    public function getIndustryData(): JsonResponse
    {
        $currentUser = $this->getUser();
        if (!$currentUser instanceof User) {
            return new JsonResponse(['error' => 'Unauthorized'], Response::HTTP_UNAUTHORIZED);
        }

        $characters = $this->entityManager->getRepository(EveCharacter::class)->findBy(['user' => $currentUser]);
        $result = [];
        $locationCache = [];

        foreach ($characters as $character) {
            if (empty($character->getRefreshToken())) {
                $result[] = [
                    'id' => $character->getId(),
                    'name' => $character->getName(),
                    'jobs' => [],
                    'lastUpdate' => null,
                    'error' => 'Kein Refresh-Token vorhanden. Bitte logge dich erneut mit diesem Charakter ein.',
                ];
                continue;
            }

            try {
                // Since the frontend only displays active jobs, only fetch active jobs from database
                $jobs = $this->entityManager->getRepository(EveCharacterIndustryJob::class)->findBy(
                    ['character' => $character, 'status' => 'active'],
                    ['endDate' => 'ASC']
                );

                $mappedJobs = [];
                foreach ($jobs as $job) {
                    $blueprintName = $this->sdeService->getItemName($job->getBlueprintTypeId()) ?: 'Unbekanntes Blueprint';
                    $productName = $job->getProductTypeId() ? ($this->sdeService->getItemName($job->getProductTypeId()) ?: 'Unbekanntes Produkt') : null;
                    
                    // Resolve location names with a request-bound cache to avoid repeated ESI/DB queries
                    $bpLocId = (int) $job->getBlueprintLocationId();
                    if (!isset($locationCache[$bpLocId])) {
                        $locationCache[$bpLocId] = $this->locationService->resolveLocation($bpLocId, $character);
                    }
                    $bpLoc = $locationCache[$bpLocId];

                    $outLocId = (int) $job->getOutputLocationId();
                    if (!isset($locationCache[$outLocId])) {
                        $locationCache[$outLocId] = $this->locationService->resolveLocation($outLocId, $character);
                    }
                    $outLoc = $locationCache[$outLocId];

                    $mappedJobs[] = [
                        'jobId' => $job->getJobId(),
                        'installerId' => $job->getInstallerId(),
                        'blueprintId' => $job->getBlueprintId(),
                        'blueprintTypeId' => $job->getBlueprintTypeId(),
                        'blueprintName' => $blueprintName,
                        'blueprintLocationName' => $bpLoc['name'],
                        'outputLocationName' => $outLoc['name'],
                        'productTypeId' => $job->getProductTypeId(),
                        'productName' => $productName,
                        'activityId' => $job->getActivityId(),
                        'runs' => $job->getRuns(),
                        'successfulRuns' => $job->getSuccessfulRuns(),
                        'duration' => $job->getDuration(),
                        'startDate' => $job->getStartDate()->format(\DateTimeInterface::ATOM),
                        'endDate' => $job->getEndDate()->format(\DateTimeInterface::ATOM),
                        'pauseDate' => $job->getPauseDate() ? $job->getPauseDate()->format(\DateTimeInterface::ATOM) : null,
                        'completedDate' => $job->getCompletedDate() ? $job->getCompletedDate()->format(\DateTimeInterface::ATOM) : null,
                        'status' => $job->getStatus(),
                        'cost' => $job->getCost(),
                        'probability' => $job->getProbability(),
                        'licenceLimit' => $job->getLicenceLimit(),
                    ];
                }

                $result[] = [
                    'id' => $character->getId(),
                    'name' => $character->getName(),
                    'jobs' => $mappedJobs,
                    'lastUpdate' => $character->getLastIndustryJobsUpdate() ? $character->getLastIndustryJobsUpdate()->format(\DateTimeInterface::ATOM) : null,
                    'error' => null,
                ];

            } catch (\Exception $e) {
                $result[] = [
                    'id' => $character->getId(),
                    'name' => $character->getName(),
                    'jobs' => [],
                    'lastUpdate' => null,
                    'error' => 'Fehler beim Abrufen der Industriedaten: ' . $e->getMessage(),
                ];
            }
        }

        return new JsonResponse([
            'characters' => $result
        ]);
    }
}
