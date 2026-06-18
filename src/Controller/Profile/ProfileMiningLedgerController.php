<?php

namespace App\Controller\Profile;

use App\Entity\User;
use App\Entity\EveCharacter;
use App\Entity\EveCharacterMiningRecord;
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
                $dbRecords = $this->entityManager->getRepository(EveCharacterMiningRecord::class)->findBy(
                    ['character' => $character],
                    ['date' => 'DESC']
                );

                // Map raw records to UI-friendly records
                $mappedRecords = [];
                foreach ($dbRecords as $record) {
                    $typeId = $record->getTypeId();
                    $solarSystemId = $record->getSolarSystemId();
                    
                    $typeName = $this->sdeService->getItemName($typeId);
                    $solarSystemName = $this->sdeService->getLocationName($solarSystemId);
                    $price = $prices[$typeId] ?? 0.0;
                    $quantity = (int)$record->getQuantity();

                    $mappedRecords[] = [
                        'date' => $record->getDate()->format('Y-m-d'),
                        'solarSystemId' => $solarSystemId,
                        'solarSystemName' => $solarSystemName,
                        'typeId' => $typeId,
                        'typeName' => $typeName,
                        'quantity' => $quantity,
                        'price' => $price,
                        'value' => $quantity * $price,
                    ];
                }

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
                    'error' => 'Fehler beim Abrufen der Bergbaudaten: ' . $e->getMessage(),
                ];
            }
        }

        return new JsonResponse([
            'characters' => $result
        ]);
    }
}
