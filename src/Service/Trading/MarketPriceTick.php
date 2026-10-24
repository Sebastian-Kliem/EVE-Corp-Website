<?php

namespace App\Service\Trading;

/**
 * EVE accepts order prices with at most four significant digits, the smallest step is 0.01 ISK.
 */
final class MarketPriceTick
{
    private const SIGNIFICANT_DIGITS = 4;

    /**
     * Highest valid price below the competitor's sell price.
     */
    public static function undercut(float $competitorPrice): float
    {
        $cents = self::_toCents($competitorPrice);
        if ($cents <= 1) {
            return 0.01;
        }
        // The step just below the price, 1000.00 drops to 999.90 instead of 999.00
        $step = self::_stepCents($cents - 1);

        return (intdiv($cents - 1, $step) * $step) / 100;
    }

    /**
     * Lowest valid price above the competitor's buy price.
     */
    public static function overbid(float $competitorPrice): float
    {
        $cents = self::_toCents($competitorPrice);
        $step = self::_stepCents($cents);

        return ((intdiv($cents, $step) + 1) * $step) / 100;
    }

    private static function _stepCents(int $cents): int
    {
        $digits = strlen((string)max(1, $cents));

        return (int)max(1, 10 ** ($digits - self::SIGNIFICANT_DIGITS));
    }

    private static function _toCents(float $price): int
    {
        return (int)round($price * 100);
    }
}
