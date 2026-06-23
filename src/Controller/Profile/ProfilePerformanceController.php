<?php

namespace App\Controller\Profile;

use App\Entity\User;
use App\Entity\EveCharacter;
use App\Service\PerformanceEngine;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/dashboard/performance')]
#[IsGranted('ROLE_MEMBER')]
class ProfilePerformanceController extends AbstractController
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly PerformanceEngine $performanceEngine
    ) {}

    #[Route('', name: 'app_dashboard_performance_ledger', methods: ['GET'])]
    public function index(): Response
    {
        $currentUser = $this->getUser();
        if (!$currentUser instanceof User) {
            return $this->redirectToRoute('app_login');
        }

        // Collect character list matching patterns of other dashboard controllers
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

        // De-duplicate
        $uniqueCharacters = [];
        foreach ($charactersList as $char) {
            $uniqueCharacters[$char['id']] = $char;
        }
        $charactersList = array_values($uniqueCharacters);

        // Sort by character name
        usort($charactersList, fn($a, $b) => strcasecmp($a['name'], $b['name']));

        return $this->render('profile/profile_performance/performance_ledger.html.twig', [
            'charactersList' => $charactersList,
        ]);
    }

    #[Route('/data', name: 'app_dashboard_performance_data', methods: ['GET'])]
    public function getPerformanceData(): JsonResponse
    {
        $currentUser = $this->getUser();
        if (!$currentUser instanceof User) {
            return new JsonResponse(['error' => 'Unauthorized'], Response::HTTP_UNAUTHORIZED);
        }

        try {
            $data = $this->performanceEngine->calculateDailyPerformance($currentUser);
            return new JsonResponse($data);
        } catch (\Exception $e) {
            return new JsonResponse([
                'error' => 'Failed to calculate performance data: ' . $e->getMessage()
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }
}
