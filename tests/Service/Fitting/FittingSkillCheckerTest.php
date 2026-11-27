<?php

namespace App\Tests\Service\Fitting;

use App\Entity\EveCharacter;
use App\Entity\Fitting\Fitting;
use App\Service\Fitting\FittingSkillChecker;
use App\Service\SdeService;
use PHPUnit\Framework\TestCase;

class FittingSkillCheckerTest extends TestCase
{
    private const SHIP = 587;
    private const GUN = 2889;
    private const AMMO = 21894;
    private const FRIGATE_SKILL = 3329;
    private const GUNNERY_SKILL = 3300;
    private const AMMO_SKILL = 9999;

    public function testPilotsNeedHighestRequiredLevelOfHullAndModulesButNotCargo(): void
    {
        $fitting = $this->_createFitting(1);
        $checker = new FittingSkillChecker($this->_createSdeService());

        $pilots = $checker->findPilots([$fitting], [
            $this->_createCharacter(10, [self::FRIGATE_SKILL => 3, self::GUNNERY_SKILL => 4]),
            $this->_createCharacter(11, [self::FRIGATE_SKILL => 3, self::GUNNERY_SKILL => 3]),
            $this->_createCharacter(12, null),
        ]);

        $this->assertSame([1 => [10]], $pilots);
    }

    public function testMissingSkillsReportRequiredAndCurrentLevel(): void
    {
        $checker = new FittingSkillChecker($this->_createSdeService());
        $requirements = $checker->getRequirements([$this->_createFitting(1)])[1];

        $this->assertSame([self::FRIGATE_SKILL => 1, self::GUNNERY_SKILL => 4], $requirements);
        $this->assertSame(
            [self::GUNNERY_SKILL => ['required' => 4, 'current' => 2]],
            $checker->findMissingSkills($requirements, [self::FRIGATE_SKILL => 5, self::GUNNERY_SKILL => 2])
        );
    }

    public function testUnsyncedSkillsAreUnknown(): void
    {
        $checker = new FittingSkillChecker($this->_createSdeService());

        $this->assertNull($checker->getSkillLevels(new EveCharacter()));
    }

    private function _createSdeService(): SdeService
    {
        $sdeService = $this->createStub(SdeService::class);
        $sdeService->method('getRequiredSkills')->willReturnCallback(static function (array $typeIds): array {
            $requirements = [
                self::SHIP => [self::FRIGATE_SKILL => 1],
                self::GUN => [self::GUNNERY_SKILL => 4, self::FRIGATE_SKILL => 1],
                self::AMMO => [self::AMMO_SKILL => 5],
            ];
            $result = [];
            foreach ($typeIds as $typeId) {
                if (isset($requirements[$typeId])) {
                    $result[$typeId] = $requirements[$typeId];
                }
            }
            return $result;
        });

        return $sdeService;
    }

    private function _createFitting(int $id): Fitting
    {
        $fitting = new Fitting();
        $fitting->setContent(self::SHIP, [
            ['typeId' => self::GUN, 'flag' => 'HiSlot0', 'quantity' => 1],
            ['typeId' => self::AMMO, 'flag' => 'Cargo', 'quantity' => 100],
        ], 'hash');
        (new \ReflectionProperty(Fitting::class, 'id'))->setValue($fitting, $id);

        return $fitting;
    }

    /**
     * @param array<int, int>|null $skillLevels
     */
    private function _createCharacter(int $id, ?array $skillLevels): EveCharacter
    {
        $character = (new EveCharacter())->setId($id);
        if ($skillLevels === null) {
            return $character;
        }

        $skills = [];
        foreach ($skillLevels as $skillId => $level) {
            $skills[] = ['skill_id' => $skillId, 'active_skill_level' => $level, 'trained_skill_level' => $level];
        }

        return $character->setSkills(['skills' => $skills]);
    }
}
