<?php

namespace App\Service\Fitting;

use App\Service\SdeService;

/**
 * Turns EFT text into ESI fitting content (ship type and flagged items) using the SDE.
 */
class FittingContentBuilder
{
    public function __construct(
        private readonly EftParser $eftParser,
        private readonly SdeService $sdeService,
    ) {}

    /**
     * @return array{shipTypeId: int, shipName: string, name: ?string, items: list<array{typeId: int, flag: string, quantity: int}>, warnings: list<string>}
     * @throws FittingBuildException
     */
    public function buildFromEft(string $eft): array
    {
        $parsed = $this->eftParser->parse($eft);
        if ($parsed['shipName'] === null) {
            throw new FittingBuildException('Die erste Zeile muss das Schiff enthalten, z. B. "[Loki, Mein Fit]".');
        }

        $resolvedNames = $this->sdeService->resolveItemNames($this->_collectNames($parsed));
        $ship = $resolvedNames[strtolower($parsed['shipName'])] ?? null;
        if ($ship === null || !$this->sdeService->isShip($ship['id'])) {
            throw new FittingBuildException(sprintf('Schiff "%s" wurde in der SDE nicht gefunden.', $parsed['shipName']));
        }

        $typeIds = [];
        foreach ($resolvedNames as $resolved) {
            $typeIds[] = $resolved['id'];
        }
        $details = $this->sdeService->getItemsDetails($typeIds);

        $warnings = [];
        $items = $this->_buildItems($parsed['lines'], $resolvedNames, $details, $warnings);

        return [
            'shipTypeId' => $ship['id'],
            'shipName' => $ship['name'],
            'name' => $parsed['name'],
            'items' => $items,
            'warnings' => $warnings,
        ];
    }

    /**
     * @return list<string>
     */
    private function _collectNames(array $parsed): array
    {
        $names = [$parsed['shipName']];
        foreach ($parsed['lines'] as $line) {
            $names[] = $line['name'];
            if ($line['charge'] !== null) {
                $names[] = $line['charge'];
            }
        }

        return $names;
    }

    /**
     * @param list<string> $warnings
     * @return list<array{typeId: int, flag: string, quantity: int}>
     */
    private function _buildItems(array $lines, array $resolvedNames, array $details, array &$warnings): array
    {
        $nextSlotIndex = [];
        $fittedItems = [];
        $bayQuantities = [];

        foreach ($lines as $line) {
            $resolved = $resolvedNames[strtolower($line['name'])] ?? null;
            if ($resolved === null) {
                $warnings[] = sprintf('Unbekanntes Item übersprungen: %s', $line['name']);
                continue;
            }

            $typeId = $resolved['id'];
            $slotGroup = $details[$typeId]['slot'] ?? 'cargo';
            $prefix = FittingSlots::FITTED_SLOT_PREFIXES[$slotGroup] ?? null;

            // Fitted modules have no quantity suffix; everything else goes to a bay
            if ($prefix !== null && !$line['hasQuantity']) {
                $slotIndex = $nextSlotIndex[$prefix] ?? 0;
                $nextSlotIndex[$prefix] = $slotIndex + 1;
                $fittedItems[] = ['typeId' => $typeId, 'flag' => $prefix . $slotIndex, 'quantity' => 1];
                $this->_addLoadedCharge($line, $resolvedNames, $bayQuantities, $warnings);
                continue;
            }

            $flag = match ($slotGroup) {
                'drone' => FittingSlots::DRONE_BAY,
                'fighter' => FittingSlots::FIGHTER_BAY,
                default => FittingSlots::CARGO,
            };
            $bayQuantities[$flag][$typeId] = ($bayQuantities[$flag][$typeId] ?? 0) + $line['quantity'];
        }

        return array_merge($fittedItems, $this->_flattenBays($bayQuantities));
    }

    /**
     * ESI fittings have no loaded charges, so they are carried in the cargo.
     *
     * @param list<string> $warnings
     */
    private function _addLoadedCharge(array $line, array $resolvedNames, array &$bayQuantities, array &$warnings): void
    {
        if ($line['charge'] === null) {
            return;
        }

        $charge = $resolvedNames[strtolower($line['charge'])] ?? null;
        if ($charge === null) {
            $warnings[] = sprintf('Unbekannte Ladung übersprungen: %s', $line['charge']);
            return;
        }

        $chargeTypeId = $charge['id'];
        $bayQuantities[FittingSlots::CARGO][$chargeTypeId] = ($bayQuantities[FittingSlots::CARGO][$chargeTypeId] ?? 0) + 1;
    }

    /**
     * @return list<array{typeId: int, flag: string, quantity: int}>
     */
    private function _flattenBays(array $bayQuantities): array
    {
        $items = [];
        foreach ([FittingSlots::DRONE_BAY, FittingSlots::FIGHTER_BAY, FittingSlots::CARGO] as $flag) {
            foreach ($bayQuantities[$flag] ?? [] as $typeId => $quantity) {
                $items[] = ['typeId' => (int) $typeId, 'flag' => $flag, 'quantity' => $quantity];
            }
        }

        return $items;
    }
}
