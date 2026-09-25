<?php

namespace App\Service\Discord;

use App\Entity\AppSetting;
use App\Service\Discord\Model\DiscordMessage;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

class DiscordWebhookService
{
    public const CHANNEL_DEFAULT = 'default';
    public const CHANNEL_STRUCTURES = 'structures';
    public const CHANNEL_FUEL = 'fuel';
    public const CHANNEL_COMBAT = 'combat';
    public const CHANNEL_USER_ALERTS = 'user_alerts';
    public const CHANNEL_INDUSTRY = 'industry';
    public const CHANNEL_MARKET = 'market';
    public const CHANNEL_WANDERER = 'wanderer';

    public const SETTING_KEYS = [
        'discord_webhook_default' => self::CHANNEL_DEFAULT,
        'discord_webhook_structures' => self::CHANNEL_STRUCTURES,
        'discord_webhook_fuel' => self::CHANNEL_FUEL,
        'discord_webhook_combat' => self::CHANNEL_COMBAT,
        'discord_webhook_user_alerts' => self::CHANNEL_USER_ALERTS,
        'discord_webhook_industry' => self::CHANNEL_INDUSTRY,
        'discord_webhook_market' => self::CHANNEL_MARKET,
        'discord_webhook_wanderer' => self::CHANNEL_WANDERER,
        'discord_ping_role_structure_defense' => 'ping_defense',
        'discord_ping_role_fuel' => 'ping_fuel',
        'discord_ping_role_wanderer' => 'ping_wanderer',
        'wanderer_webhook_secret' => 'wanderer_secret',
    ];

    public function __construct(
        private readonly HttpClientInterface $httpClient,
        private readonly EntityManagerInterface $entityManager,
        private readonly LoggerInterface $logger
    ) {}

    /**
     * Gets a setting value from the database.
     */
    public function getSetting(string $key, ?string $default = null): ?string
    {
        try {
            $repo = $this->entityManager->getRepository(AppSetting::class);
            $setting = $repo->find($key);
            if ($setting !== null && $setting->getValue() !== null && trim($setting->getValue()) !== '') {
                return trim($setting->getValue());
            }
        } catch (\Throwable $e) {
            // Database might not be initialized or connection issue
        }

        return $default;
    }

    /**
     * Saves multiple settings in the database.
     */
    public function saveSettings(array $settings): void
    {
        $repo = $this->entityManager->getRepository(AppSetting::class);

        foreach ($settings as $key => $value) {
            if (!array_key_exists($key, self::SETTING_KEYS)) {
                continue;
            }

            $cleanValue = $value !== null ? trim((string)$value) : null;
            if ($cleanValue === '') {
                $cleanValue = null;
            }

            $setting = $repo->find($key);
            if ($setting === null) {
                $setting = new AppSetting($key, $cleanValue);
                $this->entityManager->persist($setting);
            } else {
                $setting->setValue($cleanValue);
            }
        }

        $this->entityManager->flush();
    }

    /**
     * Returns all current setting values (merged from DB and .env).
     */
    public function getAllSettings(): array
    {
        $result = [];
        foreach (array_keys(self::SETTING_KEYS) as $key) {
            $result[$key] = $this->getSetting($key);
        }
        return $result;
    }

    /**
     * Checks if a webhook is configured for the given channel or fallback.
     */
    public function isConfigured(string $channel = self::CHANNEL_DEFAULT): bool
    {
        return !empty($this->resolveWebhookUrl($channel));
    }

    /**
     * Resolves the webhook URL for a channel with fallback to 'default'.
     */
    public function resolveWebhookUrl(string $channel = self::CHANNEL_DEFAULT): ?string
    {
        $key = 'discord_webhook_' . $channel;
        $url = $this->getSetting($key);

        if (!empty($url)) {
            return $url;
        }

        // Fallback to default
        return $this->getSetting('discord_webhook_default');
    }

    /**
     * Returns the formatted role mention string or null if not configured.
     */
    public function getStructureDefensePing(): ?string
    {
        $role = $this->getSetting('discord_ping_role_structure_defense');
        if (empty($role)) {
            return null;
        }

        $role = trim($role);
        if ($role === '@here' || $role === '@everyone' || str_starts_with($role, '<@&')) {
            return $role;
        }

        return '<@&' . $role . '>';
    }

    /**
     * Returns the formatted role mention string for fuel alerts.
     */
    public function getFuelPing(): ?string
    {
        $role = $this->getSetting('discord_ping_role_fuel');
        if (empty($role)) {
            return null;
        }

        $role = trim($role);
        if ($role === '@here' || $role === '@everyone' || str_starts_with($role, '<@&')) {
            return $role;
        }

        return '<@&' . $role . '>';
    }

    /**
     * Returns the formatted role mention string for wanderer alerts.
     */
    public function getWandererPing(): ?string
    {
        $role = $this->getSetting('discord_ping_role_wanderer');
        if (empty($role)) {
            return null;
        }

        $role = trim($role);
        if ($role === '@here' || $role === '@everyone' || str_starts_with($role, '<@&')) {
            return $role;
        }

        return '<@&' . $role . '>';
    }

    /**
     * Returns the configured Wanderer Webhook secret for HMAC signature verification.
     */
    public function getWandererSecret(): ?string
    {
        return $this->getSetting('wanderer_webhook_secret');
    }

    /**
     * Sends a Discord message to the resolved channel or custom webhook URL.
     */
    public function send(
        DiscordMessage $message,
        string $channel = self::CHANNEL_DEFAULT,
        ?string $overrideWebhookUrl = null
    ): bool {
        $url = $overrideWebhookUrl ?: $this->resolveWebhookUrl($channel);

        if (empty($url)) {
            $this->logger->info(sprintf(
                '[Discord] Skipped sending message for channel "%s" (No webhook URL configured).',
                $channel
            ));
            return false;
        }

        $payload = $message->toArray();
        if (empty($payload)) {
            $this->logger->warning('[Discord] Attempted to send empty Discord payload.');
            return false;
        }

        try {
            $response = $this->httpClient->request('POST', $url, [
                'json' => $payload,
                'timeout' => 8.0,
            ]);

            $statusCode = $response->getStatusCode();
            if ($statusCode >= 200 && $statusCode < 300) {
                $this->logger->info(sprintf(
                    '[Discord] Successfully sent notification to channel "%s" (HTTP %d).',
                    $channel,
                    $statusCode
                ));
                return true;
            }

            $this->logger->error(sprintf(
                '[Discord] Failed to send notification to channel "%s". Status: %d, Response: %s',
                $channel,
                $statusCode,
                $response->getContent(false)
            ));
            return false;
        } catch (\Throwable $e) {
            $this->logger->error(sprintf(
                '[Discord] Exception while sending webhook to channel "%s": %s',
                $channel,
                $e->getMessage()
            ));
            return false;
        }
    }
}
