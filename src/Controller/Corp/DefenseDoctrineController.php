<?php

namespace App\Controller\Corp;

use App\Entity\AppSetting;
use App\Entity\Fitting\FleetComposition;
use App\Repository\FleetCompositionRepository;
use App\Service\Fitting\DefenseDoctrineFleet;
use App\Service\Fitting\EftFormatter;
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
        private readonly FleetCompositionRepository $fleetRepository,
        private readonly DefenseDoctrineFleet $doctrineFleet,
        private readonly SdeService $sdeService,
        private readonly EftFormatter $eftFormatter
    ) {}

    #[Route('', name: 'app_corp_defense_doctrine', methods: ['GET'])]
    public function index(): Response
    {
        $settingRepo = $this->entityManager->getRepository(AppSetting::class);
        $notesSetting = $settingRepo->find(self::SETTING_KEY_NOTES);

        $this->doctrineFleet->migrateLegacyFits();

        $fleetOptions = [];
        foreach ($this->fleetRepository->findBy([], ['name' => 'ASC']) as $fleet) {
            $fleetOptions[] = ['id' => $fleet->getId(), 'name' => $fleet->getName()];
        }

        return $this->render('corp/defense_doctrine.html.twig', [
            'notes' => $notesSetting ? ($notesSetting->getValue() ?? '') : '',
            'notesUpdatedAt' => $notesSetting ? $notesSetting->getUpdatedAt()->format('d.m.Y H:i') : null,
            'fleet' => $this->_serializeFleet($this->doctrineFleet->getFleet()),
            'fleetOptions' => $fleetOptions,
            'canManage' => $this->isGranted('ROLE_OFFICER'),
        ]);
    }

    #[Route('/api/notes', name: 'app_corp_defense_doctrine_save_notes', methods: ['POST'])]
    #[IsGranted('ROLE_OFFICER')]
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

    #[Route('/api/fleet', name: 'app_corp_defense_doctrine_save_fleet', methods: ['POST'])]
    #[IsGranted('ROLE_OFFICER')]
    public function saveFleet(Request $request): JsonResponse
    {
        $data = json_decode($request->getContent(), true);
        $fleetId = (int) ($data['fleetId'] ?? 0);

        $fleet = $fleetId > 0 ? $this->fleetRepository->find($fleetId) : null;
        if ($fleetId > 0 && $fleet === null) {
            return new JsonResponse(['error' => 'Flotte nicht gefunden.'], Response::HTTP_NOT_FOUND);
        }

        $this->doctrineFleet->setFleet($fleet);

        return new JsonResponse(['success' => true, 'fleet' => $this->_serializeFleet($fleet)]);
    }

    /**
     * Doctrine view of the fleet: one card per entry with EFT for copy & paste.
     */
    private function _serializeFleet(?FleetComposition $fleet): ?array
    {
        if ($fleet === null) {
            return null;
        }

        $fits = [];
        foreach ($fleet->getEntries() as $entry) {
            $fitting = $entry->getFitting();
            $fits[] = [
                'id' => $entry->getId(),
                'title' => $fitting->getName(),
                'shipName' => $this->sdeService->getItemName($fitting->getShipTypeId()),
                'shipTypeId' => $fitting->getShipTypeId(),
                'role' => $entry->getRole() ?: 'Allgemein',
                'quantity' => $entry->getQuantity(),
                'eft' => $this->eftFormatter->format($fitting),
                'notes' => $entry->getNotes() ?? '',
                'fittingUrl' => $this->generateUrl('app_general_fittings', ['fit' => $fitting->getId()]),
            ];
        }

        return [
            'id' => $fleet->getId(),
            'name' => $fleet->getName(),
            'description' => $fleet->getDescription() ?? '',
            'updatedAt' => $fleet->getUpdatedAt()->format('d.m.Y H:i'),
            'fits' => $fits,
        ];
    }
}
