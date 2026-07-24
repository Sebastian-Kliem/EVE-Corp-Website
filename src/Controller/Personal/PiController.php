<?php

namespace App\Controller\Personal;

use App\Entity\User;
use App\Entity\EveCharacter;
use App\Entity\EveCharacterPi;
use App\Service\PiSimulationService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/personal/pi')]
#[IsGranted('ROLE_MEMBER')]
class PiController extends AbstractController
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly PiSimulationService $piSimulationService
    ) {}

    #[Route('', name: 'app_dashboard_pi_overview', methods: ['GET'])]
    public function index(): Response
    {
        $currentUser = $this->getUser();
        if (!$currentUser instanceof User) {
            return $this->redirectToRoute('app_login');
        }



        // Collect all character IDs to pass to frontend so it knows what to load
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

        // Also add characters directly attached to user if any
        $allCharacters = $this->entityManager->getRepository(EveCharacter::class)->findBy(['user' => $currentUser]);
        foreach ($allCharacters as $char) {
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

        // De-duplicate character list
        $uniqueCharacters = [];
        foreach ($charactersList as $char) {
            $uniqueCharacters[$char['id']] = $char;
        }
        $charactersList = array_values($uniqueCharacters);

        // Sort by account group, account name, character name
        usort($charactersList, function ($a, $b) {
            $grpCmp = strcasecmp($a['accountGroup'], $b['accountGroup']);
            if ($grpCmp !== 0) return $grpCmp;
            $accCmp = strcasecmp($a['accountName'], $b['accountName']);
            if ($accCmp !== 0) return $accCmp;
            return strcasecmp($a['name'], $b['name']);
        });

        return $this->render('profile/profile_pi/pi_overview.html.twig', [
            'charactersList' => $charactersList,
        ]);
    }

    #[Route('/data', name: 'app_dashboard_pi_data', methods: ['GET'])]
    public function getPiData(): JsonResponse
    {
        $currentUser = $this->getUser();
        if (!$currentUser instanceof User) {
            return new JsonResponse(['error' => 'Unauthorized'], Response::HTTP_UNAUTHORIZED);
        }

        // Fetch all characters associated with this user
        $characters = $this->entityManager->getRepository(EveCharacter::class)->findBy(['user' => $currentUser]);

        $data = [];

        foreach ($characters as $character) {
            if (empty($character->getRefreshToken())) {
                continue;
            }

            // Load cached PI data from the local database
            $piEntry = $this->entityManager->getRepository(EveCharacterPi::class)->findOneBy(['character' => $character]);

            if ($piEntry) {
                $piData = $piEntry->getPiData();
                $planets = $piData['planets'] ?? [];
                
                // Simulate production flow for nodes/routes to reflect actual inventory
                $simulatedPlanets = $this->piSimulationService->simulatePlanets($planets);

                $data[] = [
                    'character_id' => $character->getId(),
                    'character_name' => $character->getName(),
                    'planets' => $simulatedPlanets,
                    'unassigned_pocos' => $piData['unassigned_pocos'] ?? [],
                    'last_updated' => $piEntry->getLastUpdated()->format('d.m.Y H:i'),
                ];
            } else {
                $data[] = [
                    'character_id' => $character->getId(),
                    'character_name' => $character->getName(),
                    'planets' => [],
                    'unassigned_pocos' => [],
                    'warning' => 'PI-Daten wurden noch nicht synchronisiert. Bitte warte auf den nächsten Cronjob-Durchlauf.',
                ];
            }
        }

        return new JsonResponse($data);
    }
}
