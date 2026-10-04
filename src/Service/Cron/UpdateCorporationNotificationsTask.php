<?php

namespace App\Service\Cron;

use App\Entity\DiscordNotificationLog;
use App\Entity\EveCharacter;
use App\Service\Discord\DiscordWebhookService;
use App\Service\Discord\StructureNotificationParser;
use App\Service\Esi\CorporationAccessResolver;
use App\Service\Esi\EsiClient;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ObjectRepository;
use Psr\Log\LoggerInterface;

class UpdateCorporationNotificationsTask implements CronTaskInterface
{
    private const SUPPORTED_TYPES = [
        'StructureUnderAttack',
        'StructureLostShields',
        'StructureLostArmor',
        'StructureWentLowPower',
        'StructureWentHighPower',
        'StructureFuelAlert',
        'StructureServicesOffline',
        'StructureUnanchoring',
        'StructureDestroyed',
        'TowerAlertMsg',
        'TowerResourceAlertMsg',
        'OrbitalAttacked',
        'OrbitalReinforced',
    ];

    // Structure alerts reach station managers too; directors come first and also receive POS and customs office alerts
    private const NOTIFICATION_ROLES = ['Station_Manager'];
    private const NOTIFICATION_SCOPE = 'esi-characters.read_notifications.v1';

    // Older notifications are stale (first run, new director, long Discord outage) and are not posted
    private const MAX_NOTIFICATION_AGE_HOURS = 6;

    private const RESULT_SKIPPED = 'skipped';
    private const RESULT_DISPATCHED = 'dispatched';
    private const RESULT_DELIVERY_FAILED = 'delivery_failed';

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly EsiClient $esiClient,
        private readonly StructureNotificationParser $notificationParser,
        private readonly DiscordWebhookService $discordWebhookService,
        private readonly CorporationAccessResolver $corporationAccessResolver,
        private readonly LoggerInterface $logger
    ) {}

    public function getCommandName(): string
    {
        return 'corporation:sync-notifications';
    }

    public function execute(): void
    {
        $charactersByCorp = $this->corporationAccessResolver->getCharactersByCorporation(self::NOTIFICATION_ROLES, self::NOTIFICATION_SCOPE);

        $this->logger->info(sprintf('[Cron] Starting corporation notifications sync for %d corporations.', count($charactersByCorp)));

        $logRepo = $this->entityManager->getRepository(DiscordNotificationLog::class);

        foreach ($charactersByCorp as $corpId => $characters) {
            // First character that can read its notifications covers the corporation
            foreach ($characters as $character) {
                $this->logger->info(sprintf(
                    '[Cron] Checking notifications for corp %d via %s...',
                    $corpId,
                    $character->getName()
                ));

                try {
                    $notifications = $this->esiClient->request(
                        'GET',
                        sprintf('characters/%d/notifications/', $character->getId()),
                        [],
                        $character
                    );

                    if (!is_array($notifications)) {
                        continue;
                    }

                    $dispatchedCount = 0;
                    foreach ($notifications as $notif) {
                        $result = $this->_processNotification($notif, $character, $corpId, $logRepo);
                        if ($result === self::RESULT_DISPATCHED) {
                            $dispatchedCount++;
                        } elseif ($result === self::RESULT_DELIVERY_FAILED) {
                            // Discord is likely unreachable; remaining alerts are retried on the next run
                            break;
                        }
                    }

                    $this->logger->info(sprintf(
                        '[Cron] Processed notifications for corp %d (%d new alerts dispatched).',
                        $corpId,
                        $dispatchedCount
                    ));

                    break;
                } catch (\Throwable $e) {
                    $this->logger->error(sprintf(
                        '[Cron] Failed to fetch notifications for %s: %s',
                        $character->getName(),
                        $e->getMessage()
                    ));
                }
            }
        }

        $this->logger->info('[Cron] Finished corporation notifications sync execution.');
    }

    private function _processNotification(array $notif, EveCharacter $director, int $corpId, ObjectRepository $logRepo): string
    {
        $notifId = (string)($notif['notification_id'] ?? '');
        $type = $notif['type'] ?? '';

        if (empty($notifId) || !in_array($type, self::SUPPORTED_TYPES, true)) {
            return self::RESULT_SKIPPED;
        }

        if (!$this->_isNotificationRecent($notif)) {
            return self::RESULT_SKIPPED;
        }

        // Check if already processed
        if ($logRepo->findOneBy(['notificationId' => $notifId]) !== null) {
            return self::RESULT_SKIPPED;
        }

        // Parse into Discord message
        $message = $this->notificationParser->parseNotification($notif);
        if ($message === null) {
            return self::RESULT_SKIPPED;
        }

        $targetChannel = $this->_getTargetChannel($type);

        // Without a webhook the notification is still logged so it is not re-processed later
        $sent = false;
        if ($this->discordWebhookService->isConfigured($targetChannel)) {
            $sent = $this->discordWebhookService->send($message, $targetChannel);
            if (!$sent) {
                $this->logger->warning(sprintf('[Cron] Discord delivery of notification %s (%s) failed, will retry on next run.', $notifId, $type));
                return self::RESULT_DELIVERY_FAILED;
            }
        }

        $log = new DiscordNotificationLog();
        $log->setNotificationId($notifId);
        $log->setChannel($targetChannel);
        $log->setType($type);
        $log->setMetadata([
            'sender_id' => $notif['sender_id'] ?? null,
            'sent_date' => $notif['timestamp'] ?? null,
            'director_char_id' => $director->getId(),
            'corp_id' => $corpId,
            'delivered' => $sent,
        ]);

        $this->entityManager->persist($log);
        $this->entityManager->flush();

        return self::RESULT_DISPATCHED;
    }

    private function _isNotificationRecent(array $notif): bool
    {
        try {
            $sentDate = new \DateTimeImmutable((string)($notif['timestamp'] ?? ''));
        } catch (\Exception $e) {
            // Unknown age: rather post once than drop a possible attack alert
            return true;
        }

        return $sentDate >= new \DateTimeImmutable(sprintf('-%d hours', self::MAX_NOTIFICATION_AGE_HOURS));
    }

    private function _getTargetChannel(string $type): string
    {
        return match ($type) {
            'StructureUnderAttack', 'StructureLostShields', 'StructureLostArmor',
            'TowerAlertMsg', 'OrbitalAttacked' => DiscordWebhookService::CHANNEL_COMBAT,
            'StructureFuelAlert', 'TowerResourceAlertMsg' => DiscordWebhookService::CHANNEL_FUEL,
            default => DiscordWebhookService::CHANNEL_STRUCTURES,
        };
    }
}
