<?php

namespace App\Tests\Service\Character;

use App\Entity\EveCharacter;
use App\Service\Character\CharacterCloneOverviewBuilder;
use App\Service\Character\SkillGroupProfileBuilder;
use App\Service\Chart\RadarChartBuilder;
use App\Service\LocationService;
use App\Service\SdeService;
use PHPUnit\Framework\TestCase;

class CharacterDetailsBuildersTest extends TestCase
{
    public function testSkillGroupsSumSkillPointsRelativeToGroupMaximum(): void
    {
        $sdeService = $this->createStub(SdeService::class);
        $sdeService->method('getSkillCatalog')->willReturn([
            1 => ['groupId' => 10, 'groupName' => 'Gunnery', 'rank' => 1.0],
            2 => ['groupId' => 10, 'groupName' => 'Gunnery', 'rank' => 3.0],
            3 => ['groupId' => 20, 'groupName' => 'Armor', 'rank' => 2.0],
        ]);
        $character = (new EveCharacter())->setSkills(['skills' => [
            ['skill_id' => 1, 'skillpoints_in_skill' => 256000, 'trained_skill_level' => 5],
            ['skill_id' => 2, 'skillpoints_in_skill' => 128000, 'trained_skill_level' => 4],
            ['skill_id' => 99, 'skillpoints_in_skill' => 5000, 'trained_skill_level' => 1],
        ]]);

        $builder = new SkillGroupProfileBuilder($sdeService);
        $groups = $builder->build($character);

        $this->assertSame(['Armor', 'Gunnery'], [$groups[0]['name'], $groups[1]['name']]);
        $this->assertSame(0.0, $groups[0]['percent']);
        $this->assertSame(384000, $groups[1]['trainedSkillPoints']);
        $this->assertSame(1024000, $groups[1]['maxSkillPoints']);
        $this->assertSame(37.5, $groups[1]['percent']);
        $this->assertSame(1, $groups[1]['maxedSkillCount']);
        $this->assertSame(40.0, $builder->getChartScaleMax($groups));
    }

    public function testRadarChartPlacesFirstAxisOnTopAndScalesValues(): void
    {
        $chart = (new RadarChartBuilder())->build([
            ['label' => 'A', 'value' => 50.0],
            ['label' => 'B', 'value' => 100.0],
            ['label' => 'C', 'value' => 0.0],
            ['label' => 'D', 'value' => 25.0],
        ], 100.0, 100.0);

        $this->assertCount(4, $chart['axes']);
        $this->assertCount(4, $chart['rings']);
        $this->assertSame($chart['centerX'], $chart['axes'][0]['x']);
        $this->assertSame($chart['centerY'] - 100.0, $chart['axes'][0]['y']);
        $this->assertSame($chart['centerY'] - 50.0, $chart['axes'][0]['pointY']);
        $this->assertSame($chart['centerX'] + 100.0, $chart['axes'][1]['pointX']);
        $this->assertSame('start', $chart['axes'][1]['labelAnchor']);
        $this->assertSame('end', $chart['axes'][3]['labelAnchor']);
    }

    public function testCloneOverviewResolvesNamesAndJumpCooldown(): void
    {
        $locationService = $this->createStub(LocationService::class);
        $locationService->method('resolveLocation')->willReturnCallback(static fn (int $locationId): array => ['name' => 'Station ' . $locationId]);
        $sdeService = $this->createStub(SdeService::class);
        $sdeService->method('getItemName')->willReturnCallback(static fn (mixed $typeId): string => 'Implant ' . $typeId);

        $character = (new EveCharacter())
            ->setSkills(['skills' => [['skill_id' => 33399, 'active_skill_level' => 4, 'trained_skill_level' => 4]]])
            ->setClones([
                'home_location' => ['location_id' => 60003760, 'location_type' => 'station'],
                'jump_clones' => [['jump_clone_id' => 1, 'location_id' => 60008494, 'implants' => [9899], 'name' => 'PvP']],
                'last_clone_jump_date' => '2026-11-11T00:00:00Z',
            ]);

        $builder = new CharacterCloneOverviewBuilder($locationService, $sdeService);
        $overview = $builder->build($character, new \DateTimeImmutable('2026-11-11T10:00:00Z'));

        $this->assertSame('Station 60003760', $overview['homeLocation']);
        $this->assertSame('Station 60008494', $overview['jumpClones'][0]['location']);
        $this->assertSame(['Implant 9899'], $overview['jumpClones'][0]['implants']);
        $this->assertEquals(new \DateTimeImmutable('2026-11-11T20:00:00Z'), $overview['nextJumpAt']);

        $laterOverview = $builder->build($character, new \DateTimeImmutable('2026-11-11T21:00:00Z'));
        $this->assertNull($laterOverview['nextJumpAt']);
    }
}
