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
    private ?AppSetting $seenIdsSetting = null;

    public function testFirstRunOnlyRecordsExistingConnections(): void
    {
        $routeService = $this->createMock(WandererRouteService::class);
        $routeService->expects($this->never())->method('processWebhookPayload');

        $this->_createTask($this->_createApiClient([$this->_connection('a'), $this->_connection('b')]), $routeService)->execute();

        $this->assertSame(['a', 'b'], json_decode((string)$this->seenIdsSetting?->getValue(), true));
    }

    public function testOnlyNewConnectionsAreProcessed(): void
    {
        $this->seenIdsSetting = new AppSetting(SyncWandererConnectionsTask::SEEN_IDS_SETTING_KEY, json_encode(['a', 'gone']));

        $routeService = $this->createMock(WandererRouteService::class);
        $routeService->expects($this->once())
            ->method('processWebhookPayload')
            ->with($this->callback(function (array $payload): bool {
                return $payload['type'] === 'connection_added'
                    && $payload['payload']['id'] === 'b'
                    && $payload['map_name'] === 'keepers-of-duat';
            }))
            ->willReturn(['matched_rules' => 1]);

        $this->_createTask($this->_createApiClient([$this->_connection('a'), $this->_connection('b')]), $routeService)->execute();

        // Removed connections drop out of the stored state
        $this->assertSame(['a', 'b'], json_decode((string)$this->seenIdsSetting->getValue(), true));
    }

    public function testUnconfiguredApiIsSkipped(): void
    {
        $apiClient = $this->createMock(WandererApiClient::class);
        $apiClient->method('isConfigured')->willReturn(false);
        $apiClient->expects($this->never())->method('fetchConnections');

        $routeService = $this->createMock(WandererRouteService::class);
        $routeService->expects($this->never())->method('processWebhookPayload');

        $this->_createTask($apiClient, $routeService)->execute();

        $this->assertNull($this->seenIdsSetting);
    }

    private function _createTask(WandererApiClient $apiClient, WandererRouteService $routeService): SyncWandererConnectionsTask
    {
        $settingRepository = $this->createStub(EntityRepository::class);
        $settingRepository->method('find')->willReturnCallback(fn (): ?AppSetting => $this->seenIdsSetting);

        $entityManager = $this->createStub(EntityManagerInterface::class);
        $entityManager->method('getRepository')->willReturn($settingRepository);
        $entityManager->method('persist')->willReturnCallback(function (object $entity): void {
            if ($entity instanceof AppSetting) {
                $this->seenIdsSetting = $entity;
            }
        });

        return new SyncWandererConnectionsTask($apiClient, $routeService, $entityManager, $this->createStub(CronLogWriter::class), new NullLogger());
    }

    /**
     * @param array<int, array<string, mixed>> $connections
     */
    private function _createApiClient(array $connections): WandererApiClient
    {
        $apiClient = $this->createStub(WandererApiClient::class);
        $apiClient->method('isConfigured')->willReturn(true);
        $apiClient->method('getMapSlug')->willReturn('keepers-of-duat');
        $apiClient->method('fetchConnections')->willReturn($connections);

        return $apiClient;
    }

    /**
     * @return array<string, mixed>
     */
    private function _connection(string $id): array
    {
        return ['id' => $id, 'type' => 0, 'solar_system_source' => 31001075, 'solar_system_target' => 30002969];
    }
}
