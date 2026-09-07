<?php

namespace App\Service;

use App\Entity\EveCorporationAsset;

class PersonalCorpAssetService
{
    /**
     * Resolves the corporation assets hierarchy and returns the top-level personal root assets.
     * Prevents double-counting by ensuring that containers inside an already selected personal hangar
     * or inside another selected personal container are not added as duplicate root nodes.
     *
     * @param int $corpId
     * @param EveCorporationAsset[] $corpAssets
     * @param array $personalHangars
     * @param array $personalContainers
     * @return array{
     *     roots: array<int, EveCorporationAsset>,
     *     byItemId: array<int, EveCorporationAsset>,
     *     nested: array<int, array<int, EveCorporationAsset>>
     * }
     */
    public function resolvePersonalCorpAssets(
        int $corpId,
        array $corpAssets,
        array $personalHangars,
        array $personalContainers
    ): array {
        $byItemId = [];
        foreach ($corpAssets as $asset) {
            $byItemId[$asset->getItemId()] = $asset;
        }

        $nested = [];
        foreach ($corpAssets as $asset) {
            $parentId = $asset->getLocationId();
            if (isset($byItemId[$parentId])) {
                $nested[$parentId][] = $asset;
            }
        }

        $personalRoots = [];

        // 1. Hangars: Collect all top-level assets directly located in the configured personal hangars
        foreach ($personalHangars as $h) {
            if ((int)($h['corporationId'] ?? 0) === $corpId) {
                $locId = (int)($h['locationId'] ?? 0);
                $flag = $h['locationFlag'] ?? '';
                foreach ($corpAssets as $asset) {
                    if ($asset->getLocationId() === $locId && $asset->getLocationFlag() === $flag) {
                        $personalRoots[$asset->getItemId()] = $asset;
                    }
                }
            }
        }

        // 2. Containers: Add selected containers only if they are not already covered
        $selectedContainerItemIds = [];
        foreach ($personalContainers as $c) {
            if ((int)($c['corporationId'] ?? 0) === $corpId) {
                $selectedContainerItemIds[] = (int)($c['itemId'] ?? 0);
            }
        }

        foreach ($selectedContainerItemIds as $itemId) {
            if (!isset($byItemId[$itemId])) {
                continue;
            }

            $containerAsset = $byItemId[$itemId];

            // If already a root (e.g. directly in a selected hangar or duplicate in config), skip
            if (isset($personalRoots[$itemId])) {
                continue;
            }

            // If inside any selected personal hangar (directly or nested), skip
            if ($this->isInsidePersonalHangar($containerAsset, $personalHangars, $corpId, $byItemId)) {
                continue;
            }

            // If inside another selected personal container (nested), skip
            if ($this->isInsidePersonalContainer($containerAsset, $selectedContainerItemIds, $byItemId)) {
                continue;
            }

            $personalRoots[$itemId] = $containerAsset;
        }

        return [
            'roots' => $personalRoots,
            'byItemId' => $byItemId,
            'nested' => $nested,
        ];
    }

    /**
     * Checks if an asset is located directly or indirectly inside any of the configured personal hangars.
     *
     * @param EveCorporationAsset $asset
     * @param array $personalHangars
     * @param int $corpId
     * @param array<int, EveCorporationAsset> $byItemId
     * @return bool
     */
    public function isInsidePersonalHangar(
        EveCorporationAsset $asset,
        array $personalHangars,
        int $corpId,
        array $byItemId
    ): bool {
        $curr = $asset;
        $visited = [];

        while ($curr !== null && !isset($visited[$curr->getItemId()])) {
            $visited[$curr->getItemId()] = true;
            $locId = (int) $curr->getLocationId();
            $flag = (string) $curr->getLocationFlag();

            foreach ($personalHangars as $h) {
                if ((int)($h['corporationId'] ?? 0) === $corpId
                    && (int)($h['locationId'] ?? 0) === $locId
                    && ($h['locationFlag'] ?? '') === $flag
                ) {
                    return true;
                }
            }

            $curr = $byItemId[$locId] ?? null;
        }

        return false;
    }

    /**
     * Checks if an asset is located inside another selected personal container.
     *
     * @param EveCorporationAsset $asset
     * @param int[] $selectedContainerItemIds
     * @param array<int, EveCorporationAsset> $byItemId
     * @return bool
     */
    public function isInsidePersonalContainer(
        EveCorporationAsset $asset,
        array $selectedContainerItemIds,
        array $byItemId
    ): bool {
        $parentLocId = (int) $asset->getLocationId();
        $visited = [];

        while (isset($byItemId[$parentLocId]) && !isset($visited[$parentLocId])) {
            $visited[$parentLocId] = true;
            if (in_array($parentLocId, $selectedContainerItemIds, true)) {
                return true;
            }
            $parentLocId = (int) $byItemId[$parentLocId]->getLocationId();
        }

        return false;
    }
}
