<?php

namespace App\Service\Cron;

use App\Entity\AppSetting;
use App\Service\Wanderer\WandererApiClient;
use App\Service\Wanderer\WandererHomeChain;
use App\Service\Wanderer\WandererRouteService;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;

/**
 * Polls the Wanderer map for new connections and feeds them into the route alerts
 * (replacement for Wanderer webhooks, which crash upstream).
 */
class SyncWandererConnectionsTask implements CronTaskInterface
{
    public const STATE_SETTING_KEY = 'wanderer_known_connections';

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
        $previousConnections = $this->_loadKnownConnections();

        $currentConnections = [];
        foreach ($connections as $connection) {
            $connectionId = (string)($connection['id'] ?? '');
            if ($connectionId === '') {
                continue;
            }
            $currentConnections[$connectionId] = [
                (int)($connection['solar_system_source'] ?? 0),
                (int)($connection['solar_system_target'] ?? 0),
                WandererHomeChain::isWormholeConnection($connection),
            ];
        }

        // First run only records the current state, otherwise every existing connection would alert
        if ($previousConnections === null) {
            $this->_saveKnownConnections($currentConnections);
            $this->cronLogWriter->write(sprintf('[Wanderer] Abgleich initialisiert mit %d bestehenden Verbindung(en).', count($currentConnections)));
            return;
        }

        // Without a home system no connection can be proven to be our route
        $homeSystemId = $this->routeService->resolveHomeSystemId();
        if ($homeSystemId === null) {
            $this->_saveKnownConnections($currentConnections);
            $this->cronLogWriter->write('[Wanderer] Kein gültiges Heimatsystem konfiguriert, Routenmeldungen pausiert.');
            return;
        }

        $homeChainSystemIds = WandererHomeChain::collectSystemIds($currentConnections, $homeSystemId);

        // A system is announced again only after it was cut off from the home chain in between
        $connectedSystemIds = WandererHomeChain::collectSystemIds($previousConnections, $homeSystemId);

        $mapSlug = $this->wandererApiClient->getMapSlug();
        foreach ($connections as $connection) {
            $connectionId = (string)($connection['id'] ?? '');
            if ($connectionId === '' || isset($previousConnections[$connectionId])) {
                continue;
            }

            [$sourceSystemId, $targetSystemId] = $currentConnections[$connectionId];
            if (!$this->_isPartOfHomeChain($currentConnections[$connectionId], $homeChainSystemIds)) {
                $this->cronLogWriter->write(sprintf(
                    '[Wanderer] Neue Verbindung %d -> %d ignoriert: kein Wurmloch mit Verbindung zum Heimatsystem (z. B. Filament).',
                    $sourceSystemId,
                    $targetSystemId
                ));
                continue;
            }

            $result = $this->routeService->processNewConnection($connection, $mapSlug, array_keys($connectedSystemIds));
            $this->cronLogWriter->write(sprintf(
                '[Wanderer] Neue Verbindung %s -> %s: %d neue(s) System(e), %d Regel(n) ausgelöst.',
                $connection['solar_system_source'] ?? '?',
                $connection['solar_system_target'] ?? '?',
                $result['processed_systems'],
                $result['matched_rules']
            ));

            $connectedSystemIds[$sourceSystemId] = true;
            $connectedSystemIds[$targetSystemId] = true;
        }

        $this->_saveKnownConnections($currentConnections);
    }

    /**
     * @param array{0: int, 1: int, 2: bool} $knownConnection [source, target, isWormhole]
     * @param array<int, true> $homeChainSystemIds
     */
    private function _isPartOfHomeChain(array $knownConnection, array $homeChainSystemIds): bool
    {
        [$sourceSystemId, $targetSystemId, $isWormhole] = $knownConnection;

        return $isWormhole && isset($homeChainSystemIds[$sourceSystemId], $homeChainSystemIds[$targetSystemId]);
    }

    /**
     * @return array<string, array<int, int|bool>>|null connection ID => [source, target, isWormhole]; null when not initialized yet
     */
    private function _loadKnownConnections(): ?array
    {
        $setting = $this->entityManager->getRepository(AppSetting::class)->find(self::STATE_SETTING_KEY);
        if ($setting === null || $setting->getValue() === null) {
            return null;
        }

        $decoded = json_decode($setting->getValue(), true);
        if (!is_array($decoded) || !isset($decoded['connections']) || !is_array($decoded['connections'])) {
            return null;
        }

        return $decoded['connections'];
    }

    /**
     * @param array<string, array<int, int|bool>> $connections
     */
    private function _saveKnownConnections(array $connections): void
    {
        $value = json_encode(['connections' => $connections]);
        $setting = $this->entityManager->getRepository(AppSetting::class)->find(self::STATE_SETTING_KEY);
        if ($setting === null) {
            $setting = new AppSetting(self::STATE_SETTING_KEY, $value);
            $this->entityManager->persist($setting);
        } else {
            $setting->setValue($value);
        }

        $this->entityManager->flush();
    }
}
