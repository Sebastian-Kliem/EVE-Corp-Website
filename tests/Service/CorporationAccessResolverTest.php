<?php

namespace App\Tests\Service;

use App\Entity\EveCharacter;
use App\Service\Esi\CorporationAccessResolver;
use App\Service\Esi\EsiClient;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\EntityRepository;
use PHPUnit\Framework\TestCase;

class CorporationAccessResolverTest extends TestCase
{
    private const SCOPE = 'esi-corporations.read_structures.v1';

    public function testCharactersAreFilteredByRoleScopeAndTokenPerCorporation(): void
    {
        $stationManager = $this->_createCharacter(1, 98000001, ['Station_Manager'], true);
        $director = $this->_createCharacter(2, 98000001, ['Director'], true);
        $withoutScope = $this->_createCharacter(3, 98000001, ['Station_Manager'], false);
        $withoutRole = $this->_createCharacter(4, 98000001, ['Accountant'], true);
        $otherCorpManager = $this->_createCharacter(5, 98000002, ['Station_Manager'], true);
        $withoutRefreshToken = $this->_createCharacter(6, 98000003, ['Director'], true);
        $withoutRefreshToken->setRefreshToken(null);
        $npcCorpDirector = $this->_createCharacter(7, 1000166, ['Director'], true);

        $resolver = $this->_createResolver([$stationManager, $director, $withoutScope, $withoutRole, $otherCorpManager, $withoutRefreshToken, $npcCorpDirector]);

        // Directors first, then the other role holders
        $this->assertSame(
            [98000001 => [$director, $stationManager], 98000002 => [$otherCorpManager]],
            $resolver->getCharactersByCorporation(['Station_Manager'], self::SCOPE)
        );
        // Without accepted roles only directors qualify
        $this->assertSame([98000001 => [$director]], $resolver->getCharactersByCorporation([], self::SCOPE));
    }

    private function _createResolver(array $characters): CorporationAccessResolver
    {
        $repository = $this->createStub(EntityRepository::class);
        $repository->method('findBy')->willReturn($characters);
        $entityManager = $this->createStub(EntityManagerInterface::class);
        $entityManager->method('getRepository')->willReturn($repository);

        $esiClient = $this->createStub(EsiClient::class);
        $esiClient->method('hasScope')->willReturnCallback(
            fn (EveCharacter $character, string $scope) => $character->getAccessToken() === 'token-with-scope'
        );

        return new CorporationAccessResolver($entityManager, $esiClient);
    }

    private function _createCharacter(int $id, int $corporationId, array $roles, bool $hasScope): EveCharacter
    {
        $character = new EveCharacter();
        $character->setId($id);
        $character->setName('Pilot ' . $id);
        $character->setCorporationId($corporationId);
        $character->setRoles($roles);
        $character->setRefreshToken('refresh-token');
        $character->setTokenValid(true);
        $character->setAccessToken($hasScope ? 'token-with-scope' : 'token-without-scope');

        return $character;
    }
}
