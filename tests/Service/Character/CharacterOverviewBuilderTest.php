<?php

namespace App\Tests\Service\Character;

use App\Entity\EveAccount;
use App\Entity\EveCharacter;
use App\Entity\User;
use App\Service\Character\CharacterListBuilder;
use App\Service\Character\CharacterOverviewBuilder;
use App\Service\SdeService;
use PHPUnit\Framework\TestCase;

class CharacterOverviewBuilderTest extends TestCase
{
    private \DateTimeImmutable $now;

    protected function setUp(): void
    {
        $this->now = new \DateTimeImmutable('2026-11-11 12:00:00', new \DateTimeZone('UTC'));
    }

    public function testOmegaAccountsComeFirstSortedByStrongestCharacter(): void
    {
        $alphaRich = $this->_createAccount('Alpha Rich', false, [90_000_000]);
        $omegaSmall = $this->_createAccount('Omega Small', true, [5_000_000, 20_000_000]);
        $omegaBig = $this->_createAccount('Omega Big', true, [1_000_000, 60_000_000]);

        $overview = $this->_buildOverview([$alphaRich, $omegaSmall, $omegaBig]);

        $accountNames = [];
        foreach ($overview['accountGroups'] as $accountGroup) {
            $accountNames[] = $accountGroup['account']->getName();
        }
        $this->assertSame(['Omega Big', 'Omega Small', 'Alpha Rich'], $accountNames);
        $this->assertSame(2, $overview['totals']['omegaAccountCount']);
        $this->assertSame(176_000_000, $overview['totals']['totalSkillPoints']);
    }

    public function testCharactersInsideAccountAreSortedBySkillPointsDescending(): void
    {
        $account = $this->_createAccount('Main', true, [3_000_000, null, 40_000_000]);

        $overview = $this->_buildOverview([$account]);

        $skillPoints = [];
        foreach ($overview['accountGroups'][0]['characters'] as $characterEntry) {
            $skillPoints[] = $characterEntry['totalSkillPoints'];
        }
        $this->assertSame([40_000_000, 3_000_000, null], $skillPoints);
    }

    public function testSkillQueueSkipsFinishedEntriesAndDetectsPausedAndEmptyQueues(): void
    {
        $training = $this->_createCharacter(1, 'Training', 1_000_000);
        $training->setSkillQueue([
            ['skill_id' => 1, 'queue_position' => 0, 'finished_level' => 3, 'finish_date' => '2026-11-10T12:00:00Z'],
            ['skill_id' => 2, 'queue_position' => 1, 'finished_level' => 4, 'finish_date' => '2026-11-11T14:00:00Z'],
            ['skill_id' => 3, 'queue_position' => 2, 'finished_level' => 5, 'finish_date' => '2026-11-14T12:00:00Z'],
        ]);
        $paused = $this->_createCharacter(2, 'Paused', 2_000_000);
        $paused->setSkillQueue([['skill_id' => 4, 'queue_position' => 0, 'finished_level' => 1]]);
        $idle = $this->_createCharacter(3, 'Idle', 3_000_000);

        $overview = $this->_buildOverview([], [$training, $paused, $idle]);

        $trainingByName = [];
        foreach ($overview['unassignedGroup']['characters'] as $characterEntry) {
            $trainingByName[$characterEntry['character']->getName()] = $characterEntry['training'];
        }

        $this->assertSame(CharacterOverviewBuilder::TRAINING_ACTIVE, $trainingByName['Training']['status']);
        $this->assertSame('Skill 2', $trainingByName['Training']['skillName']);
        $this->assertSame(2, $trainingByName['Training']['queueLength']);
        $this->assertSame('2h 0m', $trainingByName['Training']['skillRemaining']);
        $this->assertSame('3T 0h', $trainingByName['Training']['queueRemaining']);
        $this->assertFalse($trainingByName['Training']['queueEndsSoon']);
        $this->assertSame(CharacterOverviewBuilder::TRAINING_PAUSED, $trainingByName['Paused']['status']);
        $this->assertSame(CharacterOverviewBuilder::TRAINING_EMPTY, $trainingByName['Idle']['status']);
    }

    /**
     * @param list<EveAccount> $accounts
     * @param list<EveCharacter> $unassignedCharacters
     */
    private function _buildOverview(array $accounts, array $unassignedCharacters = []): array
    {
        $characterListBuilder = $this->createStub(CharacterListBuilder::class);
        $characterListBuilder->method('build')->willReturn([
            'accounts' => $accounts,
            'unassignedCharacters' => $unassignedCharacters,
            'missingScopesByCharacter' => [],
        ]);

        $sdeService = $this->createStub(SdeService::class);
        $sdeService->method('getItemName')->willReturnCallback(static fn (mixed $typeId): string => 'Skill ' . $typeId);

        $builder = new CharacterOverviewBuilder($characterListBuilder, $sdeService);

        return $builder->build(new User(), $this->now);
    }

    /**
     * @param list<int|null> $skillPointsPerCharacter
     */
    private function _createAccount(string $name, bool $isOmega, array $skillPointsPerCharacter): EveAccount
    {
        $account = (new EveAccount())->setName($name)->setIsOmega($isOmega);
        foreach ($skillPointsPerCharacter as $index => $skillPoints) {
            $account->addCharacter($this->_createCharacter(crc32($name) + $index, $name . ' ' . $index, $skillPoints));
        }

        return $account;
    }

    private function _createCharacter(int $id, string $name, ?int $skillPoints): EveCharacter
    {
        $character = (new EveCharacter())->setId($id)->setName($name);
        if ($skillPoints !== null) {
            $character->setSkills(['total_sp' => $skillPoints, 'unallocated_sp' => 0, 'skills' => []]);
        }

        return $character;
    }
}
