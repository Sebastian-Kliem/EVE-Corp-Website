<?php

namespace App\Service;

use App\Entity\EveCharacter;
use App\Entity\EveStructure;
use App\Service\Esi\EsiClient;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ManagerRegistry;
use Doctrine\DBAL\Connection;

class LocationService
{
    private Connection $sdeConnection;

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly EsiClient $esiClient,
        ManagerRegistry $doctrine
    ) {
        $this->sdeConnection = $doctrine->getConnection('sde');
    }

    /**
     * Resolves a location ID (NPC Station, Solar System, or Upwell Structure) into its names.
     * Returns an array with structure:
     * [
     *     'name' => '[SystemName] StationOrStructureName',
     *     'systemName' => 'SystemName',
     *     'rawName' => 'StationOrStructureName'
     * ]
     */
    public function resolveLocation(int $locationId, ?EveCharacter $character = null): array
    {
        // 1. Check if NPC Station (IDs 60000000 to 64000000)
        if ($locationId >= 60000000 && $locationId < 64000000) {
            try {
                $row = $this->sdeConnection->fetchAssociative(
                    'SELECT st.stationName, s.solarSystemName 
                     FROM staStations st 
                     JOIN mapSolarSystems s ON st.solarSystemID = s.solarSystemID 
                     WHERE st.stationID = :id LIMIT 1',
                    ['id' => $locationId]
                );

                if ($row) {
                    $system = $row['solarSystemName'];
                    $station = $row['stationName'];
                    return [
                        'name' => $station,
                        'systemName' => $system,
                        'rawName' => $station,
                    ];
                }
            } catch (\Exception $e) {
                // Fallback to basic
            }
        }

        // 2. Check if Solar System (IDs 30000000 to 32000000)
        if ($locationId >= 30000000 && $locationId < 32000000) {
            try {
                $system = $this->sdeConnection->fetchOne(
                    'SELECT solarSystemName FROM mapSolarSystems WHERE solarSystemID = :id LIMIT 1',
                    ['id' => $locationId]
                );

                if ($system) {
                    return [
                        'name' => $system,
                        'systemName' => $system,
                        'rawName' => 'Solarsystem',
                    ];
                }
            } catch (\Exception $e) {
                // Fallback
            }
        }

        // 3. Check if Player-Owned Structure (IDs >= 1000000000000)
        if ($locationId >= 1000000000000) {
            return $this->resolvePlayerStructure($locationId, $character);
        }

        // 4. Default Fallback
        return [
            'name' => 'Location #' . $locationId,
            'systemName' => 'Unbekannt',
            'rawName' => 'Location #' . $locationId,
        ];
    }

    /**
     * Resolves player-owned structure name and solar system.
     */
    private function resolvePlayerStructure(int $locationId, ?EveCharacter $character = null): array
    {
        // Check if we can find this location in corporation assets (e.g. Customs Offices)
        $corpAsset = $this->entityManager->getRepository(\App\Entity\EveCorporationAsset::class)->findOneBy(['itemId' => $locationId]);
        if ($corpAsset && $corpAsset->getCustomName()) {
            $structureName = $corpAsset->getCustomName();
            $solarSystemName = 'Unbekannt';
            if (preg_match('/\(([^)]+)\)/', $structureName, $matches)) {
                $parts = explode(' ', trim($matches[1]));
                $solarSystemName = $parts[0];
            }
            return [
                'name' => $structureName,
                'systemName' => $solarSystemName,
                'rawName' => $structureName,
            ];
        }

        $structureRepo = $this->entityManager->getRepository(EveStructure::class);
        $structure = $structureRepo->find((string)$locationId);

        $now = new \DateTimeImmutable();
        $cacheExpiryDays = 7;

        // If cached and still valid, return cached info
        // We only use cached info if it is a fully resolved structure name, not our fallback
        if ($structure && 
            $structure->getName() !== 'Spieler-Struktur' && 
            $structure->getSolarSystemName() !== 'Unbekannt' && 
            $structure->getLastUpdated()->modify('+' . $cacheExpiryDays . ' days') > $now
        ) {
            return [
                'name' => $structure->getName(),
                'systemName' => $structure->getSolarSystemName() ?? 'Unbekannt',
                'rawName' => $structure->getName(),
            ];
        }

        $resolvedData = null;
        $charactersToTry = [];

        // 1. Queue the passed character first
        if ($character && !empty($character->getRefreshToken())) {
            $charactersToTry[] = $character;
        }

        // 2. Queue other characters from the same user account
        if ($character && $character->getUser()) {
            $userChars = $this->entityManager->getRepository(EveCharacter::class)->findBy(['user' => $character->getUser()]);
            foreach ($userChars as $uc) {
                if ($character && $uc->getId() === $character->getId()) {
                    continue;
                }
                if (!empty($uc->getRefreshToken())) {
                    $charactersToTry[] = $uc;
                }
            }
        }

        // 3. Queue all other characters in the database as a final fallback
        $allChars = $this->entityManager->getRepository(EveCharacter::class)->findAll();
        foreach ($allChars as $ac) {
            if ($character && $ac->getId() === $character->getId()) {
                continue;
            }
            // Avoid adding duplicates
            $alreadyQueued = false;
            foreach ($charactersToTry as $q) {
                if ($q->getId() === $ac->getId()) {
                    $alreadyQueued = true;
                    break;
                }
            }
            if (!$alreadyQueued && !empty($ac->getRefreshToken())) {
                $charactersToTry[] = $ac;
            }
        }

        // Try to fetch from ESI using queued characters
        foreach ($charactersToTry as $tryChar) {
            try {
                $data = $this->esiClient->request(
                    'GET',
                    sprintf('universe/structures/%d/', $locationId),
                    [],
                    $tryChar
                );

                if ($data && !empty($data['name'])) {
                    $resolvedData = $data;
                    break;
                }
            } catch (\Exception $e) {
                // Keep trying other characters
            }
        }

        if ($resolvedData && !empty($resolvedData['name'])) {
            $structureName = $resolvedData['name'];
            $solarSystemId = (int)($resolvedData['solar_system_id'] ?? 0);
            $solarSystemName = 'Unbekannt';

            if ($solarSystemId > 0) {
                $solarSystemName = $this->sdeConnection->fetchOne(
                    'SELECT solarSystemName FROM mapSolarSystems WHERE solarSystemID = :id LIMIT 1',
                    ['id' => $solarSystemId]
                ) ?: 'Unbekannt';
            }

            // Save to local cache
            if (!$structure) {
                $structure = new EveStructure();
                $structure->setId((string)$locationId);
            }
            $structure->setName($structureName);
            $structure->setSolarSystemId($solarSystemId);
            $structure->setSolarSystemName($solarSystemName);
            $structure->setLastUpdated($now);

            $this->entityManager->persist($structure);
            $this->entityManager->flush();

            return [
                'name' => $structureName,
                'systemName' => $solarSystemName,
                'rawName' => $structureName,
            ];
        }

        // If fetching fails completely, cache a fallback so we don't spam ESI
        if (!$structure) {
            $structure = new EveStructure();
            $structure->setId((string)$locationId);
            $structure->setName('Spieler-Struktur');
            $structure->setSolarSystemId(0);
            $structure->setSolarSystemName('Unbekannt');
        }
        $structure->setLastUpdated($now);
        $this->entityManager->persist($structure);
        $this->entityManager->flush();

        return [
            'name' => $structure->getName(),
            'systemName' => $structure->getSolarSystemName() ?? 'Unbekannt',
            'rawName' => $structure->getName(),
        ];
    }
}
