<?php

namespace App\Service\Trading;

/**
 * Purchased lots per item type, sales take the newest lots first (LIFO): the margin of each flip shows,
 * expensive old stock stays visible as an open position instead of spreading into every later sale.
 */
class TradingLotStock
{
    /** @var array<int, list<array{quantity: float, unitCost: float, unitFee: float, acquiredAt: int}>> */
    private array $lots = [];

    /**
     * @param float $unitFee Broker fee share per unit of the buy order, sold units carry it with them
     */
    public function add(int $typeId, float $quantity, float $unitCost, int $acquiredAt, float $unitFee = 0.0): void
    {
        if ($quantity <= 0) {
            return;
        }
        $this->lots[$typeId][] = ['quantity' => $quantity, 'unitCost' => $unitCost, 'unitFee' => $unitFee, 'acquiredAt' => $acquiredAt];
    }

    /**
     * Takes up to the given quantity from the newest lots.
     *
     * @return array{quantity: float, cost: float, fee: float, holdingSeconds: float}
     */
    public function take(int $typeId, float $quantity, int $timestamp): array
    {
        $taken = ['quantity' => 0.0, 'cost' => 0.0, 'fee' => 0.0, 'holdingSeconds' => 0.0];
        while ($quantity > 0 && !empty($this->lots[$typeId])) {
            $lastIndex = count($this->lots[$typeId]) - 1;
            $lot = $this->lots[$typeId][$lastIndex];
            $takenQuantity = min($quantity, $lot['quantity']);

            $taken['quantity'] += $takenQuantity;
            $taken['cost'] += $takenQuantity * $lot['unitCost'];
            $taken['fee'] += $takenQuantity * $lot['unitFee'];
            $taken['holdingSeconds'] += $takenQuantity * max(0, $timestamp - $lot['acquiredAt']);
            $quantity -= $takenQuantity;

            if ($takenQuantity < $lot['quantity']) {
                $this->lots[$typeId][$lastIndex]['quantity'] -= $takenQuantity;
            } else {
                array_pop($this->lots[$typeId]);
            }
        }

        return $taken;
    }

    /**
     * @return array<int, array{quantity: float, cost: float, oldestAcquiredAt: int}> Cost includes the fee shares
     */
    public function remaining(): array
    {
        $remaining = [];
        foreach ($this->lots as $typeId => $lots) {
            if ($lots === []) {
                continue;
            }
            $position = ['quantity' => 0.0, 'cost' => 0.0, 'oldestAcquiredAt' => $lots[0]['acquiredAt']];
            foreach ($lots as $lot) {
                $position['quantity'] += $lot['quantity'];
                $position['cost'] += $lot['quantity'] * ($lot['unitCost'] + $lot['unitFee']);
            }
            $remaining[$typeId] = $position;
        }

        return $remaining;
    }
}
