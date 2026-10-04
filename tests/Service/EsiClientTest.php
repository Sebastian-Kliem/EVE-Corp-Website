<?php

namespace App\Tests\Service;

use App\Entity\EveCharacter;
use App\Service\Esi\EsiClient;
use App\Service\Esi\EsiMissingScopeException;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

class EsiClientTest extends TestCase
{
    protected function setUp(): void
    {
        // EsiClient refuses all requests during the daily ESI downtime window
        $timeUtc = (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format('H:i');
        if ($timeUtc >= '10:50' && $timeUtc <= '11:30') {
            $this->markTestSkipped('ESI downtime window');
        }
    }

    public function testMissingScopeIsDetectedAndCachedWithoutRefresh(): void
    {
        $requestCount = 0;
        $httpClient = new MockHttpClient(function () use (&$requestCount) {
            $requestCount++;
            return new MockResponse(
                '{"error":"Unauthorized - Token is not valid for any required scope: esi-skills.read_skills.v1"}',
                ['http_code' => 401]
            );
        });
        $esiClient = $this->_createClient($httpClient);
        $character = $this->_createCharacter(['esi-wallet.read_character_wallet.v1']);

        try {
            $esiClient->request('GET', 'characters/123/skills/', [], $character);
            $this->fail('Expected EsiMissingScopeException');
        } catch (EsiMissingScopeException $e) {
            $this->assertSame('esi-skills.read_skills.v1', $e->getRequiredScope());
            $this->assertStringContainsString(EsiMissingScopeException::MESSAGE_MARKER, $e->getMessage());
        }

        $this->expectException(EsiMissingScopeException::class);
        try {
            $esiClient->request('GET', 'characters/123/skills/', [], $character);
        } finally {
            // Second call is answered from the cache, no refresh and no further ESI request
            $this->assertSame(1, $requestCount);
            $this->assertTrue($character->isTokenValid());
        }
    }

    public function testCacheEntryIsIgnoredAfterScopesChange(): void
    {
        $responses = [
            new MockResponse('{"error":"Unauthorized - Token is not valid for any required scope: esi-skills.read_skills.v1"}', ['http_code' => 401]),
            new MockResponse('{"total_sp":1000}', ['http_code' => 200]),
        ];
        $esiClient = $this->_createClient(new MockHttpClient($responses));
        $character = $this->_createCharacter(['esi-wallet.read_character_wallet.v1']);

        try {
            $esiClient->request('GET', 'characters/123/skills/', [], $character);
        } catch (EsiMissingScopeException $e) {
            // expected
        }

        // Re-linked with the missing scope: the cached negative result no longer applies
        $character->setAccessToken($this->_createAccessToken(['esi-wallet.read_character_wallet.v1', 'esi-skills.read_skills.v1']));

        $this->assertSame(['total_sp' => 1000], $esiClient->request('GET', 'characters/123/skills/', [], $character));
    }

    public function testGetMissingScopesComparesTokenWithConfiguredScopes(): void
    {
        $esiClient = $this->_createClient(new MockHttpClient(), 'esi-skills.read_skills.v1, esi-wallet.read_character_wallet.v1,esi-assets.read_assets.v1');
        $character = $this->_createCharacter(['esi-wallet.read_character_wallet.v1']);

        $this->assertSame(['esi-skills.read_skills.v1', 'esi-assets.read_assets.v1'], $esiClient->getMissingScopes($character));

        $character->setAccessToken('not-a-jwt');
        $this->assertSame([], $esiClient->getMissingScopes($character));
    }

    public function testErrorResponseWithLowBudgetPausesAllRequests(): void
    {
        $cachePool = new ArrayAdapter();
        $httpClient = new MockHttpClient(new MockResponse('{"error":"Forbidden"}', [
            'http_code' => 403,
            'response_headers' => ['X-ESI-Error-Limit-Remain' => '5', 'X-ESI-Error-Limit-Reset' => '30'],
        ]));
        $esiClient = $this->_createClient($httpClient, '', $cachePool);

        try {
            $esiClient->request('GET', 'universe/structures/1000000000001/', [], $this->_createCharacter([]));
            $this->fail('Expected the 403 to be rethrown');
        } catch (\Symfony\Contracts\HttpClient\Exception\HttpExceptionInterface $e) {
            $this->assertSame(403, $e->getResponse()->getStatusCode());
        }

        $pauseItem = $cachePool->getItem('esi_error_limit_pause_until');
        $this->assertTrue($pauseItem->isHit());
        $this->assertEqualsWithDelta(time() + 31, $pauseItem->get(), 2);
    }

    public function testRateLimitedRequestIsRetriedAfterRetryAfter(): void
    {
        $responses = [
            new MockResponse('{"error":"Too many requests"}', ['http_code' => 429, 'response_headers' => ['Retry-After' => '1']]),
            new MockResponse('{"total_sp":1000}', ['http_code' => 200]),
        ];
        $httpClient = new MockHttpClient($responses);
        $esiClient = $this->_createClient($httpClient);

        $startTime = microtime(true);
        $this->assertSame(['total_sp' => 1000], $esiClient->request('GET', 'characters/123/skills/', [], $this->_createCharacter([])));
        $this->assertSame(2, $httpClient->getRequestsCount());
        $this->assertGreaterThanOrEqual(1.0, microtime(true) - $startTime);
    }

    public function testSingleServerErrorDoesNotTakeEsiOffline(): void
    {
        $responses = [
            new MockResponse('{"error":"Gateway timeout"}', ['http_code' => 504]),
            new MockResponse('{"total_sp":1000}', ['http_code' => 200]),
        ];
        $esiClient = $this->_createClient(new MockHttpClient($responses));

        $this->assertSame(['total_sp' => 1000], $esiClient->request('GET', 'characters/123/skills/', [], $this->_createCharacter([])));
        $this->assertFalse($esiClient->isOffline());
    }

    public function testConsecutiveServerErrorsOpenCircuitBreaker(): void
    {
        $httpClient = new MockHttpClient(function () {
            return new MockResponse('{"error":"Bad gateway"}', ['http_code' => 502]);
        });
        $esiClient = $this->_createClient($httpClient);

        // Single attempts per request keep the test free of retry sleeps
        for ($requestNumber = 1; $requestNumber <= 10; $requestNumber++) {
            $this->assertFalse($esiClient->isOffline());
            try {
                $esiClient->requestWithHeaders('GET', 'status/', [], null, 1);
                $this->fail('Expected the 502 to be rethrown');
            } catch (\Symfony\Contracts\HttpClient\Exception\HttpExceptionInterface $e) {
                $this->assertSame(502, $e->getResponse()->getStatusCode());
            }
        }

        // Further requests are rejected without calling ESI
        $this->assertSame(10, $httpClient->getRequestsCount());
        $this->assertTrue($esiClient->isOffline());
        $this->expectExceptionMessage('circuit breaker active');
        $esiClient->request('GET', 'status/');
    }

    private function _createClient(MockHttpClient $httpClient, string $configuredScopes = '', ?ArrayAdapter $cachePool = null): EsiClient
    {
        return new EsiClient(
            $httpClient,
            $this->createStub(EntityManagerInterface::class),
            $cachePool ?? new ArrayAdapter(),
            new NullLogger(),
            'client-id',
            'secret',
            'https://example.org/callback',
            $configuredScopes
        );
    }

    private function _createCharacter(array $scopes): EveCharacter
    {
        $character = new EveCharacter();
        $character->setId(123);
        $character->setName('Test Pilot');
        $character->setRefreshToken('refresh-token');
        $character->setAccessToken($this->_createAccessToken($scopes));
        $character->setTokenExpiresAt(new \DateTimeImmutable('+10 minutes'));

        return $character;
    }

    private function _createAccessToken(array $scopes): string
    {
        $payload = ['sub' => 'CHARACTER:EVE:123', 'name' => 'Test Pilot', 'owner' => 'hash', 'scp' => $scopes];
        $encodedPayload = rtrim(strtr(base64_encode(json_encode($payload)), '+/', '-_'), '=');

        return 'header.' . $encodedPayload . '.signature';
    }
}
