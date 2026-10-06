<?php

namespace App\Service\Assets;

use App\Entity\EveCharacterAsset;
use App\Entity\EveCorporationAsset;
use App\Service\JitaPriceService;
use App\Service\SdeService;

/**
 * Builds the nested asset trees (with prices) shown in the personal and corporation inventory views.
 */
class AssetTreeBuilder
{
    public function __construct(
        private readonly SdeService $sdeService,
        private readonly JitaPriceService $jitaPriceService
    ) {}

    public function buildCharacterAssetNode(EveCharacterAsset $asset, array $nestedAssets, array $prices): array
    {
        $itemId = $asset->getItemId();
        $typeId = $asset->getTypeId();
        $children = [];
        if (isset($nestedAssets[$itemId])) {
            foreach ($nestedAssets[$itemId] as $childAsset) {
                $children[] = $this->buildCharacterAssetNode($childAsset, $nestedAssets, $prices);
            }
            $children = $this->groupAndSortNodes($children, $itemId);
        }

        $price = $asset->isBlueprintCopy() ? 0.0 : ($prices[$typeId] ?? 0.0);
        if ($asset->getLocationFlag() === 'MarketOrder' && !$asset->isBlueprintCopy()) {
            try {
                $priceInfo = $this->jitaPriceService->getAverageJitaPrice($typeId, true);
                if ($priceInfo['price'] !== null) {
                    $price = (float)$priceInfo['price'];
                }
            } catch (\Exception $e) {
                // Keep default price
            }
        }

        return [
            'itemId' => $itemId,
            'typeId' => $typeId,
            'name' => $this->sdeService->getItemName($typeId),
            'customName' => $asset->getCustomName(),
            'quantity' => $asset->getQuantity(),
            'locationFlag' => $asset->getLocationFlag(),
            'isBlueprintCopy' => $asset->isBlueprintCopy(),
            'isBlueprint' => $this->sdeService->isBlueprint($typeId),
            'isSingleton' => $asset->isSingleton(),
            'price' => $price,
            'category' => $this->sdeService->getItemCategory($typeId),
            'materialEfficiency' => $asset->getMaterialEfficiency(),
            'timeEfficiency' => $asset->getTimeEfficiency(),
            'runs' => $asset->getRuns(),
            'children' => $children,
        ];
    }

    public function buildPersonalCorpAssetNode(EveCorporationAsset $asset, array $nestedAssets, array $prices): array
    {
        $itemId = $asset->getItemId();
        $typeId = $asset->getTypeId();
        $children = [];
        if (isset($nestedAssets[$itemId])) {
            foreach ($nestedAssets[$itemId] as $childAsset) {
                $children[] = $this->buildPersonalCorpAssetNode($childAsset, $nestedAssets, $prices);
            }
            $children = $this->groupAndSortNodes($children, $itemId);
        }

        return [
            'itemId' => $itemId,
            'typeId' => $typeId,
            'name' => $this->sdeService->getItemName($typeId),
            'customName' => $asset->getCustomName(),
            'quantity' => $asset->getQuantity(),
            'locationFlag' => $asset->getLocationFlag(),
            'isBlueprintCopy' => $asset->isBlueprintCopy() ?? false,
            'isBlueprint' => $this->sdeService->isBlueprint($typeId),
            'isSingleton' => $asset->isSingleton(),
            'price' => ($asset->isBlueprintCopy() ?? false) ? 0.0 : ($prices[$typeId] ?? 0.0),
            'category' => $this->sdeService->getItemCategory($typeId),
            'materialEfficiency' => $asset->getMaterialEfficiency(),
            'timeEfficiency' => $asset->getTimeEfficiency(),
            'runs' => $asset->getRuns(),
            'children' => $children,
        ];
    }

    public function calculateNodeValue(array $node): float
    {
        $val = ($node['price'] ?? 0.0) * ($node['quantity'] ?? 1);
        if (!empty($node['children'])) {
            foreach ($node['children'] as $child) {
                $val += $this->calculateNodeValue($child);
            }
        }
        return $val;
    }

    public function buildCorpAssetNode(
        EveCorporationAsset $asset,
        array $nestedAssets,
        array $divisionNames = [],
        bool $isCeoOrAdmin = true,
        array $visibilityMap = [],
        array $prices = []
    ): array {
        $itemId = $asset->getItemId();
        $typeId = $asset->getTypeId();

        $children = [];
        if (isset($nestedAssets[$itemId])) {
            $hasOffice = false;
            $officeAsset = null;
            foreach ($nestedAssets[$itemId] as $childAsset) {
                if ($childAsset->getTypeId() === 27) {
                    $hasOffice = true;
                    $officeAsset = $childAsset;
                }
            }

            if ($hasOffice && $officeAsset !== null) {
                // Bypass the office node completely: directly add the office's children (hangars/deliveries)
                $officeNode = $this->buildCorpAssetNode($officeAsset, $nestedAssets, $divisionNames, $isCeoOrAdmin, $visibilityMap, $prices);
                $children = $officeNode['children'];
            } else {
                foreach ($nestedAssets[$itemId] as $childAsset) {
                    // Filter out structure fittings, rigs, core, and fuel blocks
                    $flag = $childAsset->getLocationFlag();
                    if (in_array($flag, [
                        'QuantumCoreRoom',
                        'StructureFuel',
                        'StructureServiceSlot0',
                        'StructureServiceSlot1',
                        'StructureServiceSlot2',
                        'StructureServiceSlot3',
                        'StructureServiceSlot4',
                        'StructureServiceSlot5',
                        'StructureServiceSlot6',
                        'StructureServiceSlot7',
                        'RigSlot0',
                        'RigSlot1',
                        'RigSlot2',
                    ], true)) {
                        continue;
                    }

                    $children[] = $this->buildCorpAssetNode($childAsset, $nestedAssets, $divisionNames, $isCeoOrAdmin, $visibilityMap, $prices);
                }
            }

            // If this is an Office (typeId 27), group its children by division!
            if ($typeId === 27) {
                $getDivisionName = function (string $flag) use ($divisionNames) {
                    if (preg_match('/^CorpSAG(\d)$/', $flag, $matches)) {
                        $divIndex = (int) $matches[1];
                        return $divisionNames[$divIndex] ?? 'Hangar ' . $divIndex;
                    }
                    if ($flag === 'CorpDeliveries') {
                        return 'Lieferungen (Deliveries)';
                    }
                    return null;
                };

                $groupedByDiv = [];
                $nonDivChildren = [];

                foreach ($children as $child) {
                    $flag = $child['locationFlag'] ?? '';
                    $divName = $getDivisionName($flag);

                    if ($divName !== null) {
                        if (!$isCeoOrAdmin) {
                            $locId = $asset->getLocationId(); // Office's locationId is the structure ID
                            if (!isset($visibilityMap[$locId][$flag])) {
                                continue; // Hangar is not approved for normal users
                            }
                        }
                        $groupedByDiv[$divName][] = $child;
                    } else {
                        $nonDivChildren[] = $child;
                    }
                }

                foreach ($groupedByDiv as $divName => &$items) {
                    $items = $this->groupAndSortNodes($items, $itemId);
                }
                unset($items);

                $divNodes = [];
                $virtualIdCounter = 1;
                foreach ($groupedByDiv as $divName => $items) {
                    $divNodes[] = [
                        'itemId' => -($itemId * 10 + $virtualIdCounter++), // unique negative ID
                        'typeId' => 0, // special type ID for division folders
                        'name' => $divName,
                        'quantity' => count($items),
                        'locationFlag' => 'Division',
                        'isBlueprintCopy' => false,
                        'isSingleton' => false,
                        'children' => $items,
                    ];
                }

                usort($divNodes, function ($a, $b) use ($divisionNames) {
                    $getDivOrder = function ($name) use ($divisionNames) {
                        if ($name === 'Lieferungen (Deliveries)') {
                            return 8;
                        }
                        foreach ($divisionNames as $idx => $divName) {
                            if ($name === $divName) {
                                return $idx;
                            }
                        }
                        if (preg_match('/^Hangar (\d)$/', $name, $matches)) {
                            return (int) $matches[1];
                        }
                        return 99;
                    };
                    return $getDivOrder($a['name']) <=> $getDivOrder($b['name']);
                });

                $children = array_merge($divNodes, $nonDivChildren);
            } else {
                $children = $this->groupAndSortNodes($children, $itemId);
            }
        }

        return [
            'itemId' => $itemId,
            'typeId' => $typeId,
            'name' => $this->sdeService->getItemName($typeId),
            'customName' => $asset->getCustomName(),
            'quantity' => $asset->getQuantity(),
            'locationFlag' => $asset->getLocationFlag(),
            'isBlueprintCopy' => $asset->isBlueprintCopy(),
            'isBlueprint' => $this->sdeService->isBlueprint($typeId),
            'isSingleton' => $asset->isSingleton(),
            'price' => $asset->isBlueprintCopy() ? 0.0 : ($prices[$typeId] ?? 0.0),
            'category' => $this->sdeService->getItemCategory($typeId),
            'materialEfficiency' => $asset->getMaterialEfficiency(),
            'timeEfficiency' => $asset->getTimeEfficiency(),
            'runs' => $asset->getRuns(),
            'children' => $children,
        ];
    }

    /**
     * Groups and sorts assets.
     * 1. Ships are grouped under a virtual folder "Schiffe (Ships)".
     * 2. Containers with content are placed next.
     * 3. All other items are placed last.
     * Each sub-group is sorted alphabetically by name.
     */
    public function groupAndSortNodes(array $nodes, int $parentId): array
    {
        $shipNodes = [];
        $containerWithContentNodes = [];
        $otherNodes = [];

        foreach ($nodes as $node) {
            $typeId = $node['typeId'] ?? 0;
            $hasChildren = !empty($node['children']);

            if ($this->sdeService->isShip($typeId)) {
                $shipNodes[] = $node;
            } elseif ($this->sdeService->isContainer($typeId) && $hasChildren) {
                $containerWithContentNodes[] = $node;
            } else {
                $otherNodes[] = $node;
            }
        }

        // Sort each category alphabetically by name
        usort($shipNodes, function ($a, $b) {
            return strcasecmp($a['name'], $b['name']);
        });
        usort($containerWithContentNodes, function ($a, $b) {
            return strcasecmp($a['name'], $b['name']);
        });
        usort($otherNodes, function ($a, $b) {
            return strcasecmp($a['name'], $b['name']);
        });

        $finalNodes = [];

        // 1. Ships (under a virtual folder if there are any)
        if (count($shipNodes) > 0) {
            $finalNodes[] = [
                'itemId' => -((int)abs($parentId) * 10 + 9999), // unique virtual ID
                'typeId' => 0, // special type ID for virtual folder
                'name' => 'Schiffe (Ships)',
                'quantity' => count($shipNodes),
                'locationFlag' => 'Group',
                'isBlueprintCopy' => false,
                'isSingleton' => false,
                'children' => $shipNodes,
                'price' => 0.0,
            ];
        }

        // 2. Containers with content
        foreach ($containerWithContentNodes as $node) {
            $finalNodes[] = $node;
        }

        // 3. Other items
        foreach ($otherNodes as $node) {
            $finalNodes[] = $node;
        }

        return $finalNodes;
    }
}
