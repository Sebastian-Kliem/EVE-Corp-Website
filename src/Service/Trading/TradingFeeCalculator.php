<?php

namespace App\Service\Trading;

use App\Entity\EveCharacter;

/**
 * Sales tax and broker fee at NPC stations from the trading character's skills; standings are not known and not included.
 */
class TradingFeeCalculator
{
    public const SKILL_ACCOUNTING = 16622;
    public const SKILL_BROKER_RELATIONS = 3446;

    private const BASE_SALES_TAX = 0.075;
    private const SALES_TAX_REDUCTION_PER_LEVEL = 0.11;
    private const BASE_BROKER_FEE = 0.03;
    private const BROKER_FEE_REDUCTION_PER_LEVEL = 0.003;

    /**
     * @return array{salesTax: float, brokerFee: float, accountingLevel: int, brokerRelationsLevel: int}
     */
    public function calculate(?EveCharacter $character): array
    {
        $accountingLevel = $character === null ? 0 : $this->_skillLevel($character, self::SKILL_ACCOUNTING);
        $brokerRelationsLevel = $character === null ? 0 : $this->_skillLevel($character, self::SKILL_BROKER_RELATIONS);

        return [
            'salesTax' => self::BASE_SALES_TAX * (1 - self::SALES_TAX_REDUCTION_PER_LEVEL * $accountingLevel),
            'brokerFee' => self::BASE_BROKER_FEE - self::BROKER_FEE_REDUCTION_PER_LEVEL * $brokerRelationsLevel,
            'accountingLevel' => $accountingLevel,
            'brokerRelationsLevel' => $brokerRelationsLevel,
        ];
    }

    private function _skillLevel(EveCharacter $character, int $skillId): int
    {
        $skills = $character->getSkills();
        // Stored as the ESI answer, older rows hold the plain skill list
        $skillList = $skills['skills'] ?? $skills;
        foreach ($skillList as $skill) {
            if ((int)($skill['skill_id'] ?? 0) === $skillId) {
                return (int)($skill['active_skill_level'] ?? $skill['trained_skill_level'] ?? 0);
            }
        }

        return 0;
    }
}
