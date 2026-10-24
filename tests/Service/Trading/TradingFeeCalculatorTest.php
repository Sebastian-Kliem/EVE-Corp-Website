<?php

namespace App\Tests\Service\Trading;

use App\Entity\EveCharacter;
use App\Service\Trading\TradingFeeCalculator;
use PHPUnit\Framework\TestCase;

class TradingFeeCalculatorTest extends TestCase
{
    public function testFeesWithoutCharacterUseTheBaseRates(): void
    {
        $fees = (new TradingFeeCalculator())->calculate(null);

        $this->assertEqualsWithDelta(0.075, $fees['salesTax'], 0.00001);
        $this->assertEqualsWithDelta(0.03, $fees['brokerFee'], 0.00001);
    }

    public function testFeesShrinkWithTheSkills(): void
    {
        $character = new EveCharacter();
        $character->setSkills(['skills' => [
            ['skill_id' => TradingFeeCalculator::SKILL_ACCOUNTING, 'active_skill_level' => 5, 'trained_skill_level' => 5],
            ['skill_id' => TradingFeeCalculator::SKILL_BROKER_RELATIONS, 'active_skill_level' => 4, 'trained_skill_level' => 4],
        ]]);

        $fees = (new TradingFeeCalculator())->calculate($character);

        $this->assertEqualsWithDelta(0.03375, $fees['salesTax'], 0.00001);
        $this->assertEqualsWithDelta(0.018, $fees['brokerFee'], 0.00001);
        $this->assertSame(5, $fees['accountingLevel']);
        $this->assertSame(4, $fees['brokerRelationsLevel']);
    }
}
