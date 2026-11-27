<?php

namespace App\Service\Fitting;

use App\Entity\AppSetting;
use App\Entity\DefenseDoctrineFit;
use App\Entity\Fitting\FleetComposition;
use App\Entity\Fitting\FleetCompositionEntry;
use App\Repository\DefenseDoctrineFitRepository;
use App\Repository\FleetCompositionRepository;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;

/**
 * Fleet composition used as the corp defense doctrine, stored as app setting.
 */
class DefenseDoctrineFleet
{
    private const SETTING_KEY_FLEET = 'corp_defense_doctrine_fleet_id';
    private const MIGRATED_FLEET_NAME = 'Verteidigungsdoktrin';

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly FleetCompositionRepository $fleetRepository,
        private readonly DefenseDoctrineFitRepository $legacyFitRepository,
        private readonly FittingLibrary $library,
        private readonly LoggerInterface $logger,
    ) {}

    public function getFleet(): ?FleetComposition
    {
        $fleetId = (int) $this->_findSetting()?->getValue();

        return $fleetId > 0 ? $this->fleetRepository->find($fleetId) : null;
    }

    public function setFleet(?FleetComposition $fleet): void
    {
        $value = $fleet !== null ? (string) $fleet->getId() : '';
        $setting = $this->_findSetting();
        if ($setting === null) {
            $this->entityManager->persist(new AppSetting(self::SETTING_KEY_FLEET, $value));
        } else {
            $setting->setValue($value);
        }
        $this->entityManager->flush();
    }

    public function isDoctrineFleet(FleetComposition $fleet): bool
    {
        return $fleet->getId() !== null && (int) $this->_findSetting()?->getValue() === $fleet->getId();
    }

    /**
     * One-time migration: legacy doctrine fits become a fleet whose fits live in the library.
     */
    public function migrateLegacyFits(): void
    {
        if ($this->_findSetting() !== null) {
            return;
        }

        $legacyFits = $this->legacyFitRepository->findAllOrdered();
        if (empty($legacyFits)) {
            return;
        }

        $fleet = new FleetComposition();
        $fleet->setName(self::MIGRATED_FLEET_NAME);
        $sortOrder = 0;
        foreach ($legacyFits as $legacyFit) {
            $entry = $this->_createEntry($legacyFit);
            if ($entry === null) {
                continue;
            }
            $entry->setSortOrder($sortOrder++);
            $fleet->addEntry($entry);
            $this->entityManager->remove($legacyFit);
        }

        $this->entityManager->persist($fleet);
        $this->entityManager->flush();
        $this->setFleet($fleet);
    }

    private function _createEntry(DefenseDoctrineFit $legacyFit): ?FleetCompositionEntry
    {
        try {
            $result = $this->library->createFromEft((string) $legacyFit->getEft(), $legacyFit->getCreatedBy(), $legacyFit->getTitle());
        } catch (FittingBuildException $e) {
            // Unparseable fits stay in the legacy table
            $this->logger->warning(sprintf('Defense doctrine fit %d could not be migrated: %s', $legacyFit->getId(), $e->getMessage()));
            return null;
        }

        $entry = new FleetCompositionEntry($result['fitting']);
        $entry->setRole($legacyFit->getRole());
        $entry->setNotes($legacyFit->getNotes());

        return $entry;
    }

    private function _findSetting(): ?AppSetting
    {
        return $this->entityManager->getRepository(AppSetting::class)->find(self::SETTING_KEY_FLEET);
    }
}
