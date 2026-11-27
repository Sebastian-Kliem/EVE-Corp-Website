<?php

namespace App\Controller\General;

use App\Entity\Fitting\FleetComposition;
use App\Entity\Fitting\FleetCompositionEntry;
use App\Entity\User;
use App\Repository\FittingRepository;
use App\Repository\FleetCompositionRepository;
use App\Service\Fitting\DefenseDoctrineFleet;
use App\Service\Fitting\FittingViewBuilder;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/general/fleets')]
#[IsGranted('ROLE_MEMBER')]
class FleetController extends AbstractController
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly FleetCompositionRepository $fleetRepository,
        private readonly FittingRepository $fittingRepository,
        private readonly FittingViewBuilder $viewBuilder,
        private readonly DefenseDoctrineFleet $doctrineFleet,
    ) {}

    #[Route('', name: 'app_general_fleets', methods: ['GET'])]
    public function index(): Response
    {
        $user = $this->_currentUser();
        $this->doctrineFleet->migrateLegacyFits();

        return $this->render('general/fleets.html.twig', [
            'library' => $this->viewBuilder->buildLibrary($user),
            'fleets' => $this->viewBuilder->serializeFleets($this->fleetRepository->findAllWithEntries(), $user),
        ]);
    }

    #[Route('/api', name: 'app_general_fleets_create', methods: ['POST'])]
    public function create(Request $request): JsonResponse
    {
        $fleet = new FleetComposition();
        $fleet->setCreatedBy($this->_currentUser());

        $error = $this->_applyRequest($fleet, $request);
        if ($error !== null) {
            return $error;
        }

        $this->entityManager->persist($fleet);
        $this->entityManager->flush();

        return $this->_fleetResponse($fleet);
    }

    #[Route('/api/{id}', name: 'app_general_fleets_update', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function update(int $id, Request $request): JsonResponse
    {
        $fleet = $this->_findEditableFleet($id);

        $error = $this->_applyRequest($fleet, $request);
        if ($error !== null) {
            return $error;
        }

        $this->entityManager->flush();

        return $this->_fleetResponse($fleet);
    }

    #[Route('/api/{id}', name: 'app_general_fleets_delete', requirements: ['id' => '\d+'], methods: ['DELETE'])]
    public function delete(int $id): JsonResponse
    {
        $fleet = $this->_findEditableFleet($id);
        if ($this->doctrineFleet->isDoctrineFleet($fleet)) {
            return new JsonResponse(['error' => 'Diese Flotte ist als Verteidigungsdoktrin hinterlegt und kann nicht gelöscht werden.'], Response::HTTP_BAD_REQUEST);
        }

        $this->entityManager->remove($fleet);
        $this->entityManager->flush();

        return new JsonResponse(['success' => true]);
    }

    /**
     * Applies name, description and entries; returns an error response on invalid input.
     */
    private function _applyRequest(FleetComposition $fleet, Request $request): ?JsonResponse
    {
        $data = json_decode($request->getContent(), true);
        if (!is_array($data)) {
            $data = [];
        }

        $name = trim((string) ($data['name'] ?? ''));
        if ($name === '') {
            return new JsonResponse(['error' => 'Der Flottenname darf nicht leer sein.'], Response::HTTP_BAD_REQUEST);
        }

        $entries = [];
        $sortOrder = 0;
        foreach (is_array($data['entries'] ?? null) ? $data['entries'] : [] as $entryData) {
            $fitting = $this->fittingRepository->find((int) ($entryData['fittingId'] ?? 0));
            if ($fitting === null) {
                return new JsonResponse(['error' => 'Ein ausgewähltes Fit existiert nicht mehr.'], Response::HTTP_BAD_REQUEST);
            }

            $role = trim((string) ($entryData['role'] ?? ''));
            $notes = trim((string) ($entryData['notes'] ?? ''));
            $entry = new FleetCompositionEntry($fitting);
            $entry->setRole($role !== '' ? $role : null);
            $entry->setQuantity((int) ($entryData['quantity'] ?? 1));
            $entry->setNotes($notes !== '' ? $notes : null);
            $entry->setSortOrder($sortOrder++);
            $entries[] = $entry;
        }

        $description = trim((string) ($data['description'] ?? ''));
        $fleet->setName($name);
        $fleet->setDescription($description !== '' ? $description : null);
        $fleet->clearEntries();
        foreach ($entries as $entry) {
            $fleet->addEntry($entry);
        }

        return null;
    }

    private function _fleetResponse(FleetComposition $fleet): JsonResponse
    {
        return new JsonResponse(['fleet' => $this->viewBuilder->serializeFleets([$fleet], $this->_currentUser())[0]]);
    }

    private function _findEditableFleet(int $id): FleetComposition
    {
        $fleet = $this->fleetRepository->find($id);
        if ($fleet === null) {
            throw $this->createNotFoundException('Flotte nicht gefunden.');
        }
        if (!$this->viewBuilder->canEdit($fleet->getCreatedBy(), $this->_currentUser())) {
            throw $this->createAccessDeniedException('Nur Ersteller oder Officer dürfen diese Flotte ändern.');
        }

        return $fleet;
    }

    private function _currentUser(): User
    {
        $user = $this->getUser();
        if (!$user instanceof User) {
            throw $this->createAccessDeniedException();
        }

        return $user;
    }
}
