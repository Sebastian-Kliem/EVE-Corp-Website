<?php

namespace App\Tests\Service\Discord;

use App\Entity\AppSetting;
use App\Service\Discord\DiscordWebhookService;
use App\Service\Discord\Model\DiscordMessage;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\EntityRepository;
use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;
use Psr\Log\NullLogger;
use Symfony\Component\HttpClient\Exception\TransportException;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

class DiscordWebhookServiceTest extends TestCase
{
    private const WEBHOOK_URL = 'https://discord.com/api/webhooks/123/secret-token';

    private array $logMessages = [];

    public function testRateLimitedMessageIsSentAfterRetryAfter(): void
    {
        $httpClient = new MockHttpClient([
            new MockResponse('{"message":"You are being rate limited.","retry_after":0.05,"global":false}', ['http_code' => 429]),
            new MockResponse('', ['http_code' => 204]),
        ]);

        $this->assertTrue($this->_createService($httpClient)->send(DiscordMessage::create('Fuel low'), overrideWebhookUrl: self::WEBHOOK_URL));
        $this->assertSame(2, $httpClient->getRequestsCount());
    }

    public function testLongRateLimitIsNotWaitedFor(): void
    {
        $httpClient = new MockHttpClient([
            new MockResponse('{"message":"You are being rate limited.","retry_after":30,"global":true}', ['http_code' => 429]),
        ]);

        $this->assertFalse($this->_createService($httpClient)->send(DiscordMessage::create('Fuel low'), overrideWebhookUrl: self::WEBHOOK_URL));
        $this->assertSame(1, $httpClient->getRequestsCount());
    }

    public function testClientErrorIsNotRetried(): void
    {
        $httpClient = new MockHttpClient([
            new MockResponse('{"message":"Invalid Webhook Token"}', ['http_code' => 401]),
        ]);

        $this->assertFalse($this->_createService($httpClient)->send(DiscordMessage::create('Fuel low'), overrideWebhookUrl: self::WEBHOOK_URL));
        $this->assertSame(1, $httpClient->getRequestsCount());
    }

    public function testWebhookTokenIsRedactedFromTransportErrors(): void
    {
        $httpClient = new MockHttpClient(function (string $method, string $url) {
            throw new TransportException(sprintf('Idle timeout reached for "%s".', $url));
        });

        $this->assertFalse($this->_createService($httpClient)->send(DiscordMessage::create('Fuel low'), overrideWebhookUrl: self::WEBHOOK_URL));
        $this->assertNotEmpty($this->logMessages);
        foreach ($this->logMessages as $logMessage) {
            $this->assertStringNotContainsString('secret-token', $logMessage);
        }
    }

    public function testSubmittedSettingsLeaveFieldsOfOtherFormsUntouched(): void
    {
        $storedSettings = ['discord_webhook_wanderer' => self::WEBHOOK_URL, 'discord_ping_role_wanderer' => '@here'];
        $service = $this->_createServiceWithSettings($storedSettings);

        // The Discord settings page has no Wanderer fields
        $service->saveSubmittedSettings(['discord_webhook_fuel' => 'https://discord.com/api/webhooks/1/fuel', 'discord_ping_role_fuel' => '123']);

        $this->assertSame(self::WEBHOOK_URL, $storedSettings['discord_webhook_wanderer']->getValue());
        $this->assertSame('@here', $storedSettings['discord_ping_role_wanderer']->getValue());
        $this->assertSame('https://discord.com/api/webhooks/1/fuel', $storedSettings['discord_webhook_fuel']->getValue());
    }

    public function testEmptySecretKeepsStoredValueButEmptyPlainSettingClears(): void
    {
        $storedSettings = ['wanderer_api_key' => 'stored-key-1234', 'discord_ping_role_wanderer' => '@here'];
        $service = $this->_createServiceWithSettings($storedSettings);

        $service->saveSubmittedSettings(['wanderer_api_key' => '', 'discord_ping_role_wanderer' => '']);

        $this->assertSame('stored-key-1234', $storedSettings['wanderer_api_key']->getValue());
        $this->assertNull($storedSettings['discord_ping_role_wanderer']->getValue());
    }

    public function testRemoveKeyClearsSecret(): void
    {
        $storedSettings = ['wanderer_api_key' => 'stored-key-1234'];
        $service = $this->_createServiceWithSettings($storedSettings);

        $service->saveSubmittedSettings(['wanderer_api_key' => ''], ['wanderer_api_key']);

        $this->assertNull($storedSettings['wanderer_api_key']->getValue());
    }

    public function testMaskedSettingsOnlyShowTheEndOfSecrets(): void
    {
        $storedSettings = ['discord_webhook_default' => self::WEBHOOK_URL, 'wanderer_api_url' => 'https://wanderer.example'];
        $settings = $this->_createServiceWithSettings($storedSettings)->getAllSettingsMasked();

        $this->assertSame('...oken', $settings['discord_webhook_default']);
        $this->assertSame('https://wanderer.example', $settings['wanderer_api_url']);
        $this->assertNull($settings['discord_webhook_fuel']);
    }

    /**
     * Backs the AppSetting repository with the given array; values are replaced by entities in place.
     *
     * @param array<string, mixed> $storedSettings
     */
    private function _createServiceWithSettings(array &$storedSettings): DiscordWebhookService
    {
        foreach ($storedSettings as $key => $value) {
            $storedSettings[$key] = new AppSetting($key, $value);
        }

        $repository = $this->createStub(EntityRepository::class);
        $repository->method('find')->willReturnCallback(function (string $key) use (&$storedSettings) {
            return $storedSettings[$key] ?? null;
        });

        $entityManager = $this->createStub(EntityManagerInterface::class);
        $entityManager->method('getRepository')->willReturn($repository);
        $entityManager->method('persist')->willReturnCallback(function (AppSetting $setting) use (&$storedSettings): void {
            $storedSettings[$setting->getKey()] = $setting;
        });

        return new DiscordWebhookService(new MockHttpClient(), $entityManager, new NullLogger());
    }

    private function _createService(MockHttpClient $httpClient): DiscordWebhookService
    {
        $logMessages = &$this->logMessages;
        $logger = new class($logMessages) extends AbstractLogger {
            // @phpstan-ignore property.onlyWritten (written by reference, read by the test)
            public function __construct(private array &$messages)
            {
            }

            public function log($level, \Stringable|string $message, array $context = []): void
            {
                $this->messages[] = (string) $message;
            }
        };

        return new DiscordWebhookService($httpClient, $this->createStub(EntityManagerInterface::class), $logger);
    }
}
