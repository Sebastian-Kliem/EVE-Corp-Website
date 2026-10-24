<?php

namespace App\Tests\Service\Trading;

use App\Service\Trading\MarketPriceTick;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class MarketPriceTickTest extends TestCase
{
    public static function undercutProvider(): array
    {
        return [
            'small price in cents' => [5.43, 5.42],
            'four digits' => [1234.00, 1233.00],
            'millions' => [1234000.00, 1233000.00],
            'drops into the finer range' => [1000.00, 999.90],
            'power of ten in millions' => [10000000.00, 9999000.00],
            'minimum' => [0.01, 0.01],
        ];
    }

    #[DataProvider('undercutProvider')]
    public function testUndercut(float $competitorPrice, float $expected): void
    {
        $this->assertEqualsWithDelta($expected, MarketPriceTick::undercut($competitorPrice), 0.001);
    }

    public static function overbidProvider(): array
    {
        return [
            'small price in cents' => [5.43, 5.44],
            'four digits' => [1234.00, 1235.00],
            'millions' => [1234000.00, 1235000.00],
            'rolls into the next range' => [9999.00, 10000.00],
            'below a power of ten' => [999.90, 1000.00],
        ];
    }

    #[DataProvider('overbidProvider')]
    public function testOverbid(float $competitorPrice, float $expected): void
    {
        $this->assertEqualsWithDelta($expected, MarketPriceTick::overbid($competitorPrice), 0.001);
    }
}
