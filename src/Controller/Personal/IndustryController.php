<?php

namespace App\Controller\Personal;

use App\Entity\User;
use App\Entity\EveCharacter;
use App\Entity\EveCharacterIndustryJob;
use App\Service\LocationService;
use App\Service\SdeService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

use App\Service\JitaPriceService;

#[Route('/personal/industry')]
#[IsGranted('ROLE_MEMBER')]
class IndustryController extends AbstractController
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly SdeService $sdeService,
        private readonly LocationService $locationService,
        private readonly JitaPriceService $jitaPriceService
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
                'tags' => $char->getTags(),
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
        
        $blueprintDetails = [];
        $uniqueTypeIds = [];

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

    #[Route('/blueprint-finances', name: 'app_dashboard_industry_blueprint_finances', methods: ['GET'])]
    public function getBlueprintFinances(Request $request): JsonResponse
    {
        $blueprintTypeId = (int)$request->query->get('blueprintTypeId');
        $activityId = (int)$request->query->get('activityId');
        $productTypeId = $request->query->get('productTypeId') ? (int)$request->query->get('productTypeId') : null;

        if ($blueprintTypeId <= 0 || $activityId <= 0) {
            return new JsonResponse(['error' => 'Invalid parameters'], Response::HTTP_BAD_REQUEST);
        }

        $details = $this->sdeService->getBlueprintDetails($blueprintTypeId, $activityId);
        
        $uniqueTypeIds = [];
        foreach ($details['materials'] as $m) {
            $uniqueTypeIds[] = (int)$m['typeId'];
        }
        foreach ($details['products'] as $p) {
            $uniqueTypeIds[] = (int)$p['typeId'];
        }
        if ($productTypeId) {
            $uniqueTypeIds[] = $productTypeId;
        }
        $uniqueTypeIds[] = $blueprintTypeId;
        $uniqueTypeIds = array_values(array_unique($uniqueTypeIds));

        $marketPrices = [];
        foreach ($uniqueTypeIds as $typeId) {
            $buyInfo = $this->jitaPriceService->getAverageJitaPrice($typeId, true);
            $sellInfo = $this->jitaPriceService->getAverageJitaPrice($typeId, false);
            $marketPrices[$typeId] = [
                'buy' => $buyInfo['price'],
                'sell' => $sellInfo['price']
            ];
        }

        return new JsonResponse([
            'blueprintDetails' => $details,
            'marketPrices' => $marketPrices
        ]);
    }
}
