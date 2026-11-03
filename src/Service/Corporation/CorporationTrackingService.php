<?php

namespace App\Service\Corporation;

use App\Entity\EveCorporationStarbase;
use App\Entity\EveCorporationStructure;
use App\Entity\TrackedCorporation;
use App\Repository\TrackedCorporationRepository;
use App\Service\Esi\EsiClient;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;

/**
 * Decides which corporations get their structures synced. Unknown corporations are registered as pending.
 */
class CorporationTrackingService
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly TrackedCorporationRepository $trackedCorporationRepository,
        private readonly EsiClient $esiClient,
        private readonly LoggerInterface $logger
    ) {}

    /**
     * Registers unknown corporations as pending and returns the active ones.
     *
     * @param int[] $corporationIds
     * @return array<int, TrackedCorporation>
     */
    public function registerAndGetActive(array $corporationIds): array
    {
        $activeCorporations = [];
        $hasNewCorporations = false;

        foreach ($corporationIds as $corporationId) {
            $corporation = $this->trackedCorporationRepository->find((string)$corporationId);
            if ($corporation === null) {
                $this->_createPendingCorporation((string)$corporationId);
                $hasNewCorporations = true;
                continue;
            }

            if ($corporation->isActive()) {
                $activeCorporations[(int)$corporationId] = $corporation;
            }
        }

        if ($hasNewCorporations) {
            $this->entityManager->flush();
        }

        return $activeCorporations;
    }

    public function activate(TrackedCorporation $corporation): void
    {
        $corporation->setStatus(TrackedCorporation::STATUS_ACTIVE);
        $this->entityManager->flush();
    }

    /**
     * Stops the sync and removes the stored structures of the corporation.
     */
    public function disable(TrackedCorporation $corporation): void
    {
        $corporation->setStatus(TrackedCorporation::STATUS_DISABLED);

        foreach ([EveCorporationStructure::class, EveCorporationStarbase::class] as $entityClass) {
            $this->entityManager->createQueryBuilder()
                ->delete($entityClass, 'entity')
                ->where('entity.corporationId = :corporationId')
                ->setParameter('corporationId', $corporation->getId())
                ->getQuery()
                ->execute();
        }

        $this->entityManager->flush();
    }

    private function _createPendingCorporation(string $corporationId): void
    {
        $corporation = new TrackedCorporation($corporationId);

        try {
            $corporationData = $this->esiClient->request('GET', sprintf('corporations/%s/', $corporationId));
            $corporation->setName($corporationData['name'] ?? null);
            $corporation->setTicker($corporationData['ticker'] ?? null);
        } catch (\Exception $e) {
            $this->logger->warning(sprintf('[Corporation] Could not resolve name of corporation %s: %s', $corporationId, $e->getMessage()));
        }

        $this->entityManager->persist($corporation);
        $this->logger->info(sprintf('[Corporation] New corporation %s registered, waiting for approval.', $corporation->getDisplayName()));
    }
}
