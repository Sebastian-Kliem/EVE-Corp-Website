<?php

namespace App\Service\Wanderer;

use App\Entity\WandererRouteRule;
use App\Repository\WandererRouteRuleRepository;
use App\Service\Discord\DiscordWebhookService;
use App\Service\Discord\Model\DiscordColor;
use App\Service\Discord\Model\DiscordEmbed;
use App\Service\Discord\Model\DiscordMessage;
use App\Service\Esi\EsiClient;
use App\Service\Eve\SecurityStatus;
use App\Service\SdeService;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;

class WandererRouteService
{
    public function __construct(
        private readonly WandererRouteRuleRepository $ruleRepository,
        private readonly EsiClient $esiClient,
        private readonly SdeService $sdeService,
        private readonly DiscordWebhookService $discordWebhookService,
        private readonly EntityManagerInterface $entityManager,
        private readonly LoggerInterface $logger
    ) {}

    /**
     * Evaluates all active route rules for a new map connection (from the Wanderer API).
     *
     * @param array<string, mixed> $connection
     * @param int[] $alreadyConnectedSystemIds systems that were already reachable on the map, they are not announced again
     * @return array{processed_systems: int, matched_rules: int, corp_notifications: int, user_notifications: int}
     */
    public function processNewConnection(array $connection, ?string $mapName, array $alreadyConnectedSystemIds = []): array
    {
        $results = [
            'processed_systems' => 0,
            'matched_rules' => 0,
            'corp_notifications' => 0,
            'user_notifications' => 0,
        ];

        $discoveredSystems = [];
        foreach ($this->_extractSolarSystems($connection) as $systemData) {
            if (!in_array($systemData['id'], $alreadyConnectedSystemIds, true)) {
                $discoveredSystems[] = $systemData;
            }
        }
        $results['processed_systems'] = count($discoveredSystems);

        if (empty($discoveredSystems)) {
            return $results;
        }

        $corpRules = $this->ruleRepository->findActiveCorpRules();
        $userRules = $this->ruleRepository->findActiveUserRules();

        foreach ($discoveredSystems as $systemData) {
            $solarSystemId = $systemData['id'];
            $sourceSystemName = $systemData['source_system_name'] ?? null;

            // Check Corp Rules
            foreach ($corpRules as $rule) {
                if ($this->_evaluateAndNotify($rule, $solarSystemId, $sourceSystemName, $mapName)) {
                    $results['matched_rules']++;
                    $results['corp_notifications']++;
                }
            }

            // Check User Rules
            foreach ($userRules as $rule) {
                if ($this->_evaluateAndNotify($rule, $solarSystemId, $sourceSystemName, $mapName)) {
                    $results['matched_rules']++;
                    $results['user_notifications']++;
                }
            }
        }

        $this->entityManager->flush();

        return $results;
    }

    /**
     * Evaluates a single rule for a solar system and triggers Discord notification if matched.
     */
    private function _evaluateAndNotify(
        WandererRouteRule $rule,
        int $originSolarSystemId,
        ?string $sourceSystemName,
        ?string $mapName
    ): bool {
        // Get origin system info from SDE
        $systemInfo = $this->sdeService->getSolarSystemInfo($originSolarSystemId);
        if (!$systemInfo) {
            return false;
        }

        // Quick security check on the origin system itself
        if (!$this->_meetsSecurityMode((float)$systemInfo['security'], $rule->getSecurityMode())) {
            return false;
        }

        // Determine ESI route flag
        $esiFlag = ($rule->getSecurityMode() === WandererRouteRule::SEC_MODE_HIGHSEC_ONLY) ? 'secure' : 'shortest';

        $route = $this->esiClient->getRoute($originSolarSystemId, $rule->getTargetSolarSystemId(), $esiFlag);
        if ($route === null || empty($route)) {
            return false;
        }

        $jumps = count($route) - 1;
        if ($jumps > $rule->getMaxJumps()) {
            return false;
        }

        // Validate security mode for all intermediate systems in the route
        if (!$this->_validateRouteSecurity($route, $rule->getSecurityMode())) {
            return false;
        }

        // Match found! Send Discord message
        $targetSystemInfo = $this->sdeService->getSolarSystemInfo($rule->getTargetSolarSystemId());
        $targetName = $targetSystemInfo['solarSystemName'] ?? $rule->getTargetSolarSystemName();

        $sent = $this->_sendDiscordNotification(
            $rule,
            $systemInfo,
            $targetName,
            $jumps,
            $sourceSystemName,
            $mapName
        );

        if ($sent) {
            $rule->setLastTriggeredAt(new \DateTimeImmutable());
            return true;
        }

        return false;
    }

    /**
     * Checks whether all systems in the given route meet the security requirement.
     */
    private function _validateRouteSecurity(array $routeSystemIds, string $securityMode): bool
    {
        if ($securityMode === WandererRouteRule::SEC_MODE_ANY) {
            return true;
        }

        foreach ($routeSystemIds as $sysId) {
            $info = $this->sdeService->getSolarSystemInfo($sysId);
            // An unknown system cannot be proven safe
            if (!$info) {
                return false;
            }

            if (!$this->_meetsSecurityMode((float)$info['security'], $securityMode)) {
                return false;
            }
        }

        return true;
    }

    // Uses the security status as shown in game, so "highsec" never lets a 0.4 system through
    private function _meetsSecurityMode(float $trueSecurity, string $securityMode): bool
    {
        if ($securityMode === WandererRouteRule::SEC_MODE_HIGHSEC_ONLY) {
            return SecurityStatus::isHighsec($trueSecurity);
        }
        if ($securityMode === WandererRouteRule::SEC_MODE_HIGHSEC_LOWSEC) {
            return SecurityStatus::isHighOrLowsec($trueSecurity);
        }

        return true;
    }

    /**
     * Builds and sends the Discord notification message.
     */
    private function _sendDiscordNotification(
        WandererRouteRule $rule,
        array $originSystemInfo,
        string $targetName,
        int $jumps,
        ?string $sourceSystemName,
        ?string $mapName
    ): bool {
        $originName = $originSystemInfo['solarSystemName'];
        $originSec = SecurityStatus::toDisplay((float)$originSystemInfo['security']);
        $regionName = $originSystemInfo['regionName'] ?? 'Unknown Region';
        $constellationName = $originSystemInfo['constellationName'] ?? '';

        $secBadge = $this->_formatSecurityBadge($originSystemInfo['security']);

        $embed = new DiscordEmbed();
        $isCorp = $rule->isCorpRule();

        if ($isCorp) {
            $embed->setTitle(sprintf('K-Space Exit entdeckt: %s (%s)', $originName, $secBadge));
            $embed->setColor($this->_getColorForSecurity($originSystemInfo['security']));
        } else {
            $embed->setTitle(sprintf('Persönliche Route: %s -> %s (%d Sprünge)', $originName, $targetName, $jumps));
            $embed->setColor(DiscordColor::PURPLE);
        }

        $embed->setTimestamp(new \DateTimeImmutable());
        $embed->setFooter('WH-Toolbox Wanderer Integration');

        $descriptionLines = [];
        $descriptionLines[] = sprintf('**Regel:** %s', $rule->getName());
        $descriptionLines[] = sprintf('**Distanz:** %d %s nach **%s**', $jumps, $jumps === 1 ? 'Sprung' : 'Sprünge', $targetName);
        $descriptionLines[] = sprintf('**Sicherheitsstatus:** %0.1f (%s)', $originSec, $secBadge);
        $descriptionLines[] = sprintf('**Region:** %s (%s)', $regionName, $constellationName);

        if (!empty($sourceSystemName)) {
            $descriptionLines[] = sprintf('**Verbunden aus:** %s', $sourceSystemName);
        }
        if (!empty($mapName)) {
            $descriptionLines[] = sprintf('**Wanderer Map:** %s', $mapName);
        }

        $dotlanUrl = sprintf('https://evemaps.dotlan.net/system/%s', urlencode($originName));
        $zkillUrl = sprintf('https://zkillboard.com/system/%d/', $originSystemInfo['solarSystemID']);
        $descriptionLines[] = sprintf('[Dotlan](%s) | [zKillboard](%s)', $dotlanUrl, $zkillUrl);

        $embed->setDescription(implode("\n", $descriptionLines));

        $message = new DiscordMessage();
        $message->addEmbed($embed);

        if ($isCorp) {
            $ping = $this->discordWebhookService->getWandererPing();
            if (!empty($ping)) {
                $message->setContent($ping);
            }
            return $this->discordWebhookService->send($message, DiscordWebhookService::CHANNEL_WANDERER);
        }

        // Personal User Rule: Send to user's configured webhook
        $user = $rule->getUser();
        if ($user === null) {
            return false;
        }

        $userSettings = $user->getSettings();
        $userWebhookUrl = $userSettings['discord_webhook_wanderer'] ?? $userSettings['discord_webhook'] ?? null;

        if (empty($userWebhookUrl)) {
            $this->logger->info(sprintf(
                '[WandererRouteService] Skipped user notification for rule "%s" (User %s has no personal webhook configured).',
                $rule->getName(),
                $user->getUserIdentifier()
            ));
            return false;
        }

        return $this->discordWebhookService->send($message, DiscordWebhookService::CHANNEL_DEFAULT, $userWebhookUrl);
    }

    /**
     * Returns both systems of a wormhole connection; stargate connections yield nothing.
     *
     * @param array<string, mixed> $connection
     * @return array<int, array{id: int, source_system_name: ?string}>
     */
    private function _extractSolarSystems(array $connection): array
    {
        if (!isset($connection['solar_system_source'], $connection['solar_system_target'])
            || !is_numeric($connection['solar_system_source']) || !is_numeric($connection['solar_system_target'])) {
            return [];
        }

        if (!$this->_isWormholeConnection($connection)) {
            $this->logger->info(sprintf(
                '[WandererRouteService] Ignored connection between %s and %s (not a wormhole connection).',
                $connection['solar_system_source'],
                $connection['solar_system_target']
            ));
            return [];
        }

        $sourceId = (int)$connection['solar_system_source'];
        $targetId = (int)$connection['solar_system_target'];

        $systems = [
            ['id' => $targetId, 'source_system_name' => $this->sdeService->getLocationName($sourceId)],
        ];
        if ($sourceId !== $targetId) {
            $systems[] = ['id' => $sourceId, 'source_system_name' => $this->sdeService->getLocationName($targetId)];
        }

        return $systems;
    }

    /**
     * Formats security status text badge.
     */
    private function _formatSecurityBadge(float $security): string
    {
        if ($security <= -0.99) {
            return 'Wormhole';
        }

        return match (SecurityStatus::classify($security)) {
            SecurityStatus::HIGHSEC => 'Highsec',
            SecurityStatus::LOWSEC => 'Lowsec',
            default => 'Nullsec',
        };
    }

    /**
     * Determines the embed color based on security status.
     */
    private function _getColorForSecurity(float $security): int
    {
        return match (SecurityStatus::classify($security)) {
            SecurityStatus::HIGHSEC => DiscordColor::GREEN,
            SecurityStatus::LOWSEC => DiscordColor::ORANGE,
            default => DiscordColor::RED,
        };
    }

    /**
     * Checks if a connection is potentially a wormhole connection (i.e. not a pure K-space stargate jump).
     */
    private function _isWormholeConnection(array $data): bool
    {
        $sourceId = isset($data['solar_system_source']) ? (int)$data['solar_system_source'] : 0;
        $targetId = isset($data['solar_system_target']) ? (int)$data['solar_system_target'] : 0;

        // 1. If Wanderer explicitly flags it as a stargate connection (type === 1), it is not a wormhole exit
        if (isset($data['type']) && ((int)$data['type'] === 1 || $data['type'] === 'stargate')) {
            return false;
        }

        // 2. If either side is a J-space / Wormhole system (EVE solarSystemID 31000000-31999999)
        $isSourceWh = ($sourceId >= 31000000 && $sourceId < 32000000);
        $isTargetWh = ($targetId >= 31000000 && $targetId < 32000000);

        if ($isSourceWh || $isTargetWh) {
            return true;
        }

        // 3. For K-space to K-space connections, only accept if explicitly a wormhole connection (type === 0 or wormhole_type set)
        if (!empty($data['wormhole_type'])) {
            return true;
        }

        if (isset($data['type']) && ((int)$data['type'] === 0 || $data['type'] === 'wormhole')) {
            return true;
        }

        // Pure K-space to K-space without wormhole type: reject (normal gate travel)
        return false;
    }
}
