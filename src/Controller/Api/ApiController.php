<?php

namespace App\Controller\Api;

use App\Entity\EveStructure;
use App\Repository\UserRepository;
use App\Service\JwtService;
use App\Service\SdeService;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/api')]
class ApiController extends AbstractController
{
    public function __construct(
        private UserRepository $userRepository,
        private UserPasswordHasherInterface $passwordHasher,
        private JwtService $jwtService
    ) {}

    #[Route('/login', name: 'api_login', methods: ['POST'])]
    public function login(Request $request): JsonResponse
    {
        $data = json_decode($request->getContent(), true);

        if (!isset($data['username']) || !isset($data['password'])) {
            return new JsonResponse(['message' => 'Missing username or password'], Response::HTTP_BAD_REQUEST);
        }

        $user = $this->userRepository->findOneBy(['username' => $data['username']]);

        if (!$user || !$this->passwordHasher->isPasswordValid($user, $data['password'])) {
            return new JsonResponse(['message' => 'Invalid credentials'], Response::HTTP_UNAUTHORIZED);
        }

        $token = $this->jwtService->createToken($user);

        return new JsonResponse([
            'token' => $token,
            'message' => 'Successfully authenticated'
        ]);
    }

    #[Route('/me', name: 'api_me', methods: ['GET'])]
    #[IsGranted('ROLE_USER')]
    public function me(): JsonResponse
    {
        $user = $this->getUser();

        if (!$user) {
            return new JsonResponse(['message' => 'Not authenticated'], Response::HTTP_UNAUTHORIZED);
        }

        return new JsonResponse([
            'username' => $user->getUserIdentifier(),
            'displayName' => $user->getDisplayName(),
            'roles' => $user->getRoles()
        ]);
    }

    #[Route('/sde/items', name: 'api_sde_items', methods: ['GET'])]
    #[IsGranted('ROLE_USER')]
    public function searchSdeItems(Request $request, SdeService $sdeService): JsonResponse
    {
        $query = $request->query->get('q', '');

        if (strlen($query) < 2) {
            return new JsonResponse([]);
        }

        $items = $sdeService->searchItems($query);

        return new JsonResponse($items);
    }

    #[Route('/structures/{id}', name: 'api_update_structure', methods: ['POST'])]
    #[IsGranted('ROLE_USER')]
    public function updateStructure(
        string $id,
        Request $request,
        EntityManagerInterface $entityManager,
        ManagerRegistry $doctrine
    ): JsonResponse {
        $data = json_decode($request->getContent(), true);

        if (empty($data['name'])) {
            return new JsonResponse(['message' => 'Name darf nicht leer sein.'], Response::HTTP_BAD_REQUEST);
        }

        $structure = $entityManager->getRepository(EveStructure::class)->find($id);
        if (!$structure) {
            $structure = new EveStructure();
            $structure->setId($id);
        }

        $structure->setName($data['name']);

        $solarSystemName = trim($data['solarSystemName'] ?? '');
        $solarSystemId = 0;

        if (!empty($solarSystemName)) {
            try {
                $sdeConnection = $doctrine->getConnection('sde');
                $sdeId = $sdeConnection->fetchOne(
                    'SELECT solarSystemID FROM mapSolarSystems WHERE LOWER(solarSystemName) = LOWER(:name) LIMIT 1',
                    ['name' => $solarSystemName]
                );
                if ($sdeId) {
                    $solarSystemId = (int)$sdeId;
                    // Get correctly-cased name
                    $solarSystemName = $sdeConnection->fetchOne(
                        'SELECT solarSystemName FROM mapSolarSystems WHERE solarSystemID = :id LIMIT 1',
                        ['id' => $solarSystemId]
                    ) ?: $solarSystemName;
                } else {
                    return new JsonResponse(['message' => 'Sonnensystem nicht gefunden.'], Response::HTTP_BAD_REQUEST);
                }
            } catch (\Exception $e) {
                return new JsonResponse(['message' => 'Fehler beim Abfragen der SDE-Datenbank: ' . $e->getMessage()], Response::HTTP_INTERNAL_SERVER_ERROR);
            }
        } else {
            $solarSystemName = 'Unbekannt';
        }

        $structure->setSolarSystemId($solarSystemId);
        $structure->setSolarSystemName($solarSystemName);
        $structure->setLastUpdated(new \DateTimeImmutable());

        $entityManager->persist($structure);
        $entityManager->flush();

        return new JsonResponse([
            'id' => $structure->getId(),
            'name' => $structure->getName(),
            'solarSystemName' => $structure->getSolarSystemName(),
            'message' => 'Struktur erfolgreich aktualisiert.'
        ]);
    }
}
