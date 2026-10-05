<?php

namespace App\Service\Cron;

use App\Entity\AppSetting;
use App\Service\Wanderer\WandererApiClient;
use App\Service\Wanderer\WandererRouteService;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;

/**
 * Polls the Wanderer map for new connections and feeds them into the route alerts
 * (replacement for Wanderer webhooks, which crash upstream).
 */
class SyncWandererConnectionsTask implements CronTaskInterface
{
    public const SEEN_IDS_SETTING_KEY = 'wanderer_seen_connection_ids';

    public function __construct(
        private readonly WandererApiClient $wandererApiClient,
        private readonly WandererRouteService $routeService,
        private readonly EntityManagerInterface $entityManager,
        private readonly CronLogWriter $cronLogWriter,
        private readonly LoggerInterface $logger
    ) {}

    public function getCommandName(): string
    {
        return 'wanderer:sync-connections';
    }

    public function execute(): void
    {
        if (!$this->wandererApiClient->isConfigured()) {
            $this->logger->debug('[Cron] wanderer:sync-connections skipped: Wanderer API not configured.');
            return;
        }

        $connections = $this->wandererApiClient->fetchConnections();
        $seenIds = $this->_loadSeenIds();

        $currentIds = [];
        $newConnections = [];
        foreach ($connections as $connection) {
            $connectionId = (string)($connection['id'] ?? '');
            if ($connectionId === '') {
                continue;
            }
            $currentIds[] = $connectionId;
            if ($seenIds !== null && !in_array($connectionId, $seenIds, true)) {
                $newConnections[] = $connection;
            }
        }

        // First run only records the current state, otherwise every existing connection would alert
        if ($seenIds === null) {
            $this->_saveSeenIds($currentIds);
            $this->cronLogWriter->write(sprintf('[Wanderer] Abgleich initialisiert mit %d bestehenden Verbindung(en).', count($currentIds)));
            return;
        }

        $mapSlug = $this->wandererApiClient->getMapSlug();
        foreach ($newConnections as $connection) {
            $result = $this->routeService->processWebhookPayload([
                'type' => 'connection_added',
                'payload' => $connection,
                'map_name' => $mapSlug,
            ]);
            $this->cronLogWriter->write(sprintf(
                '[Wanderer] Neue Verbindung %s -> %s: %d Regel(n) ausgelöst.',
                $connection['solar_system_source'] ?? '?',
                $connection['solar_system_target'] ?? '?',
                $result['matched_rules']
            ));
        }

        $this->_saveSeenIds($currentIds);
    }

    /**
     * @return string[]|null null when the task has never run
     */
    private function _loadSeenIds(): ?array
    {
        $setting = $this->entityManager->getRepository(AppSetting::class)->find(self::SEEN_IDS_SETTING_KEY);
        if ($setting === null || $setting->getValue() === null) {
            return null;
        }

        $decoded = json_decode($setting->getValue(), true);

        return is_array($decoded) ? $decoded : null;
    }

    /**
     * @param string[] $connectionIds
     */
    private function _saveSeenIds(array $connectionIds): void
    {
        $value = json_encode(array_values($connectionIds));
        $setting = $this->entityManager->getRepository(AppSetting::class)->find(self::SEEN_IDS_SETTING_KEY);
        if ($setting === null) {
            $setting = new AppSetting(self::SEEN_IDS_SETTING_KEY, $value);
            $this->entityManager->persist($setting);
        } else {
            $setting->setValue($value);
        }

        $this->entityManager->flush();
    }
}
