<?php

namespace App\Service\Fitting;

/**
 * Content hash of a fit, independent of slot order, item order and name.
 */
class FittingHasher
{
    /**
     * @param list<array{typeId: int, flag: string, quantity: int}> $items
     */
    public function hash(int $shipTypeId, array $items): string
    {
        $quantities = [];
        foreach ($items as $item) {
            $key = FittingSlots::groupOf($item['flag']) . ':' . $item['typeId'];
            $quantities[$key] = ($quantities[$key] ?? 0) + $item['quantity'];
        }
        ksort($quantities);

        return hash('sha256', $shipTypeId . '|' . json_encode($quantities));
    }
}
