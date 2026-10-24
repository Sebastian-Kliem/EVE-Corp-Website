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
    // Large market order book pulls must not delay the character syncs
    public const MARKET = 'market';

    private const FAST_LANE_COMMANDS = [
        'wanderer:sync-connections',
    ];

    private const MARKET_LANE_COMMANDS = [
        'market:sync-hub-orders',
    ];

    public static function laneOf(string $command): string
    {
        if (in_array($command, self::FAST_LANE_COMMANDS, true)) {
            return self::FAST;
        }
        if (in_array($command, self::MARKET_LANE_COMMANDS, true)) {
            return self::MARKET;
        }

        return self::DEFAULT;
    }

    public static function isValid(string $lane): bool
    {
        return in_array($lane, [self::DEFAULT, self::FAST, self::MARKET], true);
    }
}
