<?php

namespace App\Tests\Service\Wanderer;

use App\Service\Discord\DiscordWebhookService;
use App\Service\Wanderer\WandererApiClient;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

class WandererApiClientTest extends TestCase
{
    private const URL = 'https://wanderer.example.org';

    public function testSuccessfulConnectionReportsConnectionCount(): void
    {
        $requestedUrl = null;
        $authorization = null;
        $httpClient = new MockHttpClient(function (string $method, string $url, array $options) use (&$requestedUrl, &$authorization) {
            $requestedUrl = $url;
            $authorization = $options['normalized_headers']['authorization'][0] ?? null;
            return new MockResponse('{"data":[{"id":"a"},{"id":"b"}]}');
        });

        $result = $this->_createClient($httpClient)->testConnection(self::URL . '/', 'keepers-of-duat', 'secret-key');

        $this->assertTrue($result['success']);
        $this->assertStringContainsString('2 Verbindung(en)', $result['message']);
        $this->assertSame(self::URL . '/api/maps/keepers-of-duat/connections', $requestedUrl);
        $this->assertSame('Authorization: Bearer secret-key', $authorization);
    }

    /**
     * @return array<string, array{MockResponse, string}>
     */
    public static function failureProvider(): array
    {
        return [
            'wrong API key' => [new MockResponse('{"error":"Unauthorized (invalid token for map)"}', ['http_code' => 401]), 'Map-API-Key'],
            'unknown map slug' => [new MockResponse('{"error":"Map not found for identifier: x"}', ['http_code' => 404]), 'Map-Slug'],
            'URL is no Wanderer' => [new MockResponse('<html>Not found</html>', ['http_code' => 404]), 'Wanderer-URL'],
            'host unreachable' => [new MockResponse('', ['error' => 'Could not resolve host']), 'nicht erreichbar'],
            'unexpected payload' => [new MockResponse('{"maps":[]}'), 'keine Wanderer-API'],
        ];
    }

    #[DataProvider('failureProvider')]
    public function testFailuresExplainWhatToCheck(MockResponse $response, string $expectedHint): void
    {
        $result = $this->_createClient(new MockHttpClient($response))->testConnection(self::URL, 'keepers-of-duat', 'secret-key');

        $this->assertFalse($result['success']);
        $this->assertStringContainsString($expectedHint, $result['message']);
    }

    public function testIncompleteInputIsRejectedWithoutRequest(): void
    {
        $client = $this->_createClient(new MockHttpClient([]));

        $this->assertFalse($client->testConnection('', 'keepers-of-duat', 'secret-key')['success']);
        $this->assertFalse($client->testConnection(self::URL, 'keepers-of-duat', ' ')['success']);
        $this->assertStringContainsString('https://', $client->testConnection('wanderer.example.org', 'keepers-of-duat', 'secret-key')['message']);
    }

    private function _createClient(MockHttpClient $httpClient): WandererApiClient
    {
        return new WandererApiClient($httpClient, $this->createStub(DiscordWebhookService::class));
    }
}
