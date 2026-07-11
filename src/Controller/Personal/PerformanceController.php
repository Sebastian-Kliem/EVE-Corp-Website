<?php

namespace App\Controller\Personal;

use App\Entity\User;
use App\Entity\EveAccount;
use App\Entity\EveCharacter;
use App\Service\PerformanceEngine;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use App\Entity\PerformanceManualEntry;
use App\Entity\PerformanceExclusion;
use Symfony\Component\HttpFoundation\Request;

#[Route('/personal/performance')]
#[IsGranted('ROLE_MEMBER')]
class PerformanceController extends AbstractController
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
                    'tags' => $char->getTags(),
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
                    'tags' => $char->getTags(),
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

        $omegaAccountCount = $this->entityManager->getRepository(EveAccount::class)->count([
            'user' => $currentUser,
            'isOmega' => true,
        ]);

        return $this->render('profile/profile_performance/performance_ledger.html.twig', [
            'charactersList' => $charactersList,
            'omegaAccountCount' => $omegaAccountCount,
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
            $ledger = $this->performanceEngine->calculateDailyPerformance($currentUser);
            $exclusions = $this->entityManager->getRepository(PerformanceExclusion::class)->findBy(['user' => $currentUser]);
            
            $exclusionsData = [];
            foreach ($exclusions as $ex) {
                $exclusionsData[] = [
                    'id' => $ex->getId(),
                    'date' => $ex->getDate()->format('Y-m-d'),
                    'category' => $ex->getCategory(),
                    'typeName' => $ex->getTypeName(),
                    'characterName' => $ex->getCharacterName(),
                    'amount' => (float)$ex->getAmount(),
                ];
            }

            return new JsonResponse([
                'ledger' => $ledger,
                'exclusions' => $exclusionsData,
            ]);
        } catch (\Exception $e) {
            return new JsonResponse([
                'error' => 'Failed to calculate performance data: ' . $e->getMessage()
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    #[Route('/manual', name: 'app_dashboard_performance_add_manual', methods: ['POST'])]
    public function addManualEntry(Request $request): JsonResponse
    {
        $currentUser = $this->getUser();
        if (!$currentUser instanceof User) {
            return new JsonResponse(['error' => 'Unauthorized'], Response::HTTP_UNAUTHORIZED);
        }

        $data = json_decode($request->getContent(), true);

        $dateStr = $data['date'] ?? null;
        $category = $data['category'] ?? 'other';
        $description = trim($data['description'] ?? '');
        $amount = (float)($data['amount'] ?? 0);
        $characterId = isset($data['characterId']) ? (int)$data['characterId'] : null;

        if (!$dateStr) {
            return new JsonResponse(['error' => 'Datum fehlt.'], Response::HTTP_BAD_REQUEST);
        }

        try {
            $date = new \DateTimeImmutable($dateStr);
        } catch (\Exception $e) {
            return new JsonResponse(['error' => 'Ungültiges Datum.'], Response::HTTP_BAD_REQUEST);
        }

        if (empty($description)) {
            return new JsonResponse(['error' => 'Beschreibung fehlt.'], Response::HTTP_BAD_REQUEST);
        }

        if ($amount === 0.0) {
            return new JsonResponse(['error' => 'Betrag darf nicht 0 sein.'], Response::HTTP_BAD_REQUEST);
        }

        $character = null;
        if ($characterId) {
            $character = $this->entityManager->getRepository(EveCharacter::class)->findOneBy([
                'id' => $characterId,
                'user' => $currentUser
            ]);
            if (!$character) {
                return new JsonResponse(['error' => 'Charakter nicht gefunden oder nicht zugeordnet.'], Response::HTTP_BAD_REQUEST);
            }
        }

        $entry = new PerformanceManualEntry();
        $entry->setUser($currentUser);
        $entry->setCharacter($character);
        $entry->setDate($date);
        $entry->setCategory($category);
        $entry->setDescription($description);
        $entry->setAmount((string)$amount);

        $this->entityManager->persist($entry);
        $this->entityManager->flush();

        return new JsonResponse(['success' => true]);
    }

    #[Route('/manual/{id}', name: 'app_dashboard_performance_delete_manual', methods: ['DELETE'])]
    public function deleteManualEntry(int $id): JsonResponse
    {
        $currentUser = $this->getUser();
        if (!$currentUser instanceof User) {
            return new JsonResponse(['error' => 'Unauthorized'], Response::HTTP_UNAUTHORIZED);
        }

        $entry = $this->entityManager->getRepository(PerformanceManualEntry::class)->findOneBy([
            'id' => $id,
            'user' => $currentUser
        ]);

        if (!$entry) {
            return new JsonResponse(['error' => 'Eintrag nicht gefunden.'], Response::HTTP_NOT_FOUND);
        }

        $this->entityManager->remove($entry);
        $this->entityManager->flush();

        return new JsonResponse(['success' => true]);
    }

    #[Route('/exclude', name: 'app_dashboard_performance_exclude_entry', methods: ['POST'])]
    public function excludeEntry(Request $request): JsonResponse
    {
        $currentUser = $this->getUser();
        if (!$currentUser instanceof User) {
            return new JsonResponse(['error' => 'Unauthorized'], Response::HTTP_UNAUTHORIZED);
        }

        $data = json_decode($request->getContent(), true);
        $dateStr = $data['date'] ?? null;
        $category = $data['category'] ?? null;
        $typeName = $data['typeName'] ?? null;
        $characterName = $data['characterName'] ?? null;
        $amount = (float)($data['amount'] ?? 0.0);

        if (!$dateStr || !$category || !$typeName || !$characterName) {
            return new JsonResponse(['error' => 'Fehlende Parameter.'], Response::HTTP_BAD_REQUEST);
        }

        try {
            $date = new \DateTimeImmutable($dateStr);
        } catch (\Exception $e) {
            return new JsonResponse(['error' => 'Ungültiges Datum.'], Response::HTTP_BAD_REQUEST);
        }

        // Check if already excluded
        $existing = $this->entityManager->getRepository(PerformanceExclusion::class)->findOneBy([
            'user' => $currentUser,
            'date' => $date,
            'category' => $category,
            'typeName' => $typeName,
            'characterName' => $characterName,
        ]);

        if (!$existing) {
            $exclusion = new PerformanceExclusion();
            $exclusion->setUser($currentUser);
            $exclusion->setDate($date);
            $exclusion->setCategory($category);
            $exclusion->setTypeName($typeName);
            $exclusion->setCharacterName($characterName);
            $exclusion->setAmount((string)$amount);

            $this->entityManager->persist($exclusion);
            $this->entityManager->flush();
        }

        return new JsonResponse(['success' => true]);
    }

    #[Route('/exclude/{id}', name: 'app_dashboard_performance_remove_exclusion', methods: ['DELETE'])]
    public function removeExclusion(int $id): JsonResponse
    {
        $currentUser = $this->getUser();
        if (!$currentUser instanceof User) {
            return new JsonResponse(['error' => 'Unauthorized'], Response::HTTP_UNAUTHORIZED);
        }

        $exclusion = $this->entityManager->getRepository(PerformanceExclusion::class)->findOneBy([
            'id' => $id,
            'user' => $currentUser
        ]);

        if (!$exclusion) {
            return new JsonResponse(['error' => 'Ausschluss nicht gefunden.'], Response::HTTP_NOT_FOUND);
        }

        $this->entityManager->remove($exclusion);
        $this->entityManager->flush();

        return new JsonResponse(['success' => true]);
    }
}
