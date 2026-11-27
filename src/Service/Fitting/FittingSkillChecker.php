<?php

namespace App\Service\Fitting;

use App\Entity\EveCharacter;
use App\Entity\Fitting\Fitting;
use App\Service\SdeService;

/**
 * Checks the direct skill requirements of hull, modules, drones and fighters (cargo is ignored).
 */
class FittingSkillChecker
{
    public function __construct(
        private readonly SdeService $sdeService,
    ) {}

    /**
     * @param Fitting[] $fittings
     * @return array<int, array<int, int>> Map of fittingId => [skillId => required level]
     */
    public function getRequirements(array $fittings): array
    {
        $typeIdsByFitting = [];
        $allTypeIds = [];
        foreach ($fittings as $fitting) {
            $typeIds = $this->_relevantTypeIds($fitting);
            $typeIdsByFitting[(int) $fitting->getId()] = $typeIds;
            foreach ($typeIds as $typeId) {
                $allTypeIds[$typeId] = $typeId;
            }
        }

        $requirementsByType = $this->sdeService->getRequiredSkills(array_values($allTypeIds));

        $requirementsByFitting = [];
        foreach ($typeIdsByFitting as $fittingId => $typeIds) {
            $requirementsByFitting[$fittingId] = $this->_mergeRequirements($typeIds, $requirementsByType);
        }

        return $requirementsByFitting;
    }

    /**
     * Trained (active) level per skill; null when the skills were never synced.
     *
     * @return array<int, int>|null
     */
    public function getSkillLevels(EveCharacter $character): ?array
    {
        $skillData = $character->getSkills();
        if (!isset($skillData['skills'])) {
            return null;
        }

        $levels = [];
        foreach ($skillData['skills'] as $skill) {
            $levels[(int) ($skill['skill_id'] ?? 0)] = (int) ($skill['active_skill_level'] ?? $skill['trained_skill_level'] ?? 0);
        }

        return $levels;
    }

    /**
     * @param array<int, int> $requirements skillId => required level
     * @param array<int, int> $skillLevels skillId => trained level
     * @return array<int, array{required: int, current: int}> Missing skills keyed by skillId
     */
    public function findMissingSkills(array $requirements, array $skillLevels): array
    {
        $missingSkills = [];
        foreach ($requirements as $skillId => $requiredLevel) {
            $currentLevel = $skillLevels[$skillId] ?? 0;
            if ($currentLevel < $requiredLevel) {
                $missingSkills[$skillId] = ['required' => $requiredLevel, 'current' => $currentLevel];
            }
        }

        return $missingSkills;
    }

    /**
     * @param Fitting[] $fittings
     * @param EveCharacter[] $characters
     * @return array<int, list<int>> Map of fittingId => IDs of characters able to fly it
     */
    public function findPilots(array $fittings, array $characters): array
    {
        $skillLevelsByCharacter = [];
        foreach ($characters as $character) {
            $skillLevels = $this->getSkillLevels($character);
            if ($skillLevels !== null) {
                $skillLevelsByCharacter[(int) $character->getId()] = $skillLevels;
            }
        }

        $pilotsByFitting = [];
        foreach ($this->getRequirements($fittings) as $fittingId => $requirements) {
            $pilotsByFitting[$fittingId] = [];
            foreach ($skillLevelsByCharacter as $characterId => $skillLevels) {
                if (empty($this->findMissingSkills($requirements, $skillLevels))) {
                    $pilotsByFitting[$fittingId][] = $characterId;
                }
            }
        }

        return $pilotsByFitting;
    }

    /**
     * @return list<int>
     */
    private function _relevantTypeIds(Fitting $fitting): array
    {
        $typeIds = [$fitting->getShipTypeId()];
        foreach ($fitting->getItems() as $item) {
            if ($item['flag'] !== FittingSlots::CARGO) {
                $typeIds[] = $item['typeId'];
            }
        }

        return array_values(array_unique($typeIds));
    }

    /**
     * @param list<int> $typeIds
     * @param array<int, array<int, int>> $requirementsByType
     * @return array<int, int>
     */
    private function _mergeRequirements(array $typeIds, array $requirementsByType): array
    {
        $merged = [];
        foreach ($typeIds as $typeId) {
            foreach ($requirementsByType[$typeId] ?? [] as $skillId => $level) {
                $merged[$skillId] = max($merged[$skillId] ?? 0, $level);
            }
        }

        return $merged;
    }
}
