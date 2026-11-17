<?php

namespace App\Tests\Entity;

use App\Entity\User;
use PHPUnit\Framework\TestCase;

class UserTradingSettingsTest extends TestCase
{
    public function testTradingDefaultsWithoutSettings(): void
    {
        $user = new User();

        $this->assertNull($user->getTradingCharacterId());
        $this->assertSame(User::TRADING_ROUTE_SHORTEST, $user->getTradingRouteMode());
    }

    public function testUnknownRouteModeFallsBackToShortest(): void
    {
        $user = (new User())->setTradingRouteMode('wormhole');

        $this->assertSame(User::TRADING_ROUTE_SHORTEST, $user->getTradingRouteMode());
    }

    public function testStationFilterDefaultsToAllWithoutEntries(): void
    {
        $this->assertSame(
            ['mode' => User::STATION_FILTER_ALL, 'marketGroupIds' => [], 'hiddenTypeIds' => []],
            (new User())->getStationTradingFilter()
        );
    }

    public function testStationFilterNormalizesIdsAndUnknownMode(): void
    {
        $user = (new User())->setStationTradingFilter(User::STATION_FILTER_EXCEPT, ['9', 9, 0, -3, 'abc', 11], [34, '34', 35]);

        $this->assertSame(
            ['mode' => User::STATION_FILTER_EXCEPT, 'marketGroupIds' => [9, 11], 'hiddenTypeIds' => [34, 35]],
            $user->getStationTradingFilter()
        );

        $user->setSetting(User::SETTING_TRADING_STATION_FILTER, ['mode' => 'everything']);
        $this->assertSame(User::STATION_FILTER_ALL, $user->getStationTradingFilter()['mode']);
    }

    public function testTradingSettingsAreStored(): void
    {
        $user = (new User())->setTradingCharacterId(2123584748)->setTradingRouteMode(User::TRADING_ROUTE_SECURE);

        $this->assertSame(2123584748, $user->getTradingCharacterId());
        $this->assertSame(User::TRADING_ROUTE_SECURE, $user->getTradingRouteMode());
    }
}
