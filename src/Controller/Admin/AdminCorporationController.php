<?php

namespace App\Controller\Admin;

use App\Entity\EveCorporationStarbase;
use App\Entity\EveCorporationStructure;
use App\Entity\TrackedCorporation;
use App\Repository\TrackedCorporationRepository;
use App\Service\Corporation\CorporationTrackingService;
use App\Service\Discord\DiscordWebhookService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/admin/corporations')]
#[IsGranted('ROLE_CEO')]
class AdminCorporationController extends AbstractController
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly TrackedCorporationRepository $trackedCorporationRepository,
        private readonly CorporationTrackingService $corporationTrackingService
    ) {}

    #[Route('', name: 'app_admin_corporations_index', methods: ['GET'])]
    public function index(): Response
    {
        $corporationsByStatus = [
            TrackedCorporation::STATUS_PENDING => [],
            TrackedCorporation::STATUS_ACTIVE => [],
            TrackedCorporation::STATUS_DISABLED => [],
        ];
        foreach ($this->trackedCorporationRepository->findBy([], ['name' => 'ASC']) as $corporation) {
            $corporationsByStatus[$corporation->getStatus()][] = $corporation;
        }

        $structuresByCorporation = [];
        $webhookHints = [];
        foreach ($corporationsByStatus[TrackedCorporation::STATUS_ACTIVE] as $corporation) {
            $structuresByCorporation[$corporation->getId()] = $this->_findStructures($corporation);
            $webhookHints[$corporation->getId()] = $corporation->getWebhookUrl() !== null ? DiscordWebhookService::maskSecret($corporation->getWebhookUrl()) : null;
        }

        return $this->render('admin/admin_corporation/index.html.twig', [
            'pendingCorporations' => $corporationsByStatus[TrackedCorporation::STATUS_PENDING],
            'activeCorporations' => $corporationsByStatus[TrackedCorporation::STATUS_ACTIVE],
            'disabledCorporations' => $corporationsByStatus[TrackedCorporation::STATUS_DISABLED],
            'structuresByCorporation' => $structuresByCorporation,
            'webhookHints' => $webhookHints,
        ]);
    }

    #[Route('/{id}/activate', name: 'app_admin_corporations_activate', methods: ['POST'])]
    public function activate(TrackedCorporation $corporation, Request $request): Response
    {
        if (!$this->isCsrfTokenValid('corporation_status_' . $corporation->getId(), $request->request->get('_token'))) {
            $this->addFlash('error', 'Ungültiges CSRF-Token.');
            return $this->redirectToRoute('app_admin_corporations_index');
        }

        $this->corporationTrackingService->activate($corporation);

        $this->addFlash('success', sprintf('%s ist aktiviert. Die Strukturen werden beim nächsten Sync abgeholt.', $corporation->getDisplayName()));
        return $this->redirectToRoute('app_admin_corporations_index');
    }

    #[Route('/{id}/disable', name: 'app_admin_corporations_disable', methods: ['POST'])]
    public function disable(TrackedCorporation $corporation, Request $request): Response
    {
        if (!$this->isCsrfTokenValid('corporation_status_' . $corporation->getId(), $request->request->get('_token'))) {
            $this->addFlash('error', 'Ungültiges CSRF-Token.');
            return $this->redirectToRoute('app_admin_corporations_index');
        }

        $this->corporationTrackingService->disable($corporation);

        $this->addFlash('success', sprintf('%s ist deaktiviert, gespeicherte Strukturdaten wurden entfernt.', $corporation->getDisplayName()));
        return $this->redirectToRoute('app_admin_corporations_index');
    }

    #[Route('/{id}/save', name: 'app_admin_corporations_save', methods: ['POST'])]
    public function save(TrackedCorporation $corporation, Request $request): Response
    {
        if (!$corporation->isActive() || !$this->isCsrfTokenValid('corporation_save_' . $corporation->getId(), $request->request->get('_token'))) {
            $this->addFlash('error', 'Ungültiges CSRF-Token.');
            return $this->redirectToRoute('app_admin_corporations_index');
        }

        $corporation->setUseGlobalChannels($request->request->getBoolean('use_global_channels'));
        $corporation->setPingRole(trim((string)$request->request->get('ping_role')) ?: null);

        // The webhook is a secret: an empty field keeps the stored value
        $webhookUrl = trim((string)$request->request->get('webhook_url'));
        if ($request->request->getBoolean('remove_webhook_url')) {
            $corporation->setWebhookUrl(null);
        } elseif ($webhookUrl !== '') {
            $corporation->setWebhookUrl($webhookUrl);
        }

        $enabledStructureIds = $request->request->all('alert_structure_ids');
        foreach ($this->_findStructures($corporation) as $structure) {
            $structure->setAlertsEnabled(in_array((string)$structure->getId(), $enabledStructureIds, true));
        }

        $this->entityManager->flush();

        $this->addFlash('success', sprintf('Einstellungen für %s gespeichert.', $corporation->getDisplayName()));
        return $this->redirectToRoute('app_admin_corporations_index');
    }

    /**
     * @return array<int, EveCorporationStructure|EveCorporationStarbase>
     */
    private function _findStructures(TrackedCorporation $corporation): array
    {
        $structures = $this->entityManager->getRepository(EveCorporationStructure::class)->findBy(
            ['corporationId' => $corporation->getId()],
            ['solarSystemName' => 'ASC', 'name' => 'ASC']
        );
        $starbases = $this->entityManager->getRepository(EveCorporationStarbase::class)->findBy(
            ['corporationId' => $corporation->getId()],
            ['solarSystemName' => 'ASC']
        );

        return array_merge($structures, $starbases);
    }
}
