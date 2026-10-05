<?php

namespace App\Tests\Service\Discord;

use App\Service\Discord\DiscordWebhookService;
use App\Service\Discord\Model\DiscordMessage;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;
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
