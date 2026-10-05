<?php

namespace App\Service\Cron;

/**
 * Cron jobs run sequentially per lane; bin/cron-loop.sh runs one scheduler loop per lane.
 */
final class CronLanes
{
    public const DEFAULT = 'default';
    // Short, time-critical jobs that must not wait behind long syncs
    public const FAST = 'fast';

    private const FAST_LANE_COMMANDS = [
        'wanderer:sync-connections',
    ];

    public static function laneOf(string $command): string
    {
        return in_array($command, self::FAST_LANE_COMMANDS, true) ? self::FAST : self::DEFAULT;
    }

    public static function isValid(string $lane): bool
    {
        return $lane === self::DEFAULT || $lane === self::FAST;
    }
}
