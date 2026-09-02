<?php

namespace App\Controller\Corp;

use App\Entity\AppSetting;
use App\Entity\DefenseDoctrineFit;
use App\Entity\User;
use App\Repository\DefenseDoctrineFitRepository;
use App\Service\SdeService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/corp/defense-doctrine')]
#[IsGranted('ROLE_MEMBER')]
class DefenseDoctrineController extends AbstractController
{
    private const SETTING_KEY_NOTES = 'corp_defense_doctrine_notes';

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly DefenseDoctrineFitRepository $fitRepository,
        private readonly SdeService $sdeService
    ) {}

    #[Route('', name: 'app_corp_defense_doctrine', methods: ['GET'])]
    public function index(): Response
    {
        $settingRepo = $this->entityManager->getRepository(AppSetting::class);
        $notesSetting = $settingRepo->find(self::SETTING_KEY_NOTES);

        $fits = $this->fitRepository->findAllOrdered();
        $fitsData = array_map(function (DefenseDoctrineFit $fit) {
            return [
                'id' => $fit->getId(),
                'title' => $fit->getTitle(),
                'shipName' => $fit->getShipName(),
                'shipTypeId' => $fit->getShipTypeId(),
                'role' => $fit->getRole() ?: 'Allgemein',
                'eft' => $fit->getEft(),
                'notes' => $fit->getNotes() ?: '',
                'sortOrder' => $fit->getSortOrder(),
                'updatedAt' => $fit->getUpdatedAt()->format('d.m.Y H:i'),
                'createdByName' => $fit->getCreatedBy() ? $fit->getCreatedBy()->getDisplayName() : null,
            ];
        }, $fits);

        return $this->render('corp/defense_doctrine.html.twig', [
            'notes' => $notesSetting ? ($notesSetting->getValue() ?? '') : '',
            'notesUpdatedAt' => $notesSetting ? $notesSetting->getUpdatedAt()->format('d.m.Y H:i') : null,
            'fits' => $fitsData,
        ]);
    }

    #[Route('/api/notes', name: 'app_corp_defense_doctrine_save_notes', methods: ['POST'])]
    public function saveNotes(Request $request): JsonResponse
    {
        $data = json_decode($request->getContent(), true);
        $notes = isset($data['notes']) ? trim((string)$data['notes']) : '';

        $settingRepo = $this->entityManager->getRepository(AppSetting::class);
        $notesSetting = $settingRepo->find(self::SETTING_KEY_NOTES);

        if ($notesSetting === null) {
            $notesSetting = new AppSetting(self::SETTING_KEY_NOTES, $notes);
            $this->entityManager->persist($notesSetting);
        } else {
            $notesSetting->setValue($notes);
        }

        $this->entityManager->flush();

        return new JsonResponse([
            'success' => true,
            'notes' => $notesSetting->getValue() ?? '',
            'updatedAt' => $notesSetting->getUpdatedAt()->format('d.m.Y H:i'),
        ]);
    }

    #[Route('/api/fits', name: 'app_corp_defense_doctrine_create_fit', methods: ['POST'])]
    public function createFit(Request $request): JsonResponse
    {
        $currentUser = $this->getUser();
        if (!$currentUser instanceof User) {
            return new JsonResponse(['error' => 'Nicht autorisiert.'], Response::HTTP_UNAUTHORIZED);
        }

        $data = json_decode($request->getContent(), true);
        $eft = trim((string)($data['eft'] ?? ''));

        if (empty($eft)) {
            return new JsonResponse(['error' => 'Das EFT-Fitting darf nicht leer sein.'], Response::HTTP_BAD_REQUEST);
        }

        $parsed = $this->parseEft($eft);
        
        $shipName = trim((string)($data['shipName'] ?? ''));
        if (empty($shipName) && !empty($parsed['shipName'])) {
            $shipName = $parsed['shipName'];
        }

        $title = trim((string)($data['title'] ?? ''));
        if (empty($title)) {
            $title = !empty($parsed['title']) ? $parsed['title'] : ($shipName ?: 'Verteidigungsfit');
        }

        $role = trim((string)($data['role'] ?? 'DPS'));
        if (empty($role)) {
            $role = 'DPS';
        }

        $notes = trim((string)($data['notes'] ?? ''));

        // Resolve ship type ID via SDE
        $shipTypeId = null;
        if (!empty($shipName)) {
            $resolved = $this->sdeService->resolveItemNames([$shipName]);
            $lowerName = strtolower($shipName);
            if (isset($resolved[$lowerName])) {
                $shipTypeId = $resolved[$lowerName]['id'];
                $shipName = $resolved[$lowerName]['name'];
            }
        }

        $fit = new DefenseDoctrineFit();
        $fit->setTitle($title);
        $fit->setShipName($shipName ?: 'Unbekanntes Schiff');
        $fit->setShipTypeId($shipTypeId);
        $fit->setRole($role);
        $fit->setEft($eft);
        $fit->setNotes($notes ?: null);
        $fit->setCreatedBy($currentUser);
        $fit->setSortOrder((int)($data['sortOrder'] ?? 0));

        $this->entityManager->persist($fit);
        $this->entityManager->flush();

        return new JsonResponse([
            'success' => true,
            'fit' => [
                'id' => $fit->getId(),
                'title' => $fit->getTitle(),
                'shipName' => $fit->getShipName(),
                'shipTypeId' => $fit->getShipTypeId(),
                'role' => $fit->getRole() ?: 'Allgemein',
                'eft' => $fit->getEft(),
                'notes' => $fit->getNotes() ?: '',
                'sortOrder' => $fit->getSortOrder(),
                'updatedAt' => $fit->getUpdatedAt()->format('d.m.Y H:i'),
                'createdByName' => $currentUser->getDisplayName(),
            ],
        ]);
    }

    #[Route('/api/fits/{id}', name: 'app_corp_defense_doctrine_update_fit', methods: ['PUT', 'POST'])]
    public function updateFit(int $id, Request $request): JsonResponse
    {
        $fit = $this->fitRepository->find($id);
        if (!$fit) {
            return new JsonResponse(['error' => 'Fit nicht gefunden.'], Response::HTTP_NOT_FOUND);
        }

        $data = json_decode($request->getContent(), true);
        $eft = trim((string)($data['eft'] ?? ''));

        if (empty($eft)) {
            return new JsonResponse(['error' => 'Das EFT-Fitting darf nicht leer sein.'], Response::HTTP_BAD_REQUEST);
        }

        $parsed = $this->parseEft($eft);

        $shipName = trim((string)($data['shipName'] ?? ''));
        if (empty($shipName) && !empty($parsed['shipName'])) {
            $shipName = $parsed['shipName'];
        }

        $title = trim((string)($data['title'] ?? ''));
        if (empty($title)) {
            $title = !empty($parsed['title']) ? $parsed['title'] : ($shipName ?: 'Verteidigungsfit');
        }

        $role = trim((string)($data['role'] ?? 'DPS'));
        $notes = trim((string)($data['notes'] ?? ''));

        // Resolve ship type ID via SDE
        $shipTypeId = null;
        if (!empty($shipName)) {
            $resolved = $this->sdeService->resolveItemNames([$shipName]);
            $lowerName = strtolower($shipName);
            if (isset($resolved[$lowerName])) {
                $shipTypeId = $resolved[$lowerName]['id'];
                $shipName = $resolved[$lowerName]['name'];
            }
        }

        $fit->setTitle($title);
        $fit->setShipName($shipName ?: 'Unbekanntes Schiff');
        $fit->setShipTypeId($shipTypeId);
        $fit->setRole($role ?: 'DPS');
        $fit->setEft($eft);
        $fit->setNotes($notes ?: null);
        if (isset($data['sortOrder'])) {
            $fit->setSortOrder((int)$data['sortOrder']);
        }

        $this->entityManager->flush();

        return new JsonResponse([
            'success' => true,
            'fit' => [
                'id' => $fit->getId(),
                'title' => $fit->getTitle(),
                'shipName' => $fit->getShipName(),
                'shipTypeId' => $fit->getShipTypeId(),
                'role' => $fit->getRole() ?: 'Allgemein',
                'eft' => $fit->getEft(),
                'notes' => $fit->getNotes() ?: '',
                'sortOrder' => $fit->getSortOrder(),
                'updatedAt' => $fit->getUpdatedAt()->format('d.m.Y H:i'),
                'createdByName' => $fit->getCreatedBy() ? $fit->getCreatedBy()->getDisplayName() : null,
            ],
        ]);
    }

    #[Route('/api/fits/{id}', name: 'app_corp_defense_doctrine_delete_fit', methods: ['DELETE'])]
    public function deleteFit(int $id): JsonResponse
    {
        $fit = $this->fitRepository->find($id);
        if (!$fit) {
            return new JsonResponse(['error' => 'Fit nicht gefunden.'], Response::HTTP_NOT_FOUND);
        }

        $this->entityManager->remove($fit);
        $this->entityManager->flush();

        return new JsonResponse(['success' => true]);
    }

    private function parseEft(string $eft): array
    {
        $lines = preg_split('/\r\n|\r|\n/', trim($eft));
        $firstLine = $lines[0] ?? '';

        $shipName = null;
        $title = null;

        if (preg_match('/^\s*\[\s*([^,\]]+?)\s*,\s*([^\]]+?)\s*\]/', $firstLine, $matches)) {
            $shipName = trim($matches[1]);
            $title = trim($matches[2]);
        } elseif (preg_match('/^\s*\[\s*([^\]]+?)\s*\]/', $firstLine, $matches)) {
            $shipName = trim($matches[1]);
        }

        return [
            'shipName' => $shipName,
            'title' => $title,
        ];
    }
}
