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
    private array $resolvedLocations = [];

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
    public function resolveLocation(int $locationId, ?EveCharacter $character = null, bool $forceRefresh = false): array
    {
        if (!$forceRefresh && isset($this->resolvedLocations[$locationId])) {
            return $this->resolvedLocations[$locationId];
        }

        $result = $this->doResolveLocation($locationId, $character, $forceRefresh);
        $this->resolvedLocations[$locationId] = $result;

        return $result;
    }

    private function doResolveLocation(int $locationId, ?EveCharacter $character = null, bool $forceRefresh = false): array
    {
        // 0. Check if this is a corporation office (type ID 27) nested within another location
        $corpAsset = $this->entityManager->getRepository(\App\Entity\EveCorporationAsset::class)->findOneBy(['itemId' => $locationId]);
        if ($corpAsset && $corpAsset->getTypeId() === 27 && $corpAsset->getLocationId() > 0 && $corpAsset->getLocationId() !== $locationId) {
            return $this->doResolveLocation($corpAsset->getLocationId(), $character, $forceRefresh);
        }

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
            return $this->resolvePlayerStructure($locationId, $character, $forceRefresh);
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
    private function resolvePlayerStructure(int $locationId, ?EveCharacter $character = null, bool $forceRefresh = false): array
    {
        // Check if we can find this location in corporation assets (e.g. Customs Offices)
        $corpAsset = $this->entityManager->getRepository(\App\Entity\EveCorporationAsset::class)->findOneBy(['itemId' => $locationId]);
        
        // Try to infer solar system ID and name from local records
        $inferredSolarSystemId = 0;
        $inferredSolarSystemName = 'Unbekannt';
        if ($corpAsset && $corpAsset->getLocationId() >= 30000000 && $corpAsset->getLocationId() < 32000000) {
            $inferredSolarSystemId = (int)$corpAsset->getLocationId();
        }
        if ($inferredSolarSystemId === 0) {
            $charAsset = $this->entityManager->getRepository(\App\Entity\EveCharacterAsset::class)->findOneBy(['locationId' => $locationId]);
            if ($charAsset && $charAsset->getLocationId() >= 30000000 && $charAsset->getLocationId() < 32000000) {
                $inferredSolarSystemId = (int)$charAsset->getLocationId();
            }
        }
        if ($inferredSolarSystemId > 0) {
            $inferredSolarSystemName = $this->sdeConnection->fetchOne(
                'SELECT solarSystemName FROM mapSolarSystems WHERE solarSystemID = :id LIMIT 1',
                ['id' => $inferredSolarSystemId]
            ) ?: 'Unbekannt';
        }

        if ($corpAsset && $corpAsset->getCustomName()) {
            $structureName = $corpAsset->getCustomName();
            $solarSystemName = 'Unbekannt';
            if (preg_match('/\(([^)]+)\)/', $structureName, $matches)) {
                $parts = explode(' ', trim($matches[1]));
                $solarSystemName = $parts[0];
            }
            if (($solarSystemName === 'Unbekannt' || empty($solarSystemName)) && $inferredSolarSystemName !== 'Unbekannt') {
                $solarSystemName = $inferredSolarSystemName;
            }

            $formattedName = $structureName;
            if ($solarSystemName && $solarSystemName !== 'Unbekannt') {
                $escapedSystem = preg_quote($solarSystemName, '/');
                if (!preg_match('/^\s*' . $escapedSystem . '\b/i', $structureName)) {
                    $formattedName = $solarSystemName . ' - ' . $structureName;
                }
            }

            return [
                'name' => $formattedName,
                'systemName' => $solarSystemName,
                'rawName' => $structureName,
            ];
        }

        $structureRepo = $this->entityManager->getRepository(EveStructure::class);
        $structure = $structureRepo->find((string)$locationId);

        $now = new \DateTimeImmutable();
        // Fallbacks expire in 1 day, successfully resolved structures in 30 days
        $cacheExpiryDays = ($structure && $structure->getName() === 'Spieler-Struktur') ? 1 : 30;

        // If cached and still valid, return cached info
        if (!$forceRefresh && $structure && 
            $structure->getLastUpdated()->modify('+' . $cacheExpiryDays . ' days') > $now
        ) {
            $structureName = $structure->getName();
            $solarSystemName = $structure->getSolarSystemName() ?? 'Unbekannt';

            if ($solarSystemName === 'Unbekannt' && $inferredSolarSystemName !== 'Unbekannt') {
                $structure->setSolarSystemId($inferredSolarSystemId);
                $structure->setSolarSystemName($inferredSolarSystemName);
                $this->entityManager->persist($structure);
                $this->entityManager->flush();
                $solarSystemName = $inferredSolarSystemName;
            }

            $formattedName = $structureName;
            if ($solarSystemName && $solarSystemName !== 'Unbekannt') {
                $escapedSystem = preg_quote($solarSystemName, '/');
                if (!preg_match('/^\s*' . $escapedSystem . '\b/i', $structureName)) {
                    $formattedName = $solarSystemName . ' - ' . $structureName;
                }
            }

            return [
                'name' => $formattedName,
                'systemName' => $solarSystemName,
                'rawName' => $structureName,
            ];
        }

        $resolvedData = null;
        $targetCorpId = null;
        if ($structure && $structure->getOwnerId()) {
            $targetCorpId = (int)$structure->getOwnerId();
        }
        if (!$targetCorpId && $corpAsset) {
            $targetCorpId = (int)$corpAsset->getCorporationId();
        }
        if (!$targetCorpId) {
            // Check if any character has assets at this location to infer target corporation
            $charAsset = $this->entityManager->getRepository(\App\Entity\EveCharacterAsset::class)->findOneBy(['locationId' => $locationId]);
            if ($charAsset && $charAsset->getCharacter()) {
                $targetCorpId = (int)$charAsset->getCharacter()->getCorporationId();
            }
        }

        $allChars = $this->entityManager->getRepository(EveCharacter::class)->findAll();

        $directorsInTargetCorp = [];
        $otherCharsInTargetCorp = [];
        $directorsInOtherCorp = [];
        $otherChars = [];

        foreach ($allChars as $ac) {
            if (empty($ac->getRefreshToken())) {
                continue;
            }

            $isTargetCorp = ($targetCorpId > 0 && $ac->getCorporationId() === $targetCorpId);

            if ($isTargetCorp && $ac->isDirector()) {
                $directorsInTargetCorp[] = $ac;
            } elseif ($isTargetCorp) {
                $otherCharsInTargetCorp[] = $ac;
            } elseif ($ac->isDirector()) {
                $directorsInOtherCorp[] = $ac;
            } else {
                $otherChars[] = $ac;
            }
        }

        $charactersToTry = [];
        $queuedIds = [];

        $queueChar = function(?EveCharacter $c) use (&$charactersToTry, &$queuedIds) {
            if ($c && !empty($c->getRefreshToken()) && !in_array($c->getId(), $queuedIds, true)) {
                $charactersToTry[] = $c;
                $queuedIds[] = $c->getId();
            }
        };

        // 1. First try: Direct input character if it is a director
        if ($character && $character->isDirector()) {
            $queueChar($character);
        }

        // 2. Directors of the target corporation
        foreach ($directorsInTargetCorp as $dc) {
            $queueChar($dc);
        }

        // 3. The passed character itself
        $queueChar($character);

        // 4. Directors of other corporations
        foreach ($directorsInOtherCorp as $dc) {
            $queueChar($dc);
        }

        // 5. Other characters in the target corporation
        foreach ($otherCharsInTargetCorp as $oc) {
            $queueChar($oc);
        }

        // 6. Other characters of the same user
        if ($character && $character->getUser()) {
            foreach ($allChars as $ac) {
                if ($ac->getUser() === $character->getUser()) {
                    $queueChar($ac);
                }
            }
        }

        // 7. Finally all other characters
        foreach ($otherChars as $oc) {
            $queueChar($oc);
        }

        // Try to fetch from ESI using queued characters (limit to 3 actual attempts)
        $attempts = 0;
        foreach ($charactersToTry as $tryChar) {
            if ($attempts >= 3) {
                break;
            }
            $attempts++;

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
                // If it failed (e.g. 403) and the character is a director,
                // we can query the corporation structures API of the character's corporation.
                if ($tryChar->isDirector()) {
                    try {
                        $corpIdToQuery = $tryChar->getCorporationId();
                        $corpStructures = $this->esiClient->request(
                            'GET',
                            sprintf('corporations/%d/structures/', $corpIdToQuery),
                            [],
                            $tryChar
                        );

                        if (is_array($corpStructures)) {
                            $foundData = null;
                            foreach ($corpStructures as $corpStruct) {
                                $cStructId = (int)$corpStruct['structure_id'];
                                $cStructName = $corpStruct['name'] ?? 'Spieler-Struktur';
                                $cSystemId = (int)($corpStruct['system_id'] ?? 0);

                                if ($cStructId === $locationId) {
                                    $foundData = [
                                        'name' => $cStructName,
                                        'solar_system_id' => $cSystemId,
                                        'owner_id' => $corpIdToQuery
                                    ];
                                }

                                $this->cacheStructureData($cStructId, $cStructName, $cSystemId, $corpIdToQuery);
                            }

                            if ($foundData) {
                                $resolvedData = $foundData;
                                break;
                            }
                        }
                    } catch (\Exception $corpEx) {
                        // Ignore and keep trying next character
                    }
                }
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
            } elseif ($inferredSolarSystemId > 0) {
                $solarSystemId = $inferredSolarSystemId;
                $solarSystemName = $inferredSolarSystemName;
            }

            $ownerId = (int)($resolvedData['owner_id'] ?? 0);
            $ownerName = null;
            if ($ownerId > 0) {
                try {
                    $corpInfo = $this->esiClient->request('GET', sprintf('corporations/%d/', $ownerId));
                    if (isset($corpInfo['name'])) {
                        $ownerName = $corpInfo['name'];
                    }
                } catch (\Exception $e) {
                    // Ignore
                }
            }

            // Save to local cache using standard Doctrine Entity
            if (!$structure) {
                $structure = new EveStructure();
                $structure->setId((string)$locationId);
            }
            $structure->setName($structureName);
            $structure->setSolarSystemId($solarSystemId);
            $structure->setSolarSystemName($solarSystemName);
            $structure->setOwnerId($ownerId > 0 ? (string)$ownerId : null);
            $structure->setOwnerName($ownerName);
            $structure->setLastUpdated($now);

            $this->entityManager->persist($structure);
            $this->entityManager->flush();

            $formattedName = $structureName;
            if ($solarSystemName && $solarSystemName !== 'Unbekannt') {
                $escapedSystem = preg_quote($solarSystemName, '/');
                if (!preg_match('/^\s*' . $escapedSystem . '\b/i', $structureName)) {
                    $formattedName = $solarSystemName . ' - ' . $structureName;
                }
            }

            return [
                'name' => $formattedName,
                'systemName' => $solarSystemName,
                'rawName' => $structureName,
            ];
        }

        // If fetching fails completely, cache a fallback so we don't spam ESI
        if (!$structure) {
            $structure = new EveStructure();
            $structure->setId((string)$locationId);
            $structure->setName('Spieler-Struktur');
            $structure->setSolarSystemId($inferredSolarSystemId);
            $structure->setSolarSystemName($inferredSolarSystemName);
        } else {
            // Update solar system if it was unknown
            if (($structure->getSolarSystemId() === 0 || !$structure->getSolarSystemName() || $structure->getSolarSystemName() === 'Unbekannt') && $inferredSolarSystemId > 0) {
                $structure->setSolarSystemId($inferredSolarSystemId);
                $structure->setSolarSystemName($inferredSolarSystemName);
            }
        }
        $structure->setLastUpdated($now);
        $this->entityManager->persist($structure);
        $this->entityManager->flush();

        $structureName = $structure->getName();
        $solarSystemName = $structure->getSolarSystemName() ?? 'Unbekannt';

        $formattedName = $structureName;
        if ($solarSystemName && $solarSystemName !== 'Unbekannt') {
            $escapedSystem = preg_quote($solarSystemName, '/');
            if (!preg_match('/^\s*' . $escapedSystem . '\b/i', $structureName)) {
                $formattedName = $solarSystemName . ' - ' . $structureName;
            }
        }

        return [
            'name' => $formattedName,
            'systemName' => $solarSystemName,
            'rawName' => $structureName,
        ];
    }

    /**
     * Updates expired or fallback structures in the background.
     */
    public function updateExpiredStructures(\Psr\Log\LoggerInterface $logger): void
    {
        $structureRepo = $this->entityManager->getRepository(EveStructure::class);
        $now = new \DateTimeImmutable();
        
        $resolvedExpiryLimit = $now->modify('-30 days');
        $fallbackExpiryLimit = $now->modify('-1 days');

        // Find structures that are resolved but older than 30 days, OR fallbacks older than 1 day
        $queryBuilder = $structureRepo->createQueryBuilder('s');
        $structures = $queryBuilder
            ->where('s.name != :fallbackName AND s.lastUpdated < :resolvedExpiryLimit')
            ->orWhere('s.name = :fallbackName AND s.lastUpdated < :fallbackExpiryLimit')
            ->setParameter('fallbackName', 'Spieler-Struktur')
            ->setParameter('resolvedExpiryLimit', $resolvedExpiryLimit)
            ->setParameter('fallbackExpiryLimit', $fallbackExpiryLimit)
            ->getQuery()
            ->getResult();

        $logger->info(sprintf('[Cron] Found %d structures that need updating.', count($structures)));

        foreach ($structures as $structure) {
            $locationId = (int)$structure->getId();
            $logger->info(sprintf('[Cron] Re-resolving structure ID %d (%s)...', $locationId, $structure->getName()));
            
            // Bypass in-memory cache to force a fresh check
            unset($this->resolvedLocations[$locationId]);
            $this->doResolveLocation($locationId, null, true);
        }
    }

    private function cacheStructureData(int $locationId, string $name, int $solarSystemId, int $ownerId): void
    {
        $structureRepo = $this->entityManager->getRepository(EveStructure::class);
        $structure = $structureRepo->find((string)$locationId);
        
        $now = new \DateTimeImmutable();
        
        if (!$structure) {
            $structure = new EveStructure();
            $structure->setId((string)$locationId);
        }
        
        // Only update if name changed or it was a fallback before
        if ($structure->getName() !== $name || $structure->getSolarSystemId() !== $solarSystemId || !$structure->getOwnerId()) {
            $solarSystemName = 'Unbekannt';
            if ($solarSystemId > 0) {
                $solarSystemName = $this->sdeConnection->fetchOne(
                    'SELECT solarSystemName FROM mapSolarSystems WHERE solarSystemID = :id LIMIT 1',
                    ['id' => $solarSystemId]
                ) ?: 'Unbekannt';
            }
            
            $structure->setName($name);
            $structure->setSolarSystemId($solarSystemId);
            $structure->setSolarSystemName($solarSystemName);
            $structure->setOwnerId((string)$ownerId);
            $structure->setLastUpdated($now);
            
            // Resolve owner name
            try {
                $corpInfo = $this->esiClient->request('GET', sprintf('corporations/%d/', $ownerId));
                if (isset($corpInfo['name'])) {
                    $structure->setOwnerName($corpInfo['name']);
                }
            } catch (\Exception $e) {
                // Ignore
            }
            
            $this->entityManager->persist($structure);
            $this->entityManager->flush();
        }
    }
}



