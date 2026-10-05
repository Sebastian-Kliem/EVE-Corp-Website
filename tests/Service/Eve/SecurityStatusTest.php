<?php

namespace App\Tests\Service\Eve;

use App\Service\Eve\SecurityStatus;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class SecurityStatusTest extends TestCase
{
    /**
     * @return array<string, array{float, float, string}>
     */
    public static function securityProvider(): array
    {
        return [
            'Jita' => [0.945913, 0.9, SecurityStatus::HIGHSEC],
            'Iosantin, lowest highsec' => [0.450343, 0.5, SecurityStatus::HIGHSEC],
            'exact highsec boundary' => [0.45, 0.5, SecurityStatus::HIGHSEC],
            'Aunenen, just below highsec' => [0.44779, 0.4, SecurityStatus::LOWSEC],
            'just below 0.45' => [0.449999, 0.4, SecurityStatus::LOWSEC],
            'tiny positive shows 0.1' => [0.02, 0.1, SecurityStatus::LOWSEC],
            'smallest positive shows 0.1' => [0.0001, 0.1, SecurityStatus::LOWSEC],
            'exact 0.05' => [0.05, 0.1, SecurityStatus::LOWSEC],
            'exact zero is nullsec' => [0.0, 0.0, SecurityStatus::NULLSEC],
            'slightly negative shows 0.0' => [-0.04, 0.0, SecurityStatus::NULLSEC],
            'nullsec' => [-0.45, -0.5, SecurityStatus::NULLSEC],
            'wormhole' => [-0.99, -1.0, SecurityStatus::NULLSEC],
            'max highsec' => [1.0, 1.0, SecurityStatus::HIGHSEC],
        ];
    }

    #[DataProvider('securityProvider')]
    public function testDisplayAndClassificationMatchTheClient(float $trueSecurity, float $expectedDisplay, string $expectedClass): void
    {
        $this->assertSame($expectedDisplay, SecurityStatus::toDisplay($trueSecurity));
        $this->assertSame($expectedClass, SecurityStatus::classify($trueSecurity));
    }
}
