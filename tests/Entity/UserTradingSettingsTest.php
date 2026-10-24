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

    public function testTradingSettingsAreStored(): void
    {
        $user = (new User())->setTradingCharacterId(2123584748)->setTradingRouteMode(User::TRADING_ROUTE_SECURE);

        $this->assertSame(2123584748, $user->getTradingCharacterId());
        $this->assertSame(User::TRADING_ROUTE_SECURE, $user->getTradingRouteMode());
    }
}
