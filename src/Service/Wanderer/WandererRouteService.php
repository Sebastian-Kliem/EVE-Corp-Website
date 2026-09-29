<?php

namespace App\Service\Wanderer;

use App\Entity\WandererRouteRule;
use App\Repository\WandererRouteRuleRepository;
use App\Service\Discord\DiscordWebhookService;
use App\Service\Discord\Model\DiscordColor;
use App\Service\Discord\Model\DiscordEmbed;
use App\Service\Discord\Model\DiscordMessage;
use App\Service\Esi\EsiClient;
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
     * Verifies the HMAC-SHA256 signature sent by Wanderer.
     */
    public function verifySignature(string $rawPayload, ?string $signatureHeader, ?string $timestampHeader): bool
    {
        $secret = $this->discordWebhookService->getWandererSecret();
        if (empty($secret)) {
            // If no secret is configured, accept incoming requests
            return true;
        }

        if (empty($signatureHeader) || empty($timestampHeader)) {
            return false;
        }

        if (str_starts_with($signatureHeader, 'sha256=')) {
            $signatureHeader = substr($signatureHeader, 7);
        }

        $signedData = $timestampHeader . '.' . $rawPayload;
        $expectedSignature = hash_hmac('sha256', $signedData, $secret);

        return hash_equals($expectedSignature, $signatureHeader);
    }

    /**
     * Processes an incoming Wanderer webhook payload.
     *
     * @return array<string, mixed> Processing summary
     */
    public function processWebhookPayload(array $payload): array
    {
        $eventType = $payload['event'] ?? $payload['type'] ?? 'unknown';

        $results = [
            'event' => $eventType,
            'processed_systems' => 0,
            'matched_rules' => 0,
            'corp_notifications' => 0,
            'user_notifications' => 0,
        ];

        // Nur Events mit realer Verbindungsentstehung verarbeiten.
        // Das bloße händische Anlegen eines Systems ('add_system') besitzt keine Verbindung
        // und soll keinen unberechtigten Routenalarm auslösen.
        if ($eventType !== 'connection_added') {
            $this->logger->info(sprintf(
                '[WandererRouteService] Event "%s" ignoriert (Routenalarme triggern nur bei "connection_added").',
                $eventType
            ));
            return $results;
        }

        $discoveredSystems = $this->_extractSolarSystems($payload);
        $results['processed_systems'] = count($discoveredSystems);
        $mapName = $payload['map']['name'] ?? $payload['map_name'] ?? $payload['map_id'] ?? null;
        $characterName = $payload['character']['name'] ?? $payload['character_name'] ?? null;

        if (empty($discoveredSystems)) {
            $this->logger->info(sprintf('[WandererRouteService] No solar systems found in event: %s', $eventType));
            return $results;
        }

        $corpRules = $this->ruleRepository->findActiveCorpRules();
        $userRules = $this->ruleRepository->findActiveUserRules();

        foreach ($discoveredSystems as $systemData) {
            $solarSystemId = $systemData['id'];
            $sourceSystemName = $systemData['source_system_name'] ?? null;

            // Check Corp Rules
            foreach ($corpRules as $rule) {
                if ($this->_evaluateAndNotify($rule, $solarSystemId, $sourceSystemName, $mapName, $characterName)) {
                    $results['matched_rules']++;
                    $results['corp_notifications']++;
                }
            }

            // Check User Rules
            foreach ($userRules as $rule) {
                if ($this->_evaluateAndNotify($rule, $solarSystemId, $sourceSystemName, $mapName, $characterName)) {
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
        ?string $mapName,
        ?string $characterName
    ): bool {
        // Cooldown check
        if (!$this->_isCooldownPassed($rule, $originSolarSystemId)) {
            return false;
        }

        // Get origin system info from SDE
        $systemInfo = $this->sdeService->getSolarSystemInfo($originSolarSystemId);
        if (!$systemInfo) {
            return false;
        }

        $originSec = $systemInfo['security'];
        // Quick security check on the origin system itself
        if ($rule->getSecurityMode() === WandererRouteRule::SEC_MODE_HIGHSEC_ONLY && $originSec < 0.45) {
            return false;
        }
        if ($rule->getSecurityMode() === WandererRouteRule::SEC_MODE_HIGHSEC_LOWSEC && $originSec < 0.0) {
            return false;
        }

        // Determine ESI route flag
        $esiFlag = ($rule->getSecurityMode() === WandererRouteRule::SEC_MODE_HIGHSEC_ONLY) ? 'secure' : 'shortest';

        $route = $this->esiClient->getRoute($originSolarSystemId, $rule->getTargetSolarSystemId(), $esiFlag);
        if ($route === null || empty($route)) {
            return false;
        }

        $jumps = count($route) - 1;
        if ($jumps < 0 || $jumps > $rule->getMaxJumps()) {
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
            $mapName,
            $characterName
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
            if (!$info) {
                continue;
            }

            $sec = $info['security'];
            if ($securityMode === WandererRouteRule::SEC_MODE_HIGHSEC_ONLY && $sec < 0.45) {
                return false;
            }
            if ($securityMode === WandererRouteRule::SEC_MODE_HIGHSEC_LOWSEC && $sec < 0.0) {
                return false;
            }
        }

        return true;
    }

    /**
     * Checks if cooldown period has passed for the given rule.
     */
    private function _isCooldownPassed(WandererRouteRule $rule, int $solarSystemId): bool
    {
        $lastTriggered = $rule->getLastTriggeredAt();
        if ($lastTriggered === null) {
            return true;
        }

        $cooldownSeconds = $rule->getCooldownMinutes() * 60;
        $now = (new \DateTimeImmutable())->getTimestamp();

        return ($now - $lastTriggered->getTimestamp()) >= $cooldownSeconds;
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
        ?string $mapName,
        ?string $characterName
    ): bool {
        $originName = $originSystemInfo['solarSystemName'];
        $originSec = round($originSystemInfo['security'], 1);
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
        if (!empty($characterName)) {
            $descriptionLines[] = sprintf('**Entdeckt von:** %s', $characterName);
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
     * Extracts solar system information from various Wanderer event payloads.
     *
     * @return array<int, array<string, mixed>>
     */
    private function _extractSolarSystems(array $payload): array
    {
        $systems = [];
        $data = isset($payload['payload']) && is_array($payload['payload']) ? $payload['payload'] : $payload;

        // Check if payload has direct system object
        if (isset($data['solar_system_id']) && is_numeric($data['solar_system_id'])) {
            $systems[] = [
                'id' => (int)$data['solar_system_id'],
                'source_system_name' => $data['source_system_name'] ?? $payload['source_system_name'] ?? null,
            ];
        } elseif (isset($data['system']['solar_system_id']) && is_numeric($data['system']['solar_system_id'])) {
            $systems[] = [
                'id' => (int)$data['system']['solar_system_id'],
                'source_system_name' => $data['source_system_name'] ?? $payload['source_system_name'] ?? null,
            ];
        } elseif (isset($data['system_id']) && is_numeric($data['system_id'])) {
            $systems[] = [
                'id' => (int)$data['system_id'],
                'source_system_name' => $data['source_system_name'] ?? $payload['source_system_name'] ?? null,
            ];
        }

        // Check if connection is a wormhole connection (reject pure K-space stargate jumps)
        if ((isset($data['solar_system_target']) || isset($data['solar_system_source'])) && !$this->_isWormholeConnection($data)) {
            $this->logger->info(sprintf(
                '[WandererRouteService] Ignored connection between %s and %s (not a wormhole exit or chain connection).',
                $data['solar_system_source'] ?? 'unknown',
                $data['solar_system_target'] ?? 'unknown'
            ));
            return [];
        }

        // Check if payload is a connection event (target system)
        if (isset($data['solar_system_target']) && is_numeric($data['solar_system_target'])) {
            $sourceName = $data['from_name'] ?? null;
            if (!$sourceName && isset($data['solar_system_source']) && is_numeric($data['solar_system_source'])) {
                $sourceName = $this->sdeService->getLocationName((int)$data['solar_system_source']);
            }
            $systems[] = [
                'id' => (int)$data['solar_system_target'],
                'source_system_name' => $sourceName,
            ];
        }

        // Check connection event source system (e.g. if jumping into K-space exit from WH)
        if (isset($data['solar_system_source']) && is_numeric($data['solar_system_source'])) {
            $targetName = $data['to_name'] ?? null;
            if (!$targetName && isset($data['solar_system_target']) && is_numeric($data['solar_system_target'])) {
                $targetName = $this->sdeService->getLocationName((int)$data['solar_system_target']);
            }
            $systems[] = [
                'id' => (int)$data['solar_system_source'],
                'source_system_name' => $targetName,
            ];
        }

        // Check for nested systems array
        if (isset($data['systems']) && is_array($data['systems'])) {
            foreach ($data['systems'] as $sys) {
                $sysId = $sys['solar_system_id'] ?? $sys['id'] ?? null;
                if (is_numeric($sysId)) {
                    $systems[] = [
                        'id' => (int)$sysId,
                        'source_system_name' => $sys['source_system_name'] ?? null,
                    ];
                }
            }
        }

        // Deduplicate systems by id
        $unique = [];
        $seen = [];
        foreach ($systems as $s) {
            if (!in_array($s['id'], $seen, true)) {
                $seen[] = $s['id'];
                $unique[] = $s;
            }
        }

        return $unique;
    }

    /**
     * Formats security status text badge.
     */
    private function _formatSecurityBadge(float $security): string
    {
        if ($security >= 0.45) {
            return 'Highsec';
        }
        if ($security > 0.0) {
            return 'Lowsec';
        }
        if ($security <= -0.99) {
            return 'Wormhole';
        }
        return 'Nullsec';
    }

    /**
     * Determines the embed color based on security status.
     */
    private function _getColorForSecurity(float $security): int
    {
        if ($security >= 0.45) {
            return DiscordColor::GREEN;
        }
        if ($security > 0.0) {
            return DiscordColor::ORANGE;
        }
        return DiscordColor::RED;
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
