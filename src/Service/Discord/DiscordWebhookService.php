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
        'wanderer_api_url' => 'wanderer_api_url',
        'wanderer_map_slug' => 'wanderer_map_slug',
        'wanderer_api_key' => 'wanderer_api_key',
    ];

    // Settings that are never rendered back into a form; an empty submission keeps the stored value
    public const SECRET_SETTING_KEYS = [
        'discord_webhook_default',
        'discord_webhook_structures',
        'discord_webhook_fuel',
        'discord_webhook_combat',
        'discord_webhook_user_alerts',
        'discord_webhook_industry',
        'discord_webhook_market',
        'discord_webhook_wanderer',
        'wanderer_api_key',
    ];

    private const MAX_ATTEMPTS = 3;
    // Longer rate limit waits are not worth blocking a cron run or page; the caller retries later
    private const MAX_RETRY_WAIT_SECONDS = 10.0;
    private const SERVER_ERROR_RETRY_SECONDS = 1.0;

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
     * Saves the settings a form submitted. Keys missing from the submission are left untouched,
     * secrets are only replaced by a non-empty value and cleared via $removeKeys.
     *
     * @param array<string, mixed> $submittedValues
     * @param string[] $removeKeys
     */
    public function saveSubmittedSettings(array $submittedValues, array $removeKeys = []): void
    {
        $settingsToSave = [];
        foreach (array_keys(self::SETTING_KEYS) as $key) {
            $isSecret = in_array($key, self::SECRET_SETTING_KEYS, true);
            if ($isSecret && in_array($key, $removeKeys, true)) {
                $settingsToSave[$key] = null;
                continue;
            }
            if (!array_key_exists($key, $submittedValues)) {
                continue;
            }

            $value = trim((string)$submittedValues[$key]);
            if ($isSecret && $value === '') {
                continue;
            }
            $settingsToSave[$key] = $value;
        }

        $this->saveSettings($settingsToSave);
    }

    /**
     * All settings with secrets reduced to a short hint, safe to render into a page.
     *
     * @return array<string, ?string>
     */
    public function getAllSettingsMasked(): array
    {
        $settings = $this->getAllSettings();
        foreach (self::SECRET_SETTING_KEYS as $key) {
            if ($settings[$key] !== null) {
                $settings[$key] = self::maskSecret($settings[$key]);
            }
        }

        return $settings;
    }

    /**
     * Shows only the last four characters of a secret.
     */
    public static function maskSecret(string $secret): string
    {
        return mb_strlen($secret) > 8 ? '...' . mb_substr($secret, -4) : '...';
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

        for ($attempt = 1; $attempt <= self::MAX_ATTEMPTS; $attempt++) {
            $retryWaitSeconds = $this->_postToWebhook($url, $payload, $channel);
            if ($retryWaitSeconds === null) {
                return true;
            }
            if ($retryWaitSeconds === false || $attempt === self::MAX_ATTEMPTS) {
                return false;
            }
            if ($retryWaitSeconds > self::MAX_RETRY_WAIT_SECONDS) {
                $this->logger->error(sprintf('[Discord] Giving up on channel "%s": rate limited for %.1f seconds.', $channel, $retryWaitSeconds));
                return false;
            }

            $this->logger->warning(sprintf('[Discord] Retrying channel "%s" in %.1f seconds (attempt %d/%d).', $channel, $retryWaitSeconds, $attempt + 1, self::MAX_ATTEMPTS));
            usleep((int) ($retryWaitSeconds * 1_000_000));
        }

        return false;
    }

    // Returns null on success, seconds to wait for a retryable failure, or false for a permanent failure
    private function _postToWebhook(string $url, array $payload, string $channel): float|false|null
    {
        try {
            $response = $this->httpClient->request('POST', $url, [
                'json' => $payload,
                'timeout' => 8.0,
            ]);

            $statusCode = $response->getStatusCode();
            $headers = $response->getHeaders(false);
            if ($statusCode >= 200 && $statusCode < 300) {
                $this->logger->info(sprintf(
                    '[Discord] Successfully sent notification to channel "%s" (HTTP %d).',
                    $channel,
                    $statusCode
                ));
                $this->_waitForExhaustedBucket($headers);
                return null;
            }

            if ($statusCode === 429) {
                $retryWaitSeconds = $this->_getRateLimitWait($response->getContent(false), $headers);
                $this->logger->warning(sprintf('[Discord] Rate limited on channel "%s" (HTTP 429), retry after %.1f seconds.', $channel, $retryWaitSeconds));
                return $retryWaitSeconds;
            }

            $this->logger->error(sprintf(
                '[Discord] Failed to send notification to channel "%s". Status: %d, Response: %s',
                $channel,
                $statusCode,
                $response->getContent(false)
            ));
            return $statusCode >= 500 ? self::SERVER_ERROR_RETRY_SECONDS : false;
        } catch (\Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface $e) {
            $this->logger->error(sprintf(
                '[Discord] Exception while sending webhook to channel "%s": %s',
                $channel,
                $this->_redactWebhookUrl($e->getMessage(), $url)
            ));
            return self::SERVER_ERROR_RETRY_SECONDS;
        } catch (\Throwable $e) {
            $this->logger->error(sprintf(
                '[Discord] Exception while sending webhook to channel "%s": %s',
                $channel,
                $this->_redactWebhookUrl($e->getMessage(), $url)
            ));
            return false;
        }
    }

    // Discord sends retry_after in the JSON body (seconds, fractional) and Retry-After as header
    private function _getRateLimitWait(string $body, array $headers): float
    {
        $data = json_decode($body, true);
        if (is_array($data) && isset($data['retry_after']) && is_numeric($data['retry_after'])) {
            return max(0.0, (float) $data['retry_after']);
        }
        if (isset($headers['retry-after'][0]) && is_numeric($headers['retry-after'][0])) {
            return max(0.0, (float) $headers['retry-after'][0]);
        }

        return self::MAX_RETRY_WAIT_SECONDS;
    }

    // Waits for the bucket reset when the last request used it up, so the next message is not rejected
    private function _waitForExhaustedBucket(array $headers): void
    {
        if (($headers['x-ratelimit-remaining'][0] ?? null) !== '0' || !is_numeric($headers['x-ratelimit-reset-after'][0] ?? null)) {
            return;
        }

        $waitSeconds = min((float) $headers['x-ratelimit-reset-after'][0], self::MAX_RETRY_WAIT_SECONDS);
        usleep((int) ($waitSeconds * 1_000_000));
    }

    // The webhook URL contains its secret token and must not end up in logs
    private function _redactWebhookUrl(string $message, string $url): string
    {
        return str_replace($url, '[webhook URL]', $message);
    }
}
