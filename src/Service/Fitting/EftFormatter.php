<?php

namespace App\Service\Fitting;

use App\Entity\Fitting\Fitting;
use App\Service\SdeService;

/**
 * Renders a fitting as EFT text for copy & paste into the game or Pyfa.
 */
class EftFormatter
{
    public function __construct(
        private readonly SdeService $sdeService,
    ) {}

    public function format(Fitting $fitting): string
    {
        $lines = [sprintf('[%s, %s]', $this->sdeService->getItemName($fitting->getShipTypeId()), $fitting->getName())];

        $fittedBySlotGroup = [];
        $bayLines = [FittingSlots::DRONE_BAY => [], FittingSlots::FIGHTER_BAY => [], FittingSlots::CARGO => []];
        foreach ($fitting->getItems() as $item) {
            $itemName = $this->sdeService->getItemName($item['typeId']);
            if (FittingSlots::isFitted($item['flag'])) {
                $fittedBySlotGroup[FittingSlots::groupOf($item['flag'])][FittingSlots::slotIndex($item['flag'])] = $itemName;
                continue;
            }
            $bay = isset($bayLines[$item['flag']]) ? $item['flag'] : FittingSlots::CARGO;
            $bayLines[$bay][] = sprintf('%s x%d', $itemName, $item['quantity']);
        }

        foreach (array_keys(FittingSlots::FITTED_SLOT_PREFIXES) as $slotGroup) {
            $modules = $fittedBySlotGroup[$slotGroup] ?? [];
            if (empty($modules)) {
                continue;
            }
            ksort($modules);
            $lines[] = '';
            foreach ($modules as $moduleName) {
                $lines[] = $moduleName;
            }
        }

        $lines[] = '';
        foreach ($bayLines as $bayItemLines) {
            if (empty($bayItemLines)) {
                continue;
            }
            $lines[] = '';
            foreach ($bayItemLines as $bayItemLine) {
                $lines[] = $bayItemLine;
            }
        }

        return rtrim(implode("\n", $lines)) . "\n";
    }
}
