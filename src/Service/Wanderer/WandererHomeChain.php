<?php

namespace App\Service\Wanderer;

/**
 * Determines which map systems are linked to the home system through wormholes.
 * Filament jumps and other connections outside that chain are no usable route for the corp.
 */
final class WandererHomeChain
{
    /**
     * Returns all systems reachable from the home system via wormhole connections only.
     *
     * @param array<int|string, array{0: int, 1: int, 2?: bool|int}> $connections [source, target, isWormhole]
     * @return array<int, true> system ID => true, always including the home system
     */
    public static function collectSystemIds(array $connections, int $homeSystemId): array
    {
        $neighboursBySystem = self::_buildWormholeAdjacency($connections);

        $chainSystemIds = [$homeSystemId => true];
        $systemsToVisit = [$homeSystemId];
        while ($systemsToVisit !== []) {
            $systemId = array_pop($systemsToVisit);
            foreach ($neighboursBySystem[$systemId] ?? [] as $neighbourId) {
                if (!isset($chainSystemIds[$neighbourId])) {
                    $chainSystemIds[$neighbourId] = true;
                    $systemsToVisit[] = $neighbourId;
                }
            }
        }

        return $chainSystemIds;
    }

    /**
     * Checks if a connection is potentially a wormhole connection (i.e. not a pure K-space stargate jump).
     *
     * @param array<string, mixed> $data connection as delivered by the Wanderer API
     */
    public static function isWormholeConnection(array $data): bool
    {
        $sourceId = isset($data['solar_system_source']) ? (int)$data['solar_system_source'] : 0;
        $targetId = isset($data['solar_system_target']) ? (int)$data['solar_system_target'] : 0;

        // 1. If Wanderer explicitly flags it as a stargate connection (type === 1), it is not a wormhole exit
        if (isset($data['type']) && ((int)$data['type'] === 1 || $data['type'] === 'stargate')) {
            return false;
        }

        // 2. If either side is a J-space / Wormhole system (EVE solarSystemID 31000000-31999999)
        $isSourceWh = ($sourceId >= 31000000 && $sourceId < 32000000);
        $isTargetWh = ($targetId >= 31000000 && $targetId < 32000000);

        if ($isSourceWh || $isTargetWh) {
            return true;
        }

        // 3. K-space to K-space only with a scanned wormhole type; the tracker marks every non-gate jump (filaments, Pochven) as type 0
        return !empty($data['wormhole_type']);
    }

    /**
     * @param array<int|string, array{0: int, 1: int, 2?: bool|int}> $connections
     * @return array<int, int[]>
     */
    private static function _buildWormholeAdjacency(array $connections): array
    {
        $neighboursBySystem = [];
        foreach ($connections as $connection) {
            // Entries stored before the wormhole flag existed count as wormholes
            $isWormhole = (bool)($connection[2] ?? true);
            if (!$isWormhole) {
                continue;
            }

            $sourceId = (int)$connection[0];
            $targetId = (int)$connection[1];
            $neighboursBySystem[$sourceId][] = $targetId;
            $neighboursBySystem[$targetId][] = $sourceId;
        }

        return $neighboursBySystem;
    }
}
