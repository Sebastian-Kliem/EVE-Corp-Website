<?php

namespace App\Service\Eve;

/**
 * Security status exactly as the EVE client shows it, derived from the true value in the SDE.
 */
final class SecurityStatus
{
    public const HIGHSEC = 'highsec';
    public const LOWSEC = 'lowsec';
    public const NULLSEC = 'nullsec';

    /**
     * Rounds to one decimal like the client; true values between 0.0 and 0.05 are shown as 0.1.
     */
    public static function toDisplay(float $trueSecurity): float
    {
        if ($trueSecurity > 0.0 && $trueSecurity < 0.05) {
            return 0.1;
        }

        $display = round($trueSecurity, 1, PHP_ROUND_HALF_UP);

        // Avoid "-0.0" for values slightly below zero
        return $display == 0.0 ? 0.0 : $display;
    }

    public static function classify(float $trueSecurity): string
    {
        $display = self::toDisplay($trueSecurity);

        if ($display >= 0.5) {
            return self::HIGHSEC;
        }
        if ($display > 0.0) {
            return self::LOWSEC;
        }

        return self::NULLSEC;
    }

    public static function isHighsec(float $trueSecurity): bool
    {
        return self::classify($trueSecurity) === self::HIGHSEC;
    }

    public static function isHighOrLowsec(float $trueSecurity): bool
    {
        return self::classify($trueSecurity) !== self::NULLSEC;
    }
}
