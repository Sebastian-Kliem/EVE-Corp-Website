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

    private function _createClient(MockHttpClient $httpClient): EsiClient
    {
        return new EsiClient(
            $httpClient,
            $this->createStub(EntityManagerInterface::class),
            new ArrayAdapter(),
            new NullLogger(),
            'client-id',
            'secret',
            'https://example.org/callback',
            ''
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
