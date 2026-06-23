<?php

namespace App\Service\Cron;

use App\Service\LocationService;
use Psr\Log\LoggerInterface;

class UpdateStructureCacheTask implements CronTaskInterface
{
    public function __construct(
        private readonly LocationService $locationService,
        private readonly LoggerInterface $logger
    ) {}

    public function getCommandName(): string
    {
        return 'structure:update-cache';
    }

    public function execute(): void
    {
        $this->logger->info('[Cron] Starting structure cache update task.');
        
        try {
            $this->locationService->updateExpiredStructures($this->logger);
        } catch (\Exception $e) {
            $this->logger->error(sprintf('[Cron] Failed to update structure cache: %s', $e->getMessage()));
            throw $e;
        }

        $this->logger->info('[Cron] Finished structure cache update task.');
    }
}
