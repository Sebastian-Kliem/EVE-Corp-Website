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
            } elseif ($groupId === 711 || $groupId === 4168) {
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
     * Searches for items in the SDE database by name.
     * Returns an array of items matching the query.
     */
    public function searchItems(string $query, int $limit = 50): array
    {
        $cleanedQuery = trim(str_replace('*', '', $query));
        if (strlen($cleanedQuery) < 2) {
            return [];
        }

        try {
            $results = $this->connection->fetchAllAssociative(
                'SELECT t.typeID as id, t.typeName as name, g.groupName 
                 FROM invTypes t 
                 JOIN invGroups g ON t.groupID = g.groupID 
                 WHERE t.published = 1 AND t.typeName LIKE :likeQuery 
                 ORDER BY 
                    CASE 
                        WHEN g.categoryID = 91 OR g.groupName LIKE \'%SKIN%\' OR t.typeName LIKE \'% SKIN%\' OR t.typeName LIKE \'% SKIN\' THEN 1 
                        ELSE 0 
                    END ASC,
                    CASE 
                        WHEN LOWER(t.typeName) = LOWER(:exactQuery) THEN 0 
                        WHEN LOWER(t.typeName) LIKE LOWER(:prefixQuery) THEN 1 
                        ELSE 2 
                    END ASC,
                    LENGTH(t.typeName) ASC,
                    t.typeName ASC 
                 LIMIT :limit',
                [
                    'likeQuery' => '%' . $cleanedQuery . '%',
                    'exactQuery' => $cleanedQuery,
                    'prefixQuery' => $cleanedQuery . '%',
                    'limit' => $limit,
                ],
                [
                    'limit' => \PDO::PARAM_INT
                ]
            );

            $formattedItems = [];
            foreach ($results as $row) {
                $isBlueprint = (bool)preg_match('/blueprint/i', $row['groupName'] ?? '');
                $formattedItems[] = [
                    'id' => (int)$row['id'],
                    'name' => $row['name'],
                    'variation' => $isBlueprint ? 'bp' : 'icon',
                ];
            }

            return $formattedItems;
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
        $names = array_unique(array_filter(array_map(fn($n) => trim(str_replace('*', '', (string)$n)), $names)));
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
                    OR t.typeID IN (33577, 33539, 33521, 33527, 33536, 33543, 33545, 33546, 33547, 33548, 33556, 33558, 33560, 33562, 33564, 33566, 57442, 57443, 57444, 57445, 57446, 57447, 57448, 57449, 57450, 57451, 57452)"
            ));
        } catch (\Exception $e) {
            return [];
        }
    }

    /**
     * Returns all type IDs belonging to blueprints or reaction formulas.
     * @return int[]
     */
    public function getAllBlueprintTypeIds(): array
    {
        try {
            return array_map('intval', $this->connection->fetchFirstColumn(
                "SELECT t.typeID FROM invTypes t JOIN invGroups g ON t.groupID = g.groupID 
                 WHERE g.groupName LIKE '%blueprint%' OR g.groupName LIKE '%formula%'"
            ));
        } catch (\Exception $e) {
            return [];
        }
    }

    /**
     * Resolves the market category and product type ID of the item produced by the blueprint.
     */
    public function getBlueprintProductInfo(int $blueprintTypeId): array
    {
        try {
            $row = $this->connection->fetchAssociative(
                'SELECT p.productTypeID, g.categoryID, g.groupName FROM industryActivityProducts p 
                 JOIN invTypes t ON p.productTypeID = t.typeID 
                 JOIN invGroups g ON t.groupID = g.groupID 
                 WHERE p.typeID = :bpId AND p.activityID IN (1, 11) 
                 LIMIT 1',
                ['bpId' => $blueprintTypeId]
            );

            if (!$row) {
                return [
                    'productId' => $blueprintTypeId,
                    'category' => 'Sonstiges'
                ];
            }

            $productId = (int)$row['productTypeID'];
            $categoryId = (int)$row['categoryID'];
            $groupName = (string)$row['groupName'];

            $category = 'Sonstiges';
            if ($categoryId === 6) {
                $category = 'Schiffe';
            }
            if ($categoryId === 7) {
                $category = 'Module';
            }
            if ($categoryId === 8) {
                $category = 'Munition';
            }
            if ($categoryId === 18) {
                $category = 'Drohnen';
            }
            if ($categoryId === 32) {
                $category = 'Subsysteme';
            }
            if ($categoryId === 4 || $categoryId === 9 || str_contains(strtolower($groupName), 'component') || str_contains(strtolower($groupName), 'reaction') || str_contains(strtolower($groupName), 'polymer')) {
                $category = 'Komponenten & Reaktionen';
            }

            return [
                'productId' => $productId,
                'category' => $category
            ];
        } catch (\Exception $e) {
            return [
                'productId' => $blueprintTypeId,
                'category' => 'Sonstiges'
            ];
        }
    }

    private const PACKAGED_SHIP_VOLUMES_BY_GROUP = [
        25 => 2500.0,   // Frigate
        324 => 2500.0,  // Assault Frigate
        830 => 2500.0,  // Covert Ops
        893 => 2500.0,  // Electronic Attack Ship
        1283 => 2500.0, // Expedition Frigate
        831 => 2500.0,  // Interceptor
        1527 => 2500.0, // Logistics Frigate
        834 => 2500.0,  // Stealth Bomber
        1022 => 2500.0, // Prototype Exploration Ship
        237 => 2500.0,  // Corvette
        29 => 500.0,    // Capsule
        31 => 500.0,    // Shuttle
        420 => 5000.0,  // Destroyer
        541 => 5000.0,  // Interdictor
        1534 => 5000.0, // Command Destroyer
        1305 => 5000.0, // Tactical Destroyer
        26 => 10000.0,  // Cruiser
        358 => 10000.0, // Heavy Assault Cruiser
        894 => 10000.0, // Heavy Interdiction Cruiser
        832 => 10000.0, // Logistics
        833 => 10000.0, // Force Recon Ship
        906 => 10000.0, // Combat Recon Ship
        963 => 10000.0, // Strategic Cruiser
        1972 => 10000.0,// Flag Cruiser
        463 => 3750.0,  // Mining Barge
        543 => 3750.0,  // Exhumer
        419 => 15000.0, // Combat Battlecruiser
        1201 => 15000.0,// Attack Battlecruiser
        540 => 15000.0, // Command Ship
        27 => 50000.0,  // Battleship
        898 => 50000.0, // Black Ops
        900 => 50000.0, // Marauder
        381 => 50000.0, // Elite Battleship
        28 => 20000.0,  // Hauler
        380 => 20000.0, // Deep Space Transport
        1202 => 20000.0,// Blockade Runner
        5087 => 20000.0,// Special Edition Yachts
        941 => 500000.0,// Industrial Command Ship (Orca)
        4902 => 500000.0,// Expedition Command Ship
        883 => 1000000.0,// Capital Industrial Ship (Rorqual)
        513 => 1000000.0,// Freighter
        902 => 1000000.0,// Jump Freighter
        547 => 1000000.0,// Carrier
        5120 => 1000000.0,// Command Carrier
        485 => 1000000.0,// Dreadnought
        4594 => 1000000.0,// Lancer Dreadnought
        1538 => 1000000.0,// Force Auxiliary
        659 => 2500000.0,// Supercarrier
        30 => 10000000.0,// Titan
    ];

    /**
     * Resolves the volume of an item from the SDE (packaged or unpackaged).
     */
    public function getItemVolume(int $typeId, bool $packaged = true): float
    {
        try {
            $row = $this->connection->fetchAssociative(
                'SELECT t.volume, t.groupID, g.categoryID 
                 FROM invTypes t 
                 JOIN invGroups g ON t.groupID = g.groupID 
                 WHERE t.typeID = :id LIMIT 1',
                ['id' => $typeId]
            );

            if (!$row) {
                return 0.0;
            }

            $volume = (float)($row['volume'] ?? 0.0);
            $categoryId = (int)($row['categoryID'] ?? 0);
            $groupId = (int)($row['groupID'] ?? 0);

            if ($packaged && $categoryId === 6 && isset(self::PACKAGED_SHIP_VOLUMES_BY_GROUP[$groupId])) {
                return self::PACKAGED_SHIP_VOLUMES_BY_GROUP[$groupId];
            }

            return $volume;
        } catch (\Exception $e) {
            return 0.0;
        }
    }

    /**
     * Batch retrieves comprehensive SDE details for an array of typeIDs.
     * @param int[] $typeIds
     * @return array<int, array> Map of typeId => details
     */
    public function getItemsDetails(array $typeIds): array
    {
        $typeIds = array_values(array_unique(array_filter(array_map('intval', $typeIds))));
        if (empty($typeIds)) {
            return [];
        }

        try {
            $placeholders = implode(',', array_fill(0, count($typeIds), '?'));
            $stmt = $this->connection->prepare(
                "SELECT t.typeID, t.typeName, t.volume, t.mass, t.capacity, t.groupID, g.groupName, g.categoryID, c.categoryName
                 FROM invTypes t
                 JOIN invGroups g ON t.groupID = g.groupID
                 JOIN invCategories c ON g.categoryID = c.categoryID
                 WHERE t.typeID IN ($placeholders)"
            );
            $result = $stmt->executeQuery($typeIds);
            $rows = $result->fetchAllAssociative();

            // Fetch fitting slot effects for modules if any
            $slotMap = $this->_fetchSlotEffectsForTypeIds($typeIds);

            $details = [];
            foreach ($rows as $row) {
                $typeId = (int)$row['typeID'];
                $categoryId = (int)$row['categoryID'];
                $groupId = (int)$row['groupID'];
                $rawVolume = (float)$row['volume'];
                $packagedVolume = $rawVolume;

                if ($categoryId === 6 && isset(self::PACKAGED_SHIP_VOLUMES_BY_GROUP[$groupId])) {
                    $packagedVolume = self::PACKAGED_SHIP_VOLUMES_BY_GROUP[$groupId];
                }

                $slot = 'cargo';
                if ($categoryId === 6) {
                    $slot = 'hull';
                } elseif (isset($slotMap[$typeId])) {
                    $slot = $slotMap[$typeId];
                } elseif ($categoryId === 18) {
                    $slot = 'drone';
                } elseif ($categoryId === 87) {
                    $slot = 'fighter';
                } elseif ($categoryId === 32) {
                    $slot = 'subsystem';
                } elseif ($categoryId === 8) {
                    $slot = 'charge';
                }

                $isBlueprint = (bool)preg_match('/(blueprint|formula)/i', $row['groupName'] ?? '');

                $details[$typeId] = [
                    'typeId' => $typeId,
                    'name' => $row['typeName'],
                    'groupId' => $groupId,
                    'groupName' => $row['groupName'],
                    'categoryId' => $categoryId,
                    'categoryName' => $row['categoryName'],
                    'volume' => $rawVolume,
                    'packagedVolume' => $packagedVolume,
                    'slot' => $slot,
                    'variation' => $isBlueprint ? 'bp' : 'icon',
                ];
            }

            return $details;
        } catch (\Exception $e) {
            return [];
        }
    }

    /**
     * Helper to fetch slot effects for typeIDs.
     */
    private function _fetchSlotEffectsForTypeIds(array $typeIds): array
    {
        if (empty($typeIds)) {
            return [];
        }

        try {
            $placeholders = implode(',', array_fill(0, count($typeIds), '?'));
            $stmt = $this->connection->prepare(
                "SELECT te.typeID, te.effectID
                 FROM dgmTypeEffects te
                 WHERE te.typeID IN ($placeholders) AND te.effectID IN (11, 12, 13, 2663, 3772)"
            );
            $result = $stmt->executeQuery($typeIds);
            $rows = $result->fetchAllAssociative();

            $map = [];
            foreach ($rows as $row) {
                $tId = (int)$row['typeID'];
                $eId = (int)$row['effectID'];
                if ($eId === 12) {
                    $map[$tId] = 'high';
                } elseif ($eId === 13) {
                    $map[$tId] = 'med';
                } elseif ($eId === 11) {
                    $map[$tId] = 'low';
                } elseif ($eId === 2663) {
                    $map[$tId] = 'rig';
                } elseif ($eId === 3772) {
                    $map[$tId] = 'subsystem';
                }
            }

            return $map;
        } catch (\Exception $e) {
            return [];
        }
    }
}


