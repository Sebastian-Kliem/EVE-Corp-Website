<?php

namespace App\Tests\Service;

use App\Entity\EveCharacter;
use App\Service\Cron\CronLogWriter;
use App\Service\Esi\EsiClient;
use App\Service\Esi\EsiMissingScopeException;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

class EsiClientTest extends TestCase
{
    // Outside the daily downtime window, so no cluster status request is made
    private const DEFAULT_TIME = '2026-10-04 09:00:00';

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

    public function testDowntimeLastsUntilClusterRestartedAfterEleven(): void
    {
        $clock = new MockClock('2026-10-04 11:05:00', 'UTC');
        $notRestarted = new MockHttpClient(new MockResponse('{"players":20000,"vip":false,"start_time":"2026-10-03T11:02:00Z"}'));
        $this->assertTrue($this->_createClient($notRestarted, clock: $clock)->isOffline());

        $unreachable = new MockHttpClient(new MockResponse('{"error":"The datasource tranquility is temporarily unavailable"}', ['http_code' => 503]));
        $this->assertTrue($this->_createClient($unreachable, clock: $clock)->isOffline());

        $vipMode = new MockHttpClient(new MockResponse('{"players":50,"vip":true,"start_time":"2026-10-04T11:03:00Z"}'));
        $this->assertTrue($this->_createClient($vipMode, clock: $clock)->isOffline());

        $restarted = new MockHttpClient(new MockResponse('{"players":12000,"vip":false,"start_time":"2026-10-04T11:03:00Z"}'));
        $this->assertFalse($this->_createClient($restarted, clock: $clock)->isOffline());

        // Before 11:00 and after 12:00 no status request is needed
        $noRequests = new MockHttpClient([]);
        $this->assertFalse($this->_createClient($noRequests, clock: new MockClock('2026-10-04 10:59:00', 'UTC'))->isOffline());
        $this->assertFalse($this->_createClient($noRequests, clock: new MockClock('2026-10-04 12:00:00', 'UTC'))->isOffline());
        $this->assertSame(0, $noRequests->getRequestsCount());
    }

    public function testRouteIsNotRequestedForWormholeSystems(): void
    {
        $noRequests = new MockHttpClient([]);
        $esiClient = $this->_createClient($noRequests);

        $this->assertNull($esiClient->getRoute(31001506, 30000142, 'shortest'));
        $this->assertNull($esiClient->getRoute(30000142, 31001506, 'shortest'));
        $this->assertSame(0, $noRequests->getRequestsCount());
    }

    public function testQuietDowntimeCheckLogsUnreachableClusterOnlyAsDebug(): void
    {
        $clock = new MockClock('2026-10-04 11:05:00', 'UTC');
        $logger = new class extends AbstractLogger {
            public array $levels = [];

            public function log($level, \Stringable|string $message, array $context = []): void
            {
                $this->levels[] = $level;
            }
        };

        $unreachable = new MockHttpClient([new MockResponse('', ['http_code' => 502]), new MockResponse('', ['http_code' => 502])]);
        $this->assertTrue($this->_createClient($unreachable, clock: $clock, logger: $logger)->isOffline(true));
        $this->assertSame(['debug'], $logger->levels);

        $this->assertTrue($this->_createClient($unreachable, clock: $clock, logger: $logger)->isOffline());
        $this->assertSame(['debug', 'info'], $logger->levels);
    }

    public function testWebRequestShowsLastKnownDataDuringDowntime(): void
    {
        $clock = new MockClock('2026-10-04 10:30:00', 'UTC');
        $httpClient = new MockHttpClient([
            $this->_createExpiringResponse('{"name":"Keepers of Duat"}', $clock),
            new MockResponse('{"error":"The datasource tranquility is temporarily unavailable"}', ['http_code' => 503]),
        ]);
        $esiClient = $this->_createClient($httpClient, clock: $clock, isWebRequest: true);

        $esiClient->request('GET', 'corporations/98000001/');
        $clock->modify('2026-10-04 11:05:00');
        $result = $esiClient->requestWithHeaders('GET', 'corporations/98000001/');

        $this->assertSame(['name' => 'Keepers of Duat'], $result['data']);
        $this->assertTrue($result['stale']);
        // Only the status check went to ESI, the corporation itself was not requested again
        $this->assertSame(2, $httpClient->getRequestsCount());
    }

    public function testCronRequestDoesNotUseExpiredDataDuringDowntime(): void
    {
        $clock = new MockClock('2026-10-04 10:30:00', 'UTC');
        $httpClient = new MockHttpClient([
            $this->_createExpiringResponse('{"name":"Keepers of Duat"}', $clock),
            new MockResponse('{"error":"The datasource tranquility is temporarily unavailable"}', ['http_code' => 503]),
        ]);
        $esiClient = $this->_createClient($httpClient, clock: $clock, isWebRequest: false);

        $esiClient->request('GET', 'corporations/98000001/');
        $clock->modify('2026-10-04 11:05:00');

        $this->expectExceptionMessage('EVE downtime');
        $esiClient->request('GET', 'corporations/98000001/');
    }

    public function testWebRequestShowsLastKnownDataOnServerError(): void
    {
        $clock = new MockClock(self::DEFAULT_TIME, 'UTC');
        $httpClient = new MockHttpClient([
            $this->_createExpiringResponse('{"name":"Keepers of Duat"}', $clock),
            new MockResponse('{"error":"Bad gateway"}', ['http_code' => 502]),
        ]);
        $esiClient = $this->_createClient($httpClient, clock: $clock, isWebRequest: true);

        $esiClient->request('GET', 'corporations/98000001/');
        $clock->modify('+5 minutes');
        $result = $esiClient->requestWithHeaders('GET', 'corporations/98000001/');

        // Served right after the first failure, without retry sleeps
        $this->assertTrue($result['stale']);
        $this->assertSame(['name' => 'Keepers of Duat'], $result['data']);
        $this->assertSame(2, $httpClient->getRequestsCount());
    }

    public function testRevokedRefreshTokenMarksCharacterInvalidWithHourlyRetry(): void
    {
        $clock = new MockClock(self::DEFAULT_TIME, 'UTC');
        $esiClient = $this->_createClient(new MockHttpClient(new MockResponse('{"error":"invalid_grant","error_description":"Invalid refresh token."}', ['http_code' => 400])), clock: $clock);
        $character = $this->_createCharacter([]);

        $this->assertFalse($esiClient->refreshToken($character));

        $this->assertFalse($character->isTokenValid());
        $this->assertEquals($clock->now()->modify('+1 hour'), $character->getTokenRetryAt());
    }

    public function testRevokedTokenIsRetriedOnlyAfterRetryTime(): void
    {
        $clock = new MockClock(self::DEFAULT_TIME, 'UTC');
        $requestCount = 0;
        $httpClient = new MockHttpClient(function () use (&$requestCount) {
            $requestCount++;
            return new MockResponse('{"access_token":"new-access-token","refresh_token":"new-refresh-token","expires_in":1199}');
        });
        $esiClient = $this->_createClient($httpClient, clock: $clock);
        $character = $this->_createCharacter([]);
        $character->markTokenRevoked($clock->now()->modify('+30 minutes'));

        $this->assertFalse($esiClient->refreshToken($character));
        $this->assertSame(0, $requestCount);

        $clock->modify('+31 minutes');
        $this->assertTrue($esiClient->refreshToken($character));
        $this->assertSame(1, $requestCount);
        $this->assertTrue($character->isTokenValid());
        $this->assertNull($character->getTokenRetryAt());
    }

    public function testRejectedApplicationCredentialsKeepCharacterValid(): void
    {
        $esiClient = $this->_createClient(new MockHttpClient(new MockResponse('{"error":"invalid_client","error_description":"Client authentication failed."}', ['http_code' => 401])));
        $character = $this->_createCharacter([]);

        $this->assertFalse($esiClient->refreshToken($character));

        $this->assertTrue($character->isTokenValid());
        $this->assertNull($character->getTokenRetryAt());
    }

    public function testTemporarySsoFailuresKeepCharacterValid(): void
    {
        $responses = [
            new MockResponse('{"error":"Service unavailable"}', ['http_code' => 503]),
            new MockResponse('', ['error' => 'Idle timeout reached for "https://login.eveonline.com/v2/oauth/token".']),
            new MockResponse('{"error":"invalid_request"}', ['http_code' => 400]),
        ];
        $esiClient = $this->_createClient(new MockHttpClient($responses));
        $character = $this->_createCharacter([]);

        foreach ($responses as $ignored) {
            $this->assertFalse($esiClient->refreshToken($character));
            $this->assertTrue($character->isTokenValid());
        }
    }

    public function testEsiUnauthorizedWithFailedRefreshDoesNotInvalidateToken(): void
    {
        $esiClient = $this->_createClient(new MockHttpClient([
            new MockResponse('{"error":"token is expired"}', ['http_code' => 401]),
            new MockResponse('{"error":"Service unavailable"}', ['http_code' => 503]),
        ]));
        $character = $this->_createCharacter([]);

        try {
            $esiClient->request('GET', 'characters/123/skills/', [], $character);
            $this->fail('Expected the original 401 to be rethrown');
        } catch (\Symfony\Contracts\HttpClient\Exception\HttpExceptionInterface $e) {
            $this->assertSame(401, $e->getResponse()->getStatusCode());
        }

        $this->assertTrue($character->isTokenValid());
    }

    private function _createExpiringResponse(string $body, MockClock $clock): MockResponse
    {
        $expiresAt = $clock->now()->modify('+60 seconds')->getTimestamp();

        return new MockResponse($body, ['response_headers' => ['Expires' => gmdate('D, d M Y H:i:s \G\M\T', $expiresAt)]]);
    }

    private function _createClient(MockHttpClient $httpClient, string $configuredScopes = '', ?ArrayAdapter $cachePool = null, ?MockClock $clock = null, bool $isWebRequest = false, ?LoggerInterface $logger = null): EsiClient
    {
        return new EsiClient(
            $httpClient,
            $this->createStub(EntityManagerInterface::class),
            $cachePool ?? new ArrayAdapter(),
            $logger ?? new NullLogger(),
            'client-id',
            'secret',
            'https://example.org/callback',
            $configuredScopes,
            $clock ?? new MockClock(self::DEFAULT_TIME, 'UTC'),
            new CronLogWriter(sys_get_temp_dir() . '/wh-toolbox-test-cron.log', 'error'),
            $isWebRequest
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
