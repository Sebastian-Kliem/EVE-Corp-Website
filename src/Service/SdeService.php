<?php

namespace App\Service;

use Doctrine\Persistence\ManagerRegistry;
use Doctrine\DBAL\Connection;

class SdeService
{
    private Connection $connection;
    private array $nameCache = [];

    public function __construct(ManagerRegistry $doctrine)
    {
        // Get the specific connection named 'sde' as configured in doctrine.yaml
        $this->connection = $doctrine->getConnection('sde');
    }

    /**
     * Translates a numeric EVE typeID into its typeName.
     * If the input is not numeric (legacy data) or not found, it returns the input as is.
     */
    public function getItemName(mixed $itemId): string
    {
        if (empty($itemId)) {
            return '';
        }

        if (!is_numeric($itemId)) {
            return (string)$itemId;
        }

        $itemId = (int)$itemId;
        if (isset($this->nameCache[$itemId])) {
            return $this->nameCache[$itemId];
        }

        try {
            $name = $this->connection->fetchOne(
                'SELECT typeName FROM invTypes WHERE typeID = :id LIMIT 1',
                ['id' => $itemId]
            );

            if ($name) {
                $this->nameCache[$itemId] = $name;
                return $name;
            }
        } catch (\Exception $e) {
            // Keep going and return the ID as a string if SDE is not available or query fails
        }

        return (string)$itemId;
    }

    /**
     * Translates a numeric locationID into a name (station, solar system, etc.)
     */
    public function getLocationName(int $locationId): string
    {
        try {
            // Check if NPC station
            if ($locationId >= 60000000 && $locationId < 64000000) {
                $name = $this->connection->fetchOne(
                    'SELECT stationName FROM staStations WHERE stationID = :id LIMIT 1',
                    ['id' => $locationId]
                );
                if ($name) {
                    return $name;
                }
            }
            
            // Check if solar system
            if ($locationId >= 30000000 && $locationId < 32000000) {
                $name = $this->connection->fetchOne(
                    'SELECT solarSystemName FROM mapSolarSystems WHERE solarSystemID = :id LIMIT 1',
                    ['id' => $locationId]
                );
                if ($name) {
                    return $name;
                }
            }
        } catch (\Exception $e) {
            // Fallback
        }
        
        return 'Location #' . $locationId;
    }

    /**
     * Checks if a typeID belongs to a blueprint group in the SDE database.
     */
    public function isBlueprint(int $itemId): bool
    {
        try {
            $groupName = $this->connection->fetchOne(
                'SELECT g.groupName FROM invTypes t JOIN invGroups g ON t.groupID = g.groupID WHERE t.typeID = :id LIMIT 1',
                ['id' => $itemId]
            );

            if ($groupName) {
                return (bool)preg_match('/blueprint/i', $groupName);
            }
        } catch (\Exception $e) {
            // Keep going and return false on errors
        }
        return false;
    }

    /**
     * Searches for published items in the EVE SDE database by name.
     * Returns an array of items matching the query.
     */
    public function searchItems(string $query, int $limit = 20): array
    {
        try {
            $results = $this->connection->fetchAllAssociative(
                'SELECT t.typeID as id, t.typeName as name, g.groupName FROM invTypes t JOIN invGroups g ON t.groupID = g.groupID WHERE t.published = 1 AND t.typeName LIKE :query ORDER BY t.typeName ASC LIMIT :limit',
                [
                    'query' => '%' . $query . '%',
                    'limit' => $limit,
                ],
                [
                    'limit' => \PDO::PARAM_INT
                ]
            );

            return array_map(function ($row) {
                $isBlueprint = (bool)preg_match('/blueprint/i', $row['groupName'] ?? '');
                return [
                    'id' => (int)$row['id'],
                    'name' => $row['name'],
                    'variation' => $isBlueprint ? 'bp' : 'icon',
                ];
            }, $results);
        } catch (\Exception $e) {
            return [];
        }
    }

    /**
     * Checks if a typeID is a valid item ID in the SDE database.
     */
    public function isValidItem(mixed $itemId): bool
    {
        if (empty($itemId) || !is_numeric($itemId)) {
            return false;
        }

        $itemId = (int)$itemId;

        try {
            $exists = $this->connection->fetchOne(
                'SELECT 1 FROM invTypes WHERE typeID = :id LIMIT 1',
                ['id' => $itemId]
            );

            return (bool)$exists;
        } catch (\Exception $e) {
            return false;
        }
    }

    public function filterCustomizableTypeIds(array $typeIds): array
    {
        $typeIds = array_values(array_unique(array_filter(array_map('intval', $typeIds))));
        if (empty($typeIds)) {
            return [];
        }

        try {
            $placeholders = implode(',', array_fill(0, count($typeIds), '?'));
            $stmt = $this->connection->prepare(
                "SELECT DISTINCT t.typeid FROM invTypes t 
                 JOIN invGroups g ON t.groupid = g.groupid 
                 WHERE t.typeid IN ($placeholders) 
                   AND (g.categoryid = 6 OR g.groupid IN (12, 340, 448, 649))"
            );
            
            $result = $stmt->executeQuery($typeIds);
            return array_map('intval', $result->fetchFirstColumn());
        } catch (\Exception $e) {
            return [];
        }
    }
}
