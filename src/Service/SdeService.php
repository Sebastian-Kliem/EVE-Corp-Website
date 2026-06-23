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
                return (bool)preg_match('/(blueprint|formula)/i', $groupName);
            }
        } catch (\Exception $e) {
            // Keep going and return false on errors
        }
        return false;
    }

    private array $categoryCache = [];

    /**
     * Categorizes an item based on its categoryID, groupID or groupName from the EVE SDE.
     * Returns: 'ship' | 'blueprint' | 'container' | 'mineral' | 'ore' | 'gas' | 'pi' | 'other'
     */
    public function getItemCategory(int $typeId): string
    {
        if (isset($this->categoryCache[$typeId])) {
            return $this->categoryCache[$typeId];
        }

        try {
            $row = $this->connection->fetchAssociative(
                'SELECT g.categoryID, g.groupID, g.groupName FROM invTypes t JOIN invGroups g ON t.groupID = g.groupID WHERE t.typeID = :id LIMIT 1',
                ['id' => $typeId]
            );

            if (!$row) {
                $this->categoryCache[$typeId] = 'other';
                return 'other';
            }

            $categoryId = (int)$row['categoryID'];
            $groupId = (int)$row['groupID'];
            $groupName = (string)$row['groupName'];

            $category = 'other';

            if ($categoryId === 6) {
                $category = 'ship';
            } elseif (preg_match('/(blueprint|formula)/i', $groupName)) {
                $category = 'blueprint';
            } elseif (preg_match('/container/i', $groupName)) {
                $category = 'container';
            } elseif ($groupId === 18) {
                $category = 'mineral';
            } elseif ($categoryId === 25) {
                $category = 'ore';
            } elseif ($groupId === 711) {
                $category = 'gas';
            } elseif ($categoryId === 43 || in_array($groupId, [1031, 1034, 1042], true) || preg_match('/planetary/i', $groupName)) {
                $category = 'pi';
            }

            $this->categoryCache[$typeId] = $category;
            return $category;

        } catch (\Exception $e) {
            return 'other';
        }
    }

    /**
     * Checks if a typeID belongs to a ship group (categoryID = 6) in the SDE database.
     */
    public function isShip(int $typeId): bool
    {
        return $this->getItemCategory($typeId) === 'ship';
    }

    /**
     * Checks if a typeID belongs to a container group in the SDE database.
     */
    public function isContainer(int $typeId): bool
    {
        return $this->getItemCategory($typeId) === 'container';
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
     * Resolves a list of item names to their typeIDs.
     * @param string[] $names
     * @return array Map of lowercase item name => SDE data
     */
    public function resolveItemNames(array $names): array
    {
        if (empty($names)) {
            return [];
        }

        // Clean names
        $names = array_unique(array_filter(array_map('trim', $names)));
        if (empty($names)) {
            return [];
        }

        try {
            $placeholders = implode(',', array_fill(0, count($names), '?'));
            $stmt = $this->connection->prepare(
                "SELECT t.typeID as id, t.typeName as name, g.groupName 
                 FROM invTypes t 
                 JOIN invGroups g ON t.groupID = g.groupID 
                 WHERE t.published = 1 AND t.typeName IN ($placeholders)"
            );
            $result = $stmt->executeQuery(array_values($names));
            $rows = $result->fetchAllAssociative();

            $resolved = [];
            foreach ($rows as $row) {
                $isBlueprint = (bool)preg_match('/blueprint/i', $row['groupName'] ?? '');
                $resolved[strtolower($row['name'])] = [
                    'id' => (int)$row['id'],
                    'name' => $row['name'],
                    'variation' => $isBlueprint ? 'bp' : 'icon',
                ];
            }
            return $resolved;
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

    public function getBlueprintDetails(int $blueprintTypeId, int $activityId): array
    {
        // Map ESI activity ID to SDE activity ID
        $sdeActivityId = $activityId;
        if ($activityId === 9) {
            $sdeActivityId = 11; // Reactions
        } elseif ($activityId === 7 || $activityId === 8) {
            $sdeActivityId = 8; // Invention / Reverse Engineering
        }

        try {
            $materials = $this->connection->fetchAllAssociative(
                'SELECT materialTypeID as typeId, quantity FROM industryActivityMaterials WHERE typeID = :bpId AND activityID = :actId',
                ['bpId' => $blueprintTypeId, 'actId' => $sdeActivityId]
            );
            
            $products = $this->connection->fetchAllAssociative(
                'SELECT productTypeID as typeId, quantity FROM industryActivityProducts WHERE typeID = :bpId AND activityID = :actId',
                ['bpId' => $blueprintTypeId, 'actId' => $sdeActivityId]
            );

            // Fetch names for all types in one go to be fast
            $typeIds = [];
            foreach ($materials as $m) {
                $typeIds[] = (int)$m['typeId'];
            }
            foreach ($products as $p) {
                $typeIds[] = (int)$p['typeId'];
            }
            $typeIds = array_unique($typeIds);
            
            $names = [];
            if (!empty($typeIds)) {
                $placeholders = implode(',', array_fill(0, count($typeIds), '?'));
                $stmt = $this->connection->prepare(
                    "SELECT typeID, typeName FROM invTypes WHERE typeID IN ($placeholders)"
                );
                $result = $stmt->executeQuery($typeIds);
                foreach ($result->fetchAllAssociative() as $row) {
                    $names[(int)$row['typeID']] = $row['typeName'];
                }
            }

            $mappedMaterials = [];
            foreach ($materials as $m) {
                $tId = (int)$m['typeId'];
                $mappedMaterials[] = [
                    'typeId' => $tId,
                    'name' => $names[$tId] ?? ('Item #' . $tId),
                    'quantity' => (int)$m['quantity']
                ];
            }

            $mappedProducts = [];
            foreach ($products as $p) {
                $tId = (int)$p['typeId'];
                $mappedProducts[] = [
                    'typeId' => $tId,
                    'name' => $names[$tId] ?? ('Item #' . $tId),
                    'quantity' => (int)$p['quantity']
                ];
            }

            return [
                'materials' => $mappedMaterials,
                'products' => $mappedProducts
            ];
        } catch (\Exception $e) {
            return [
                'materials' => [],
                'products' => []
            ];
        }
    }

    public function getSchematicDetails(int $schematicId): ?array
    {
        try {
            $schematic = $this->connection->fetchAssociative(
                'SELECT schematicName, cycleTime FROM planetSchematics WHERE schematicID = :id LIMIT 1',
                ['id' => $schematicId]
            );

            if (!$schematic) {
                return null;
            }

            $types = $this->connection->fetchAllAssociative(
                'SELECT t.typeID, t.typeName, m.quantity, m.isInput 
                 FROM planetSchematicsTypeMap m 
                 JOIN invTypes t ON m.typeID = t.typeID 
                 WHERE m.schematicID = :id',
                ['id' => $schematicId]
            );

            $inputs = [];
            $outputs = [];

            foreach ($types as $row) {
                $item = [
                    'type_id' => (int)$row['typeID'],
                    'name' => $row['typeName'],
                    'quantity' => (int)$row['quantity'],
                ];
                if ($row['isInput']) {
                    $inputs[] = $item;
                } else {
                    $outputs[] = $item;
                }
            }

            return [
                'name' => $schematic['schematicName'],
                'cycleTime' => (int)$schematic['cycleTime'],
                'inputs' => $inputs,
                'outputs' => $outputs,
            ];
        } catch (\Exception $e) {
            return null;
        }
    }

    /**
     * Returns all type IDs belonging to Gas, Ore/Ice, Blue Loot, Hacking & Salvaging categories.
     * @return int[]
     */
    public function getPerformanceTypeIds(): array
    {
        try {
            return array_map('intval', $this->connection->fetchFirstColumn(
                "SELECT t.typeID FROM invTypes t JOIN invGroups g ON t.groupID = g.groupID 
                 WHERE g.categoryID = 25 
                    OR g.groupID IN (711, 4168, 880, 754, 966, 333, 728, 729, 730, 731, 732, 733, 734, 735, 979, 1304, 367776) 
                    OR t.typeID = 34"
            ));
        } catch (\Exception $e) {
            return [];
        }
    }
}

