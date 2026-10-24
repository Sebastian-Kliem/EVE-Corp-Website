<?php

namespace App\Service\Market;

/**
 * The NPC trade hubs whose regional order books are kept up to date permanently.
 */
final class MarketHub
{
    public const JITA = 60003760;
    public const AMARR = 60008494;
    public const DODIXIE = 60011866;
    public const RENS = 60004588;
    public const HEK = 60005686;

    /** @var array<int, array{name: string, systemId: int, regionId: int}> Station ID => hub */
    public const HUBS = [
        self::JITA => ['name' => 'Jita IV - Moon 4', 'systemId' => 30000142, 'regionId' => 10000002],
        self::AMARR => ['name' => 'Amarr VIII (Oris)', 'systemId' => 30002187, 'regionId' => 10000043],
        self::DODIXIE => ['name' => 'Dodixie IX - Moon 20', 'systemId' => 30002659, 'regionId' => 10000032],
        self::RENS => ['name' => 'Rens VI - Moon 8', 'systemId' => 30002510, 'regionId' => 10000030],
        self::HEK => ['name' => 'Hek VIII - Moon 12', 'systemId' => 30002053, 'regionId' => 10000042],
    ];

    /**
     * @return int[]
     */
    public static function regionIds(): array
    {
        $regionIds = [];
        foreach (self::HUBS as $hub) {
            $regionIds[] = $hub['regionId'];
        }

        return $regionIds;
    }

    public static function isHubRegion(int $regionId): bool
    {
        return in_array($regionId, self::regionIds(), true);
    }
}
