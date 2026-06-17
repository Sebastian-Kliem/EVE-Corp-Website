<?php

namespace App\Controller\Profile;

use App\Entity\User;
use App\Entity\EveCharacter;
use App\Service\Esi\EsiClient;
use App\Service\JitaPriceService;
use App\Service\SdeService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/dashboard/mining')]
#[IsGranted('ROLE_MEMBER')]
class ProfileMiningLedgerController extends AbstractController
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly EsiClient $esiClient,
        private readonly SdeService $sdeService,
        private readonly JitaPriceService $jitaPriceService
    ) {}

    #[Route('', name: 'app_dashboard_mining_ledger', methods: ['GET'])]
    public function index(): Response
    {
        $currentUser = $this->getUser();
        if (!$currentUser instanceof User) {
            return $this->redirectToRoute('app_login');
        }

        // Gather characters to list/pre-render initial structures
        $charactersList = [];
        $allCharacters = $this->entityManager->getRepository(EveCharacter::class)->findBy(['user' => $currentUser]);
        foreach ($allCharacters as $char) {
            $charactersList[] = [
                'id' => $char->getId(),
                'name' => $char->getName(),
                'hasToken' => !empty($char->getRefreshToken()),
            ];
        }

        return $this->render('profile/mining_ledger.html.twig', [
            'charactersList' => $charactersList,
        ]);
    }

    #[Route('/data', name: 'app_dashboard_mining_data', methods: ['GET'])]
    public function getMiningData(): JsonResponse
    {
        $currentUser = $this->getUser();
        if (!$currentUser instanceof User) {
            return new JsonResponse(['error' => 'Unauthorized'], Response::HTTP_UNAUTHORIZED);
        }

        $characters = $this->entityManager->getRepository(EveCharacter::class)->findBy(['user' => $currentUser]);
        $prices = $this->jitaPriceService->getGlobalPrices();

        $result = [];

        foreach ($characters as $character) {
            if (empty($character->getRefreshToken())) {
                $result[] = [
                    'id' => $character->getId(),
                    'name' => $character->getName(),
                    'records' => [],
                    'error' => 'Kein Refresh-Token vorhanden. Bitte logge dich erneut mit diesem Charakter ein.',
                ];
                continue;
            }

            try {
                $allRecords = [];
                $page = 1;

                while (true) {
                    $records = $this->esiClient->request('GET', sprintf('characters/%d/mining/', $character->getId()), [
                        'query' => ['page' => $page]
                    ], $character);

                    if (empty($records) || !is_array($records)) {
                        break;
                    }

                    $allRecords = array_merge($allRecords, $records);
                    if (count($records) < 1000) {
                        break;
                    }
                    $page++;
                }

                // Map raw records to UI-friendly records
                $mappedRecords = [];
                foreach ($allRecords as $record) {
                    $typeId = (int)$record['type_id'];
                    $solarSystemId = (int)$record['solar_system_id'];
                    
                    $typeName = $this->sdeService->getItemName($typeId);
                    $solarSystemName = $this->sdeService->getLocationName($solarSystemId);
                    $price = $prices[$typeId] ?? 0.0;

                    $mappedRecords[] = [
                        'date' => $record['date'],
                        'solarSystemId' => $solarSystemId,
                        'solarSystemName' => $solarSystemName,
                        'typeId' => $typeId,
                        'typeName' => $typeName,
                        'quantity' => (int)$record['quantity'],
                        'price' => $price,
                        'value' => (int)$record['quantity'] * $price,
                    ];
                }

                // Sort records descending by date
                usort($mappedRecords, function ($a, $b) {
                    return strcmp($b['date'], $a['date']);
                });

                $result[] = [
                    'id' => $character->getId(),
                    'name' => $character->getName(),
                    'records' => $mappedRecords,
                    'error' => null,
                ];

            } catch (\Exception $e) {
                $result[] = [
                    'id' => $character->getId(),
                    'name' => $character->getName(),
                    'records' => [],
                    'error' => 'ESI-Fehler beim Abrufen der Bergbaudaten: ' . $e->getMessage(),
                ];
            }
        }

        return new JsonResponse([
            'characters' => $result
        ]);
    }
}
