<?php

namespace App\Service;

class ItemParserService
{
    private const FITTING_SLOT_ORDER = [
        'hull' => 1,
        'high' => 2,
        'med' => 3,
        'low' => 4,
        'rig' => 5,
        'subsystem' => 6,
        'drone' => 7,
        'fighter' => 8,
        'charge' => 9,
        'cargo' => 10,
        'other' => 11,
    ];

    private const CATEGORY_ORDER = [
        6 => 1,    // Ship
        7 => 2,    // Module
        66 => 3,   // Structure Module
        32 => 4,   // Subsystem
        18 => 5,   // Drone
        87 => 6,   // Fighter
        8 => 7,    // Charge / Ammo
        25 => 8,   // Asteroid / Ore
        4 => 9,    // Material / Minerals / Gas
        41 => 10,  // Planetary Industry
        42 => 11,  // Planetary Resources
        43 => 12,  // Planetary Commodities
        22 => 13,  // Deployable
        23 => 14,  // Starbase
        65 => 15,  // Structure
        20 => 16,  // Implant
        16 => 17,  // Skill
        9 => 18,   // Blueprint
        91 => 19,  // SKINs
        17 => 20,  // Commodity
    ];

    public function __construct(
        private readonly SdeService $sdeService
    ) {}

    /**
     * Parses raw input text (EFT fitting, EVE hangar tab copy, Multibuy, quantity notations).
     *
     * @param string $rawText
     * @return array
     */
    public function parse(string $rawText): array
    {
        $text = trim($rawText);
        if (empty($text)) {
            return [
                'isFitting' => false,
                'fitTitle' => null,
                'shipName' => null,
                'shipTypeId' => null,
                'items' => [],
                'unresolved' => [],
                'totalVolume' => 0.0,
                'totalItemCount' => 0,
            ];
        }

        $lines = preg_split('/\r\n|\r|\n/', $text);
        $firstNonEmptyLine = '';
        foreach ($lines as $line) {
            $trimmed = trim($line);
            if (!empty($trimmed)) {
                $firstNonEmptyLine = $trimmed;
                break;
            }
        }

        // Check if EFT format: Starts with [Ship Name, Fit Title] or [Ship Name]
        if (preg_match('/^\[\s*([^,\]]+?)(?:\s*,\s*([^\]]+?))?\s*\]$/', $firstNonEmptyLine, $eftMatch)) {
            return $this->_parseEftFit($lines, $eftMatch);
        }

        return $this->_parseGenericList($lines);
    }

    /**
     * Parses EFT formatted fittings.
     */
    private function _parseEftFit(array $lines, array $firstLineMatch): array
    {
        $rawShipName = trim($firstLineMatch[1]);
        $fitTitle = isset($firstLineMatch[2]) ? trim($firstLineMatch[2]) : null;

        $rawEntries = [];
        $namesToLookup = [$rawShipName];

        // Track the ship as the first item (quantity 1)
        $rawEntries[] = [
            'name' => $rawShipName,
            'quantity' => 1,
            'isShip' => true,
        ];

        // Parse remaining lines
        $isFirst = true;
        foreach ($lines as $line) {
            $trimmed = trim($line);
            if ($isFirst) {
                if (!empty($trimmed)) {
                    $isFirst = false;
                }
                continue;
            }

            if (empty($trimmed) || str_starts_with($trimmed, '[')) {
                continue;
            }

            // In EFT, lines can have trailing cargo / charges: "Heavy Missile Launcher II, Scourge Heavy Missile"
            // or quantities: "Hobgoblin II x5" / "Nanite Repair Paste x50"
            $parts = explode(',', $trimmed);
            foreach ($parts as $part) {
                $itemStr = trim($part);
                if (empty($itemStr) || str_starts_with($itemStr, '[Empty')) {
                    continue;
                }

                $qty = 1;
                $name = $itemStr;

                // Check for "Item x5" or "5x Item"
                if (preg_match('/^(.+?)\s+x\s*(\d+)$/i', $itemStr, $m)) {
                    $name = trim($m[1]);
                    $qty = (int)$m[2];
                } elseif (preg_match('/^(\d+)\s*x\s+(.+)$/i', $itemStr, $m)) {
                    $qty = (int)$m[1];
                    $name = trim($m[2]);
                }

                $name = trim(rtrim($name, '*'));
                if (!empty($name)) {
                    $rawEntries[] = [
                        'name' => $name,
                        'quantity' => max(1, $qty),
                        'isShip' => false,
                    ];
                    $namesToLookup[] = $name;
                }
            }
        }

        return $this->_buildResultFromRawEntries($rawEntries, $namesToLookup, true, $fitTitle, $rawShipName);
    }

    /**
     * Parses generic EVE text (Hangar Copy, Multibuy, Inventory, etc.).
     */
    private function _parseGenericList(array $lines): array
    {
        $rawEntries = [];
        $namesToLookup = [];

        foreach ($lines as $line) {
            $trimmed = trim($line);
            if (empty($trimmed)) {
                continue;
            }

            // 1. Tab-separated (EVE Hangar copy: "Item Name \t Quantity \t Group \t Size ...")
            if (str_contains($trimmed, "\t")) {
                $cols = explode("\t", $trimmed);
                $name = trim(rtrim(trim($cols[0]), '*'));
                $qty = 1;
                if (isset($cols[1])) {
                    $qty = $this->_cleanQuantity($cols[1]);
                }

                if (!empty($name)) {
                    $rawEntries[] = [
                        'name' => $name,
                        'quantity' => max(1, $qty),
                        'isShip' => false,
                    ];
                    $namesToLookup[] = $name;
                }
                continue;
            }

            // 2. Multibuy / Quantity regex: "Tritanium 10000", "10x Tritanium", "Tritanium x10", "10000 Tritanium"
            $name = $trimmed;
            $qty = 1;

            if (preg_match('/^(\d[\d\.,\s]*)\s*x\s+(.+)$/i', $trimmed, $m)) {
                $qty = $this->_cleanQuantity($m[1]);
                $name = trim($m[2]);
            } elseif (preg_match('/^(.+?)\s*x\s*(\d[\d\.,\s]*)$/i', $trimmed, $m)) {
                $name = trim($m[1]);
                $qty = $this->_cleanQuantity($m[2]);
            } elseif (preg_match('/^(.+?)\s+(\d[\d\.,\s]*)$/', $trimmed, $m)) {
                // Multibuy trailing quantity: "Tritanium 10000" or "Veldspar 50.000"
                $possibleQty = $this->_cleanQuantity($m[2]);
                if ($possibleQty > 0) {
                    $name = trim($m[1]);
                    $qty = $possibleQty;
                }
            } elseif (preg_match('/^(\d[\d\.,\s]*)\s+(.+)$/', $trimmed, $m)) {
                // Leading quantity: "10000 Tritanium"
                $possibleQty = $this->_cleanQuantity($m[1]);
                if ($possibleQty > 0) {
                    $qty = $possibleQty;
                    $name = trim($m[2]);
                }
            }

            $name = trim(rtrim($name, '*'));
            if (!empty($name)) {
                $rawEntries[] = [
                    'name' => $name,
                    'quantity' => max(1, $qty),
                    'isShip' => false,
                ];
                $namesToLookup[] = $name;
            }
        }

        return $this->_buildResultFromRawEntries($rawEntries, $namesToLookup, false, null, null);
    }

    /**
     * Resolves names and formats the final enriched item list.
     */
    private function _buildResultFromRawEntries(
        array $rawEntries,
        array $namesToLookup,
        bool $isFitting,
        ?string $fitTitle,
        ?string $rawShipName
    ): array {
        $resolvedMap = $this->sdeService->resolveItemNames($namesToLookup);
        $typeIds = array_column($resolvedMap, 'id');
        $detailsMap = $this->sdeService->getItemsDetails($typeIds);

        $mergedQuantities = [];
        $unresolved = [];
        $shipTypeId = null;
        $canonicalShipName = null;

        foreach ($rawEntries as $entry) {
            $nameLower = strtolower($entry['name']);
            if (!isset($resolvedMap[$nameLower])) {
                $unresolved[] = $entry['name'];
                continue;
            }

            $typeId = (int)$resolvedMap[$nameLower]['id'];
            if ($entry['isShip']) {
                $shipTypeId = $typeId;
                $canonicalShipName = $resolvedMap[$nameLower]['name'];
            }

            if (!isset($mergedQuantities[$typeId])) {
                $mergedQuantities[$typeId] = 0;
            }
            $mergedQuantities[$typeId] += $entry['quantity'];
        }

        $items = [];
        $totalVolume = 0.0;
        $totalItemCount = 0;

        foreach ($mergedQuantities as $typeId => $qty) {
            $details = $detailsMap[$typeId] ?? null;
            if (!$details) {
                continue;
            }

            $unitVolume = (float)$details['packagedVolume'];
            $itemTotalVolume = $unitVolume * $qty;
            $totalVolume += $itemTotalVolume;
            $totalItemCount += $qty;

            $slot = $details['slot'] ?? 'other';
            $catId = (int)($details['categoryId'] ?? 0);

            // Compute sort order
            $sortOrder = 100;
            if ($isFitting) {
                $sortOrder = self::FITTING_SLOT_ORDER[$slot] ?? 99;
            } else {
                $sortOrder = self::CATEGORY_ORDER[$catId] ?? 99;
            }

            $items[] = [
                'typeId' => $typeId,
                'name' => $details['name'],
                'quantity' => $qty,
                'volume' => (float)$details['volume'],
                'packagedVolume' => $unitVolume,
                'totalVolume' => round($itemTotalVolume, 2),
                'slot' => $slot,
                'categoryId' => $catId,
                'categoryName' => $details['categoryName'] ?? 'Sonstiges',
                'groupId' => (int)($details['groupId'] ?? 0),
                'groupName' => $details['groupName'] ?? '',
                'variation' => $details['variation'] ?? 'icon',
                'sortOrder' => $sortOrder,
            ];
        }

        // Sort items
        usort($items, function (array $a, array $b) use ($isFitting) {
            if ($a['sortOrder'] !== $b['sortOrder']) {
                return $a['sortOrder'] <=> $b['sortOrder'];
            }
            if (!$isFitting && $a['groupId'] !== $b['groupId']) {
                return strcmp($a['groupName'], $b['groupName']);
            }
            return strcmp($a['name'], $b['name']);
        });

        // Set default fit title if missing
        if ($isFitting && empty($fitTitle)) {
            $fitTitle = $canonicalShipName ?: ($rawShipName ?: 'Fit');
        }

        return [
            'isFitting' => $isFitting,
            'fitTitle' => $fitTitle,
            'shipName' => $canonicalShipName ?: $rawShipName,
            'shipTypeId' => $shipTypeId,
            'items' => $items,
            'unresolved' => array_values(array_unique($unresolved)),
            'totalVolume' => round($totalVolume, 2),
            'totalItemCount' => $totalItemCount,
        ];
    }

    /**
     * Cleans and extracts integer quantities from various numeric string formats (e.g. 50.000, 50,000, 50000).
     */
    private function _cleanQuantity(string $val): int
    {
        $cleaned = trim($val);
        // If string contains separators, clean them
        if (preg_match('/^\d{1,3}(?:[\.,\s]\d{3})+$/', $cleaned)) {
            $cleaned = preg_replace('/[^\d]/', '', $cleaned);
        } else {
            $cleaned = str_replace([' ', ','], '', $cleaned);
        }

        $intVal = (int)preg_replace('/[^\d]/', '', $cleaned);
        return max(1, $intVal);
    }
}
