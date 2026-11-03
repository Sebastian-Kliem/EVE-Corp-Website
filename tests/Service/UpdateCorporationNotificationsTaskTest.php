<?php

namespace App\Tests\Service;

use App\Entity\DiscordNotificationLog;
use App\Entity\EveCharacter;
use App\Entity\EveCorporationStructure;
use App\Entity\TrackedCorporation;
use App\Service\Corporation\CorporationTrackingService;
use App\Service\Cron\UpdateCorporationNotificationsTask;
use App\Service\Discord\DiscordWebhookService;
use App\Service\Discord\Model\DiscordMessage;
use App\Service\Discord\StructureNotificationParser;
use App\Service\Esi\CorporationAccessResolver;
use App\Service\Esi\EsiClient;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\EntityRepository;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

class UpdateCorporationNotificationsTaskTest extends TestCase
{
    private array $persistedLogs = [];

    public function testOldNotificationsAreNotPosted(): void
    {
        $webhookService = $this->createMock(DiscordWebhookService::class);
        $webhookService->method('isConfiguredForCorporation')->willReturn(true);
        $webhookService->expects($this->never())->method('sendForCorporation');

        $task = $this->_createTask([$this->_createNotification(1, '-2 days')], $webhookService);
        $task->execute();

        $this->assertSame([], $this->persistedLogs);
    }

    public function testFailedDeliveryIsNotLoggedAndStopsTheRun(): void
    {
        $webhookService = $this->createMock(DiscordWebhookService::class);
        $webhookService->method('isConfiguredForCorporation')->willReturn(true);
        $webhookService->expects($this->once())->method('sendForCorporation')->willReturn(false);

        $notifications = [$this->_createNotification(1, '-5 minutes'), $this->_createNotification(2, '-4 minutes')];
        $task = $this->_createTask($notifications, $webhookService);
        $task->execute();

        // Nothing logged, so both notifications are retried on the next run
        $this->assertSame([], $this->persistedLogs);
    }

    public function testDeliveredNotificationIsLogged(): void
    {
        $webhookService = $this->createStub(DiscordWebhookService::class);
        $webhookService->method('isConfiguredForCorporation')->willReturn(true);
        $webhookService->method('sendForCorporation')->willReturn(true);

        $task = $this->_createTask([$this->_createNotification(1, '-5 minutes')], $webhookService);
        $task->execute();

        $this->assertCount(1, $this->persistedLogs);
        $this->assertSame('1', $this->persistedLogs[0]->getNotificationId());
        $this->assertTrue($this->persistedLogs[0]->getMetadata()['delivered']);
    }

    public function testNotificationIsLoggedWithoutWebhook(): void
    {
        $webhookService = $this->createMock(DiscordWebhookService::class);
        $webhookService->method('isConfiguredForCorporation')->willReturn(false);
        $webhookService->expects($this->never())->method('sendForCorporation');

        $task = $this->_createTask([$this->_createNotification(1, '-5 minutes')], $webhookService);
        $task->execute();

        $this->assertCount(1, $this->persistedLogs);
        $this->assertFalse($this->persistedLogs[0]->getMetadata()['delivered']);
    }

    public function testNotificationOfStructureWithDisabledAlertsIsSkipped(): void
    {
        $webhookService = $this->createMock(DiscordWebhookService::class);
        $webhookService->method('isConfiguredForCorporation')->willReturn(true);
        $webhookService->expects($this->once())->method('sendForCorporation')->willReturn(true);

        $notifications = [
            $this->_createNotification(1, '-5 minutes', 'structureID: 1035000000001'),
            $this->_createNotification(2, '-5 minutes', 'structureID: 1035000000002'),
        ];
        $task = $this->_createTask($notifications, $webhookService, ['1035000000002']);
        $task->execute();

        $this->assertCount(1, $this->persistedLogs);
        $this->assertSame('1', $this->persistedLogs[0]->getNotificationId());
    }

    public function testInactiveCorporationIsNotChecked(): void
    {
        $webhookService = $this->createMock(DiscordWebhookService::class);
        $webhookService->expects($this->never())->method('sendForCorporation');

        $task = $this->_createTask([$this->_createNotification(1, '-5 minutes')], $webhookService, [], false);
        $task->execute();

        $this->assertSame([], $this->persistedLogs);
    }

    private function _createTask(array $notifications, DiscordWebhookService $webhookService, array $disabledStructureIds = [], bool $corporationActive = true): UpdateCorporationNotificationsTask
    {
        $director = new EveCharacter();
        $director->setId(123);
        $director->setName('Director');
        $director->setRefreshToken('refresh-token');
        $director->setTokenValid(true);
        $director->setCorporationId(98000001);
        $director->setRoles(['Director']);

        $resolver = $this->createStub(CorporationAccessResolver::class);
        $resolver->method('getCharactersByCorporation')->willReturn([98000001 => [$director]]);
        $logRepository = $this->createStub(EntityRepository::class);
        $logRepository->method('findOneBy')->willReturn(null);
        $logRepository->method('find')->willReturnCallback(function (string $structureId) use ($disabledStructureIds): EveCorporationStructure {
            $structure = new EveCorporationStructure();
            $structure->setId($structureId);
            $structure->setAlertsEnabled(!in_array($structureId, $disabledStructureIds, true));
            return $structure;
        });

        $entityManager = $this->createStub(EntityManagerInterface::class);
        $entityManager->method('getRepository')->willReturn($logRepository);
        $entityManager->method('persist')->willReturnCallback(function (object $entity): void {
            $this->persistedLogs[] = $entity;
        });

        $esiClient = $this->createStub(EsiClient::class);
        $esiClient->method('request')->willReturn($notifications);

        $parser = $this->createStub(StructureNotificationParser::class);
        $parser->method('parseNotification')->willReturn(DiscordMessage::create('alert'));

        $corporation = (new TrackedCorporation('98000001'))->setUseGlobalChannels(true);
        $trackingService = $this->createStub(CorporationTrackingService::class);
        $trackingService->method('registerAndGetActive')->willReturn($corporationActive ? [98000001 => $corporation] : []);

        return new UpdateCorporationNotificationsTask($entityManager, $esiClient, $parser, $webhookService, $resolver, $trackingService, new NullLogger());
    }

    private function _createNotification(int $notificationId, string $age, string $text = ''): array
    {
        return [
            'notification_id' => $notificationId,
            'type' => 'StructureUnderAttack',
            'timestamp' => (new \DateTimeImmutable($age))->format(\DateTimeInterface::ATOM),
            'text' => $text,
        ];
    }
}
