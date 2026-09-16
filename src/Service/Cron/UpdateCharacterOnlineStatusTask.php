<?php

namespace App\Service\Cron;

use App\Service\CharacterOnlineService;
use Psr\Log\LoggerInterface;

class UpdateCharacterOnlineStatusTask implements CronTaskInterface
{
    public function __construct(
        private readonly CharacterOnlineService $characterOnlineService,
        private readonly LoggerInterface $logger,
    ) {}

    public function getCommandName(): string
    {
        return 'character:sync-online-status';
    }

    public function execute(): void
    {
        $this->logger->info('[Cron] Starting character:sync-online-status...');
        $result = $this->characterOnlineService->syncOnlineStatus();

        if (isset($result['skipped'])) {
            $this->logger->info(sprintf('[Cron] sync-online-status skipped: %s', $result['reason'] ?? 'unknown'));
            return;
        }

        $this->logger->info(sprintf(
            '[Cron] Finished sync-online-status: %d checked, %d online, %d errors.',
            $result['checked'] ?? 0,
            $result['online'] ?? 0,
            $result['errors'] ?? 0
        ));
    }
}
