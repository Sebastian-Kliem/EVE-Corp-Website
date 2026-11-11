<?php

namespace App\Service\Character;

use App\Entity\EveCharacter;
use App\Service\SdeService;

/**
 * Sums a character's trained skill points per in-game skill group, relative to the group's maximum.
 */
class SkillGroupProfileBuilder
{
    // Skill points needed for level V of a rank 1 skill
    private const LEVEL_FIVE_SKILL_POINTS = 256000;

    public function __construct(
        private readonly SdeService $sdeService
    ) {}

    /**
     * @return list<array{name: string, trainedSkillPoints: int, maxSkillPoints: int, percent: float, trainedSkillCount: int, maxedSkillCount: int, skillCount: int}>
     */
    public function build(EveCharacter $character): array
    {
        $skillCatalog = $this->sdeService->getSkillCatalog();
        $groups = $this->_createEmptyGroups($skillCatalog);

        foreach ($character->getSkills()['skills'] ?? [] as $skill) {
            $skillId = (int) ($skill['skill_id'] ?? 0);
            if (!isset($skillCatalog[$skillId])) {
                continue;
            }

            $groupId = $skillCatalog[$skillId]['groupId'];
            $maxSkillPoints = $this->_getMaxSkillPoints($skillCatalog[$skillId]['rank']);
            $trainedLevel = (int) ($skill['trained_skill_level'] ?? 0);

            $groups[$groupId]['trainedSkillPoints'] += min((int) ($skill['skillpoints_in_skill'] ?? 0), $maxSkillPoints);
            if ($trainedLevel > 0) {
                $groups[$groupId]['trainedSkillCount']++;
            }
            if ($trainedLevel >= 5) {
                $groups[$groupId]['maxedSkillCount']++;
            }
        }

        $result = [];
        foreach ($groups as $group) {
            $group['percent'] = $group['maxSkillPoints'] > 0 ? round($group['trainedSkillPoints'] / $group['maxSkillPoints'] * 100, 1) : 0.0;
            $result[] = $group;
        }
        usort($result, $this->_compareGroupNames(...));

        return $result;
    }

    // Chart scale rounded up to the next 20 %, so small characters still fill the chart
    public function getChartScaleMax(array $groups): float
    {
        $maxPercent = 0.0;
        foreach ($groups as $group) {
            $maxPercent = max($maxPercent, $group['percent']);
        }

        return (float) min(100, max(20, (int) ceil($maxPercent / 20) * 20));
    }

    private function _createEmptyGroups(array $skillCatalog): array
    {
        $groups = [];
        foreach ($skillCatalog as $catalogEntry) {
            $groupId = $catalogEntry['groupId'];
            $groups[$groupId] ??= [
                'name' => $catalogEntry['groupName'],
                'trainedSkillPoints' => 0,
                'maxSkillPoints' => 0,
                'percent' => 0.0,
                'trainedSkillCount' => 0,
                'maxedSkillCount' => 0,
                'skillCount' => 0,
            ];
            $groups[$groupId]['maxSkillPoints'] += $this->_getMaxSkillPoints($catalogEntry['rank']);
            $groups[$groupId]['skillCount']++;
        }

        return $groups;
    }

    private function _getMaxSkillPoints(float $rank): int
    {
        return (int) round(self::LEVEL_FIVE_SKILL_POINTS * $rank);
    }

    private function _compareGroupNames(array $left, array $right): int
    {
        return strcasecmp($left['name'], $right['name']);
    }
}
