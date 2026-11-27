<?php

namespace App\Service\Fitting;

/**
 * ESI fitting flags and their slot groups.
 */
final class FittingSlots
{
    public const CARGO = 'Cargo';
    public const DRONE_BAY = 'DroneBay';
    public const FIGHTER_BAY = 'FighterBay';

    // Slot group => ESI flag prefix, in EFT section order
    public const FITTED_SLOT_PREFIXES = [
        'low' => 'LoSlot',
        'med' => 'MedSlot',
        'high' => 'HiSlot',
        'rig' => 'RigSlot',
        'subsystem' => 'SubSystemSlot',
    ];

    // Legacy numeric inventory flags => ESI flag name
    private const NUMERIC_FLAG_RANGES = [
        [11, 18, 'LoSlot'],
        [19, 26, 'MedSlot'],
        [27, 34, 'HiSlot'],
        [92, 94, 'RigSlot'],
        [125, 132, 'SubSystemSlot'],
        [164, 171, 'ServiceSlot'],
    ];

    private const NUMERIC_SINGLE_FLAGS = [5 => self::CARGO, 87 => self::DRONE_BAY, 158 => self::FIGHTER_BAY];

    /**
     * Slot group of a flag ("high", "drone", "cargo", ...).
     */
    public static function groupOf(string $flag): string
    {
        foreach (self::FITTED_SLOT_PREFIXES as $group => $prefix) {
            if (str_starts_with($flag, $prefix)) {
                return $group;
            }
        }
        if ($flag === self::DRONE_BAY) {
            return 'drone';
        }
        if ($flag === self::FIGHTER_BAY) {
            return 'fighter';
        }
        if (str_starts_with($flag, 'ServiceSlot')) {
            return 'service';
        }

        return 'cargo';
    }

    public static function isFitted(string $flag): bool
    {
        return isset(self::FITTED_SLOT_PREFIXES[self::groupOf($flag)]);
    }

    public static function slotIndex(string $flag): int
    {
        return preg_match('/(\d+)$/', $flag, $matches) ? (int) $matches[1] : 0;
    }

    /**
     * Normalizes ESI flags, which older API versions return as inventory flag IDs.
     */
    public static function normalize(string|int $flag): string
    {
        if (!is_int($flag) && !ctype_digit($flag)) {
            return $flag;
        }

        $flagId = (int) $flag;
        if (isset(self::NUMERIC_SINGLE_FLAGS[$flagId])) {
            return self::NUMERIC_SINGLE_FLAGS[$flagId];
        }
        foreach (self::NUMERIC_FLAG_RANGES as [$first, $last, $prefix]) {
            if ($flagId >= $first && $flagId <= $last) {
                return $prefix . ($flagId - $first);
            }
        }

        return self::CARGO;
    }
}
