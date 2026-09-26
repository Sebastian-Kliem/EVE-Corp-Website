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
        $discoveredSystems = $this->_extractSolarSystems($payload);
        $mapName = $payload['map']['name'] ?? $payload['map_name'] ?? $payload['map_id'] ?? null;
        $characterName = $payload['character']['name'] ?? $payload['character_name'] ?? null;

        $results = [
            'event' => $eventType,
            'processed_systems' => count($discoveredSystems),
            'matched_rules' => 0,
            'corp_notifications' => 0,
            'user_notifications' => 0,
        ];

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

        // Check if payload has direct system object
        if (isset($payload['solar_system_id']) && is_numeric($payload['solar_system_id'])) {
            $systems[] = [
                'id' => (int)$payload['solar_system_id'],
                'source_system_name' => $payload['source_system_name'] ?? null,
            ];
        } elseif (isset($payload['system']['solar_system_id']) && is_numeric($payload['system']['solar_system_id'])) {
            $systems[] = [
                'id' => (int)$payload['system']['solar_system_id'],
                'source_system_name' => $payload['source_system_name'] ?? null,
            ];
        } elseif (isset($payload['system_id']) && is_numeric($payload['system_id'])) {
            $systems[] = [
                'id' => (int)$payload['system_id'],
                'source_system_name' => $payload['source_system_name'] ?? null,
            ];
        }

        // Check if payload is a connection event
        if (isset($payload['solar_system_target']) && is_numeric($payload['solar_system_target'])) {
            $sourceName = null;
            if (isset($payload['solar_system_source']) && is_numeric($payload['solar_system_source'])) {
                $sourceName = $this->sdeService->getLocationName((int)$payload['solar_system_source']);
            }
            $systems[] = [
                'id' => (int)$payload['solar_system_target'],
                'source_system_name' => $sourceName,
            ];
        }

        // Check for nested systems array
        if (isset($payload['systems']) && is_array($payload['systems'])) {
            foreach ($payload['systems'] as $sys) {
                $sysId = $sys['solar_system_id'] ?? $sys['id'] ?? null;
                if (is_numeric($sysId)) {
                    $systems[] = [
                        'id' => (int)$sysId,
                        'source_system_name' => $sys['source_system_name'] ?? null,
                    ];
                }
            }
        }

        return $systems;
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
}
