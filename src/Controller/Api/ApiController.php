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

    #[Route('/sde/parse-items', name: 'api_sde_parse_items', methods: ['POST'])]
    #[IsGranted('ROLE_USER')]
    public function parseItems(Request $request, SdeService $sdeService): JsonResponse
    {
        $data = json_decode($request->getContent(), true);
        $text = $data['text'] ?? '';

        if (empty(trim($text))) {
            return new JsonResponse([
                'items' => [],
                'unresolved' => []
            ]);
        }

        $lines = preg_split('/\r\n|\r|\n/', $text);
        $parsedLines = [];
        $namesToLookup = [];

        foreach ($lines as $line) {
            $line = trim($line);
            if (empty($line)) {
                continue;
            }

            // 1. Check for tab separation (standard EVE hangar copy)
            if (strpos($line, "\t") !== false) {
                $parts = explode("\t", $line);
                $name = trim(rtrim(trim($parts[0]), '*'));
                $qtyStr = isset($parts[1]) ? trim($parts[1]) : '';
                
                $quantity = 1;
                if (!empty($qtyStr)) {
                    $qtyStrClean = preg_replace('/[^\d]/', '', $qtyStr);
                    if (is_numeric($qtyStrClean)) {
                        $quantity = (int)$qtyStrClean;
                    }
                }
                
                if (!empty($name)) {
                    $parsedLines[] = [
                        'raw' => $line,
                        'name' => $name,
                        'quantity' => $quantity,
                    ];
                    $namesToLookup[] = $name;
                }
                continue;
            }

            // 2. Regex checks for quantities (e.g., "123 x Tritanium", "Tritanium x123", "5 Veldspar")
            $name = $line;
            $quantity = 1;

            if (preg_match('/^(\d+)\s*x\s+(.+)$/i', $line, $matches)) {
                $quantity = (int)$matches[1];
                $name = trim(rtrim(trim($matches[2]), '*'));
            } elseif (preg_match('/^(.+?)\s*x\s*(\d+)$/i', $line, $matches)) {
                $name = trim(rtrim(trim($matches[1]), '*'));
                $quantity = (int)$matches[2];
            } else {
                $name = trim(rtrim($name, '*'));
            }

            if (!empty($name)) {
                $parsedLines[] = [
                    'raw' => $line,
                    'name' => $name,
                    'quantity' => $quantity,
                ];
                $namesToLookup[] = $name;
            }
        }

        if (empty($namesToLookup)) {
            return new JsonResponse([
                'items' => [],
                'unresolved' => []
            ]);
        }

        $resolvedMap = $sdeService->resolveItemNames($namesToLookup);
        $items = [];
        $unresolved = [];

        foreach ($parsedLines as $parsedLine) {
            $nameLower = strtolower($parsedLine['name']);
            if (isset($resolvedMap[$nameLower])) {
                $resolved = $resolvedMap[$nameLower];
                $typeId = $resolved['id'];
                if (isset($items[$typeId])) {
                    $items[$typeId]['quantity'] += $parsedLine['quantity'];
                } else {
                    $items[$typeId] = [
                        'typeId' => $typeId,
                        'name' => $resolved['name'],
                        'quantity' => $parsedLine['quantity'],
                        'variation' => $resolved['variation'],
                    ];
                }
            } else {
                // Try to strip leading number if not handled by regex: e.g. "5 Veldspar" -> "Veldspar"
                $name = $parsedLine['name'];
                $matched = false;

                if (preg_match('/^(\d+)\s+(.+)$/', $name, $matches)) {
                    $strippedQty = (int)$matches[1];
                    $strippedName = trim($matches[2]);
                    $strippedNameLower = strtolower($strippedName);

                    $resolvedStripped = $sdeService->resolveItemNames([$strippedName]);
                    if (isset($resolvedStripped[$strippedNameLower])) {
                        $resolved = $resolvedStripped[$strippedNameLower];
                        $typeId = $resolved['id'];
                        if (isset($items[$typeId])) {
                            $items[$typeId]['quantity'] += $strippedQty;
                        } else {
                            $items[$typeId] = [
                                'typeId' => $typeId,
                                'name' => $resolved['name'],
                                'quantity' => $strippedQty,
                                'variation' => $resolved['variation'],
                            ];
                        }
                        $matched = true;
                    }
                }

                if (!$matched) {
                    $unresolved[] = $parsedLine['raw'];
                }
            }
        }

        return new JsonResponse([
            'items' => array_values($items),
            'unresolved' => $unresolved
        ]);
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
