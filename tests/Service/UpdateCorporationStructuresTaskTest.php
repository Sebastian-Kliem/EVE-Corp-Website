<?php

namespace App\Tests\Service;

use App\Entity\EveCharacter;
use App\Entity\EveCorporationStructure;
use App\Service\Cron\UpdateCorporationStructuresTask;
use App\Service\Discord\StructureAlertService;
use App\Service\Esi\CorporationAccessResolver;
use App\Service\Esi\EsiClient;
use App\Service\SdeService;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\EntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\HttpClient\Exception\ClientException;
use Symfony\Component\HttpClient\Exception\ServerException;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

class UpdateCorporationStructuresTaskTest extends TestCase
{
    private const CORPORATION_ID = 98000001;

    private array $persistedEntities = [];
    private array $requestedCharacterNames = [];

    public function testNextCharacterIsUsedWhenRolesAreOutdated(): void
    {
        $outdatedDirector = $this->_createCharacter(1, 'Former Director');
        $stationManager = $this->_createCharacter(2, 'Station Manager');

        $task = $this->_createTask([$outdatedDirector, $stationManager], [
            'Former Director' => $this->_createHttpException(ClientException::class, 403),
        ]);
        $task->execute();

        $this->assertSame(['Former Director', 'Station Manager'], $this->requestedCharacterNames);
        $structures = $this->_getPersisted(EveCorporationStructure::class);
        $this->assertCount(1, $structures);
        $this->assertSame((string) self::CORPORATION_ID, $structures[0]->getCorporationId());
    }

    public function testServerErrorDoesNotTryFurtherCharacters(): void
    {
        $task = $this->_createTask([$this->_createCharacter(1, 'First'), $this->_createCharacter(2, 'Second')], [
            'First' => $this->_createHttpException(ServerException::class, 502),
        ]);
        $task->execute();

        $this->assertSame(['First'], $this->requestedCharacterNames);
        $this->assertSame([], $this->_getPersisted(EveCorporationStructure::class));
    }

    private function _createTask(array $structureCharacters, array $failuresByCharacterName): UpdateCorporationStructuresTask
    {
        $resolver = $this->createStub(CorporationAccessResolver::class);
        $resolver->method('getCharactersByCorporation')->willReturnCallback(
            fn (array $acceptedRoles) => $acceptedRoles === [] ? [] : [self::CORPORATION_ID => $structureCharacters]
        );

        $esiClient = $this->createStub(EsiClient::class);
        $esiClient->method('request')->willReturn(['name' => 'Keepers of Duat']);
        $esiClient->method('requestAllPages')->willReturnCallback(function (string $path, array $options, EveCharacter $character) use ($failuresByCharacterName) {
            $this->requestedCharacterNames[] = $character->getName();
            if (isset($failuresByCharacterName[$character->getName()])) {
                throw $failuresByCharacterName[$character->getName()];
            }

            return ['data' => [['structure_id' => 1035000000001, 'type_id' => 35832, 'system_id' => 31000005, 'name' => 'J123456 - Home', 'state' => 'shield_vulnerable']], 'fromCache' => false];
        });

        $repository = $this->createStub(EntityRepository::class);
        $repository->method('find')->willReturn(null);
        $repository->method('findBy')->willReturn([]);
        $entityManager = $this->createStub(EntityManagerInterface::class);
        $entityManager->method('getRepository')->willReturn($repository);
        $entityManager->method('isOpen')->willReturn(true);
        $entityManager->method('persist')->willReturnCallback(function (object $entity) {
            $this->persistedEntities[] = $entity;
        });

        $sdeService = $this->createStub(SdeService::class);
        $sdeService->method('getItemName')->willReturn('Astrahus');
        $sdeService->method('getLocationName')->willReturn('J123456');

        return new UpdateCorporationStructuresTask(
            $this->createStub(ManagerRegistry::class),
            $entityManager,
            $esiClient,
            $sdeService,
            $this->createStub(StructureAlertService::class),
            $resolver,
            new NullLogger()
        );
    }

    private function _getPersisted(string $className): array
    {
        $entities = [];
        foreach ($this->persistedEntities as $entity) {
            if ($entity instanceof $className) {
                $entities[] = $entity;
            }
        }

        return $entities;
    }

    private function _createHttpException(string $exceptionClass, int $statusCode): \Exception
    {
        $response = (new MockHttpClient(new MockResponse('{"error":"Character does not have required role(s)"}', ['http_code' => $statusCode])))->request('GET', 'https://esi.example/');

        return new $exceptionClass($response);
    }

    private function _createCharacter(int $id, string $name): EveCharacter
    {
        $character = new EveCharacter();
        $character->setId($id);
        $character->setName($name);
        $character->setCorporationId(self::CORPORATION_ID);

        return $character;
    }
}
