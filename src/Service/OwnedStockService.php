<?php

namespace App\Service;

use App\Entity\EveCharacter;
use App\Entity\EveCharacterAsset;
use App\Entity\EveCorporationAsset;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Collects how many items of given types a user owns and where, from all of the user's
 * characters and the corp hangars/containers the user marked as personal.
 */
class OwnedStockService
{
    // Fitted modules, loaded charges and implants cannot be handed over without stripping a ship or clone
    private const EXCLUDED_FLAG_PREFIXES = ['HiSlot', 'MedSlot', 'LoSlot', 'RigSlot', 'SubSystemSlot', 'ServiceSlot', 'Implant', 'Booster', 'Skill'];

    // Maximum container nesting that is walked up to find the root location
    private const MAX_NESTING_DEPTH = 10;

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly LocationService $locationService,
        private readonly SdeService $sdeService,
        private readonly PersonalCorpAssetService $personalCorpAssetService
    ) {}

    /**
     * @param int[] $typeIds
     * @return array<int, array{total: int, locations: list<array{location: string, system: ?string, path: string, owner: string, quantity: int}>}>
     */
    public function getOwnedStock(User $user, array $typeIds): array
    {
        $typeIds = array_values(array_unique(array_filter($typeIds)));
        if ($typeIds === []) {
            return [];
        }

        $characters = $this->entityManager->getRepository(EveCharacter::class)->findBy(['user' => $user]);
        if ($characters === []) {
            return [];
        }

        $entries = [];
        $this->_collectCharacterStock($characters, $typeIds, $entries);
        $this->_collectPersonalCorpStock($user, $characters, $typeIds, $entries);

        return $this->_groupEntries($entries);
    }

    /**
     * @param EveCharacter[] $characters
     * @param int[] $typeIds
     * @param list<array{typeId: int, location: string, system: ?string, path: string, owner: string, quantity: int}> $entries
     */
    private function _collectCharacterStock(array $characters, array $typeIds, array &$entries): void
    {
        $assets = $this->entityManager->getRepository(EveCharacterAsset::class)->createQueryBuilder('a')
            ->where('a.character IN (:characters)')
            ->andWhere('a.typeId IN (:typeIds)')
            ->andWhere('a.locationType NOT IN (:virtualTypes)')
            ->setParameter('characters', $characters)
            ->setParameter('typeIds', $typeIds)
            // Industry inputs are virtual, personal corp items are read from the corp assets below
            ->setParameter('virtualTypes', ['industry_job', 'personal_corp_asset'])
            ->getQuery()
            ->getResult();

        $parentsByItemId = $this->_loadCharacterParents($characters, $assets);

        foreach ($assets as $asset) {
            if ($this->_isExcludedFlag($asset->getLocationFlag())) {
                continue;
            }

            $character = $asset->getCharacter();
            $containerNames = [];
            $rootLocationId = $asset->getLocationId();
            $depth = 0;
            while (isset($parentsByItemId[$rootLocationId]) && $depth < self::MAX_NESTING_DEPTH) {
                $parent = $parentsByItemId[$rootLocationId];
                array_unshift($containerNames, $this->_containerName($parent->getTypeId(), $parent->getCustomName()));
                $rootLocationId = $parent->getLocationId();
                $depth++;
            }

            $entries[] = $this->_buildEntry(
                (int)$asset->getTypeId(),
                $this->locationService->resolveLocation($rootLocationId, $character),
                $this->_formatPath($containerNames, (bool)$asset->isBlueprintCopy()),
                (string)$character?->getName(),
                (int)$asset->getQuantity()
            );
        }
    }

    /**
     * Loads all containers/ships the given assets are nested in.
     *
     * @param EveCharacter[] $characters
     * @param EveCharacterAsset[] $assets
     * @return array<int, EveCharacterAsset>
     */
    private function _loadCharacterParents(array $characters, array $assets): array
    {
        $parentsByItemId = [];
        $pendingIds = [];
        foreach ($assets as $asset) {
            $pendingIds[$asset->getLocationId()] = true;
        }

        $repository = $this->entityManager->getRepository(EveCharacterAsset::class);
        for ($depth = 0; $depth < self::MAX_NESTING_DEPTH && $pendingIds !== []; $depth++) {
            $parents = $repository->createQueryBuilder('a')
                ->where('a.character IN (:characters)')
                ->andWhere('a.itemId IN (:itemIds)')
                ->setParameter('characters', $characters)
                ->setParameter('itemIds', array_keys($pendingIds))
                ->getQuery()
                ->getResult();

            $pendingIds = [];
            foreach ($parents as $parent) {
                if (isset($parentsByItemId[$parent->getItemId()])) {
                    continue;
                }
                $parentsByItemId[$parent->getItemId()] = $parent;
                $pendingIds[$parent->getLocationId()] = true;
            }
        }

        return $parentsByItemId;
    }

    /**
     * @param EveCharacter[] $characters
     * @param int[] $typeIds
     * @param list<array{typeId: int, location: string, system: ?string, path: string, owner: string, quantity: int}> $entries
     */
    private function _collectPersonalCorpStock(User $user, array $characters, array $typeIds, array &$entries): void
    {
        $personalHangars = $user->getPersonalCorpHangars();
        $personalContainers = $user->getPersonalCorpContainers();
        if ($personalHangars === [] && $personalContainers === []) {
            return;
        }

        $wantedTypeIds = array_flip($typeIds);
        foreach ($this->_charactersByCorporation($characters) as $corporationId => $character) {
            $corpAssets = $this->entityManager->getRepository(EveCorporationAsset::class)->findBy(['corporationId' => $corporationId]);
            $resolved = $this->personalCorpAssetService->resolvePersonalCorpAssets($corporationId, $corpAssets, $personalHangars, $personalContainers);

            foreach ($resolved['roots'] as $root) {
                $resolvedLocation = $this->locationService->resolveLocation($root->getLocationId(), $character);
                $rootLabel = $this->_corpHangarLabel($root->getLocationFlag());
                $this->_collectCorpBranch($root, [], $resolved['nested'], $wantedTypeIds, $resolvedLocation, $rootLabel, $entries);
            }
        }
    }

    /**
     * Walks a personal corp asset and everything nested in it.
     *
     * @param string[] $containerNames
     * @param array<int, array<int, EveCorporationAsset>> $nested
     * @param array<int, int> $wantedTypeIds
     * @param array<string, mixed> $resolvedLocation
     * @param list<array{typeId: int, location: string, system: ?string, path: string, owner: string, quantity: int}> $entries
     */
    private function _collectCorpBranch(EveCorporationAsset $asset, array $containerNames, array $nested, array $wantedTypeIds, array $resolvedLocation, string $rootLabel, array &$entries): void
    {
        if ($this->_isExcludedFlag($asset->getLocationFlag())) {
            return;
        }

        if (isset($wantedTypeIds[$asset->getTypeId()])) {
            $entries[] = $this->_buildEntry(
                (int)$asset->getTypeId(),
                $resolvedLocation,
                $this->_formatPath($containerNames, (bool)$asset->isBlueprintCopy()),
                $rootLabel,
                (int)$asset->getQuantity()
            );
        }

        if (count($containerNames) >= self::MAX_NESTING_DEPTH) {
            return;
        }

        $childContainerNames = $containerNames;
        $childContainerNames[] = $this->_containerName($asset->getTypeId(), $asset->getCustomName());
        foreach ($nested[$asset->getItemId()] ?? [] as $child) {
            $this->_collectCorpBranch($child, $childContainerNames, $nested, $wantedTypeIds, $resolvedLocation, $rootLabel, $entries);
        }
    }

    /**
     * One character per player corporation (the lowest ID), used to resolve structure names.
     *
     * @param EveCharacter[] $characters
     * @return array<int, EveCharacter>
     */
    private function _charactersByCorporation(array $characters): array
    {
        $charactersByCorporation = [];
        foreach ($characters as $character) {
            $corporationId = $character->getCorporationId();
            if ($corporationId === null || $character->isInNpcCorporation()) {
                continue;
            }
            if (!isset($charactersByCorporation[$corporationId]) || $character->getId() < $charactersByCorporation[$corporationId]->getId()) {
                $charactersByCorporation[$corporationId] = $character;
            }
        }

        return $charactersByCorporation;
    }

    /**
     * Sums entries with the same type, location, path and owner.
     *
     * @param list<array{typeId: int, location: string, system: ?string, path: string, owner: string, quantity: int}> $entries
     * @return array<int, array{total: int, locations: list<array{location: string, system: ?string, path: string, owner: string, quantity: int}>}>
     */
    private function _groupEntries(array $entries): array
    {
        $grouped = [];
        foreach ($entries as $entry) {
            $typeId = $entry['typeId'];
            $key = $entry['location'] . '|' . $entry['path'] . '|' . $entry['owner'];
            unset($entry['typeId']);

            if (!isset($grouped[$typeId])) {
                $grouped[$typeId] = ['total' => 0, 'locations' => []];
            }
            $grouped[$typeId]['total'] += $entry['quantity'];

            if (isset($grouped[$typeId]['locations'][$key])) {
                $grouped[$typeId]['locations'][$key]['quantity'] += $entry['quantity'];
            } else {
                $grouped[$typeId]['locations'][$key] = $entry;
            }
        }

        foreach ($grouped as $typeId => $stock) {
            $locations = array_values($stock['locations']);
            usort($locations, fn(array $first, array $second) => $second['quantity'] <=> $first['quantity']);
            $grouped[$typeId]['locations'] = $locations;
        }

        return $grouped;
    }

    /**
     * @param array<string, mixed> $resolvedLocation
     * @return array{typeId: int, location: string, system: ?string, path: string, owner: string, quantity: int}
     */
    private function _buildEntry(int $typeId, array $resolvedLocation, string $path, string $owner, int $quantity): array
    {
        return [
            'typeId' => $typeId,
            'location' => (string)($resolvedLocation['name'] ?? 'Unbekannter Ort'),
            'system' => isset($resolvedLocation['systemName']) ? (string)$resolvedLocation['systemName'] : null,
            'path' => $path,
            'owner' => $owner,
            'quantity' => $quantity,
        ];
    }

    private function _isExcludedFlag(?string $locationFlag): bool
    {
        foreach (self::EXCLUDED_FLAG_PREFIXES as $prefix) {
            if ($locationFlag !== null && str_starts_with($locationFlag, $prefix)) {
                return true;
            }
        }

        return false;
    }

    private function _containerName(int $typeId, ?string $customName): string
    {
        $typeName = $this->sdeService->getItemName($typeId);
        if ($customName !== null && trim($customName) !== '') {
            return sprintf('%s (%s)', trim($customName), $typeName);
        }

        return $typeName;
    }

    /**
     * @param string[] $containerNames
     */
    private function _formatPath(array $containerNames, bool $isBlueprintCopy): string
    {
        $path = implode(' > ', $containerNames);
        if ($isBlueprintCopy) {
            $path = $path === '' ? 'Kopie (BPC)' : $path . ' (Kopie, BPC)';
        }

        return $path;
    }

    private function _corpHangarLabel(?string $locationFlag): string
    {
        if ($locationFlag !== null && preg_match('/^CorpSAG(\d)$/', $locationFlag, $matches)) {
            return sprintf('Corp-Hangar %d (persönlich)', (int)$matches[1]);
        }

        return 'Corp-Hangar (persönlich)';
    }
}
