<?php

namespace App\Tests\Service\Cron;

use App\Entity\AppSetting;
use App\Service\Cron\CronLogWriter;
use App\Service\Cron\SyncWandererConnectionsTask;
use App\Service\Wanderer\WandererApiClient;
use App\Service\Wanderer\WandererRouteService;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\EntityRepository;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

class SyncWandererConnectionsTaskTest extends TestCase
{
    private const WORMHOLE = 31001075;
    private const PERIMETER = 30000144;
    private const AMARR = 30002187;

    private ?AppSetting $stateSetting = null;

    public function testFirstRunOnlyRecordsExistingConnections(): void
    {
        $routeService = $this->createMock(WandererRouteService::class);
        $routeService->expects($this->never())->method('processNewConnection');

        $this->_runTask([$this->_connection('a', self::WORMHOLE, self::PERIMETER)], $routeService);

        $this->assertSame(['a' => [self::WORMHOLE, self::PERIMETER]], $this->_storedConnections());
    }

    public function testNewConnectionIsProcessedAndStillConnectedSystemsAreSkipped(): void
    {
        $this->_storeConnections(['a' => [self::WORMHOLE, self::PERIMETER], 'gone' => [self::WORMHOLE, self::AMARR]]);

        $routeService = $this->createMock(WandererRouteService::class);
        $routeService->expects($this->once())
            ->method('processNewConnection')
            ->with(
                $this->callback(fn (array $connection): bool => $connection['id'] === 'b'),
                'keepers-of-duat',
                // Systems of the previous poll, including the one of the collapsed connection
                [self::WORMHOLE, self::PERIMETER, self::AMARR]
            )
            ->willReturn(['processed_systems' => 1, 'matched_rules' => 0, 'corp_notifications' => 0, 'user_notifications' => 0]);

        $this->_runTask([
            $this->_connection('a', self::WORMHOLE, self::PERIMETER),
            $this->_connection('b', 31002000, self::PERIMETER),
        ], $routeService);

        $this->assertSame(['a', 'b'], array_keys($this->_storedConnections()));
    }

    public function testSystemIsAnnouncedAgainAfterItWasDisconnected(): void
    {
        // Amarr was not connected in the previous poll
        $this->_storeConnections(['a' => [self::WORMHOLE, self::PERIMETER]]);

        $routeService = $this->createMock(WandererRouteService::class);
        $routeService->expects($this->once())
            ->method('processNewConnection')
            ->with($this->anything(), $this->anything(), [self::WORMHOLE, self::PERIMETER])
            ->willReturn(['processed_systems' => 1, 'matched_rules' => 1, 'corp_notifications' => 1, 'user_notifications' => 0]);

        $this->_runTask([
            $this->_connection('a', self::WORMHOLE, self::PERIMETER),
            $this->_connection('c', self::WORMHOLE, self::AMARR),
        ], $routeService);
    }

    public function testSecondNewConnectionToTheSameSystemInOnePollIsDeduplicated(): void
    {
        $this->_storeConnections([]);

        $alreadyConnectedPerCall = [];
        $routeService = $this->createMock(WandererRouteService::class);
        $routeService->expects($this->exactly(2))
            ->method('processNewConnection')
            ->willReturnCallback(function (array $connection, ?string $mapName, array $alreadyConnected) use (&$alreadyConnectedPerCall): array {
                $alreadyConnectedPerCall[] = $alreadyConnected;
                return ['processed_systems' => 1, 'matched_rules' => 0, 'corp_notifications' => 0, 'user_notifications' => 0];
            });

        $this->_runTask([
            $this->_connection('a', self::WORMHOLE, self::PERIMETER),
            $this->_connection('b', 31002000, self::PERIMETER),
        ], $routeService);

        $this->assertSame([], $alreadyConnectedPerCall[0]);
        $this->assertSame([self::WORMHOLE, self::PERIMETER], $alreadyConnectedPerCall[1]);
    }

    public function testUnconfiguredApiIsSkipped(): void
    {
        $apiClient = $this->createMock(WandererApiClient::class);
        $apiClient->method('isConfigured')->willReturn(false);
        $apiClient->expects($this->never())->method('fetchConnections');

        $routeService = $this->createMock(WandererRouteService::class);
        $routeService->expects($this->never())->method('processNewConnection');

        $this->_createTask($apiClient, $routeService)->execute();

        $this->assertNull($this->stateSetting);
    }

    /**
     * @param array<int, array<string, mixed>> $connections
     */
    private function _runTask(array $connections, WandererRouteService $routeService): void
    {
        $apiClient = $this->createStub(WandererApiClient::class);
        $apiClient->method('isConfigured')->willReturn(true);
        $apiClient->method('getMapSlug')->willReturn('keepers-of-duat');
        $apiClient->method('fetchConnections')->willReturn($connections);

        $this->_createTask($apiClient, $routeService)->execute();
    }

    private function _createTask(WandererApiClient $apiClient, WandererRouteService $routeService): SyncWandererConnectionsTask
    {
        $settingRepository = $this->createStub(EntityRepository::class);
        $settingRepository->method('find')->willReturnCallback(fn (): ?AppSetting => $this->stateSetting);

        $entityManager = $this->createStub(EntityManagerInterface::class);
        $entityManager->method('getRepository')->willReturn($settingRepository);
        $entityManager->method('persist')->willReturnCallback(function (object $entity): void {
            if ($entity instanceof AppSetting) {
                $this->stateSetting = $entity;
            }
        });

        return new SyncWandererConnectionsTask($apiClient, $routeService, $entityManager, $this->createStub(CronLogWriter::class), new NullLogger());
    }

    /**
     * @param array<string, int[]> $connections
     */
    private function _storeConnections(array $connections): void
    {
        $this->stateSetting = new AppSetting(SyncWandererConnectionsTask::STATE_SETTING_KEY, json_encode(['connections' => (object)$connections]));
    }

    /**
     * @return array<string, int[]>
     */
    private function _storedConnections(): array
    {
        return json_decode((string)$this->stateSetting?->getValue(), true)['connections'];
    }

    /**
     * @return array<string, mixed>
     */
    private function _connection(string $id, int $sourceSystemId, int $targetSystemId): array
    {
        return ['id' => $id, 'type' => 0, 'solar_system_source' => $sourceSystemId, 'solar_system_target' => $targetSystemId];
    }
}
