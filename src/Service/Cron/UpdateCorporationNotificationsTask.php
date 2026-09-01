<?php

namespace App\Service\Cron;

use App\Entity\DiscordNotificationLog;
use App\Entity\EveCharacter;
use App\Service\Discord\DiscordWebhookService;
use App\Service\Discord\StructureNotificationParser;
use App\Service\Esi\EsiClient;
use Doctrine\ORM\EntityManagerInterface;
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

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly EsiClient $esiClient,
        private readonly StructureNotificationParser $notificationParser,
        private readonly DiscordWebhookService $discordWebhookService,
        private readonly LoggerInterface $logger
    ) {}

    public function getCommandName(): string
    {
        return 'corporation:sync-notifications';
    }

    public function execute(): void
    {
        $characterRepository = $this->entityManager->getRepository(EveCharacter::class);
        /** @var EveCharacter[] $allCharacters */
        $allCharacters = $characterRepository->findAll();

        // 1. Group active director characters by corporation
        $directorsByCorp = [];
        foreach ($allCharacters as $char) {
            if (empty($char->getRefreshToken()) || !$char->isTokenValid()) {
                continue;
            }

            $corpId = $char->getCorporationId();
            if ($corpId && $char->isDirector()) {
                $directorsByCorp[$corpId][] = $char;
            }
        }

        $this->logger->info(sprintf('[Cron] Starting corporation notifications sync for %d corporations.', count($directorsByCorp)));

        $logRepo = $this->entityManager->getRepository(DiscordNotificationLog::class);

        foreach ($directorsByCorp as $corpId => $directors) {
            // Check notifications for directors
            foreach ($directors as $director) {
                $this->logger->info(sprintf(
                    '[Cron] Checking notifications for corp %d via director %s...',
                    $corpId,
                    $director->getName()
                ));

                try {
                    $notifications = $this->esiClient->request(
                        'GET',
                        sprintf('characters/%d/notifications/', $director->getId()),
                        [],
                        $director
                    );

                    if (!is_array($notifications)) {
                        continue;
                    }

                    $dispatchedCount = 0;
                    foreach ($notifications as $notif) {
                        $notifId = (string)($notif['notification_id'] ?? '');
                        $type = $notif['type'] ?? '';

                        if (empty($notifId) || !in_array($type, self::SUPPORTED_TYPES, true)) {
                            continue;
                        }

                        // Check if already processed
                        $existingLog = $logRepo->findOneBy(['notificationId' => $notifId]);
                        if ($existingLog !== null) {
                            continue;
                        }

                        // Parse into Discord message
                        $message = $this->notificationParser->parseNotification($notif);
                        if ($message === null) {
                            continue;
                        }

                        // Determine target channel
                        $targetChannel = match ($type) {
                            'StructureUnderAttack', 'StructureLostShields', 'StructureLostArmor',
                            'TowerAlertMsg', 'OrbitalAttacked' => DiscordWebhookService::CHANNEL_COMBAT,
                            'StructureFuelAlert', 'TowerResourceAlertMsg' => DiscordWebhookService::CHANNEL_FUEL,
                            default => DiscordWebhookService::CHANNEL_STRUCTURES,
                        };

                        $sent = $this->discordWebhookService->send($message, $targetChannel);

                        // Save log entry regardless of webhook configuration so we don't re-process later
                        $log = new DiscordNotificationLog();
                        $log->setNotificationId($notifId);
                        $log->setChannel($targetChannel);
                        $log->setType($type);
                        $log->setMetadata([
                            'sender_id' => $notif['sender_id'] ?? null,
                            'sent_date' => $notif['sent_date'] ?? null,
                            'director_char_id' => $director->getId(),
                            'corp_id' => $corpId,
                            'delivered' => $sent,
                        ]);

                        $this->entityManager->persist($log);
                        $this->entityManager->flush();
                        $dispatchedCount++;
                    }

                    $this->logger->info(sprintf(
                        '[Cron] Processed notifications for corp %d (%d new alerts dispatched).',
                        $corpId,
                        $dispatchedCount
                    ));

                    // One director per corp is usually sufficient for corp-level notifications
                    break;
                } catch (\Throwable $e) {
                    $this->logger->error(sprintf(
                        '[Cron] Failed to fetch notifications for director %s: %s',
                        $director->getName(),
                        $e->getMessage()
                    ));
                }
            }
        }

        $this->logger->info('[Cron] Finished corporation notifications sync execution.');
    }
}
