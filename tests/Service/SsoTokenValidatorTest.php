<?php

namespace App\Tests\Service;

use App\Service\Esi\SsoTokenValidator;
use Firebase\JWT\JWT;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

class SsoTokenValidatorTest extends TestCase
{
    private const CLIENT_ID = 'client-id';
    private const NOW = '2026-10-04 12:00:00';

    private static \OpenSSLAsymmetricKey $signingKey;
    private static \OpenSSLAsymmetricKey $foreignKey;

    public static function setUpBeforeClass(): void
    {
        self::$signingKey = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        self::$foreignKey = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
    }

    public function testValidTokenReturnsClaims(): void
    {
        $claims = $this->_createValidator(new MockHttpClient($this->_createJwksResponse()))->validate($this->_createToken());

        $this->assertSame('CHARACTER:EVE:2112000000', $claims['sub']);
    }

    public function testTokenSignedWithForeignKeyIsRejected(): void
    {
        $this->expectException(\UnexpectedValueException::class);
        $this->_createValidator(new MockHttpClient($this->_createJwksResponse()))->validate($this->_createToken(signingKey: self::$foreignKey));
    }

    public function testTokenForOtherApplicationIsRejected(): void
    {
        $this->expectExceptionMessage('not issued for this application');
        $this->_createValidator(new MockHttpClient($this->_createJwksResponse()))->validate($this->_createToken(['aud' => ['other-client', 'EVE Online']]));
    }

    public function testTokenFromOtherIssuerIsRejected(): void
    {
        $this->expectExceptionMessage('unexpected issuer');
        $this->_createValidator(new MockHttpClient($this->_createJwksResponse()))->validate($this->_createToken(['iss' => 'https://evil.example']));
    }

    public function testExpiredTokenIsRejected(): void
    {
        $this->expectException(\Firebase\JWT\ExpiredException::class);
        $expiresAt = (new \DateTimeImmutable(self::NOW . ' UTC'))->modify('-5 minutes')->getTimestamp();
        $this->_createValidator(new MockHttpClient($this->_createJwksResponse()))->validate($this->_createToken(['exp' => $expiresAt]));
    }

    public function testRotatedKeyIsFetchedAgain(): void
    {
        $httpClient = new MockHttpClient([$this->_createJwksResponse('old-key', self::$foreignKey), $this->_createJwksResponse()]);
        $validator = $this->_createValidator($httpClient);

        $this->assertSame('CHARACTER:EVE:2112000000', $validator->validate($this->_createToken())['sub']);
        $this->assertSame(2, $httpClient->getRequestsCount());
    }

    private function _createValidator(MockHttpClient $httpClient): SsoTokenValidator
    {
        return new SsoTokenValidator($httpClient, new ArrayAdapter(), new MockClock(self::NOW, 'UTC'), self::CLIENT_ID);
    }

    private function _createToken(array $overrides = [], ?\OpenSSLAsymmetricKey $signingKey = null): string
    {
        $issuedAt = (new \DateTimeImmutable(self::NOW . ' UTC'))->getTimestamp();
        $claims = array_merge([
            'scp' => ['esi-skills.read_skills.v1'],
            'sub' => 'CHARACTER:EVE:2112000000',
            'name' => 'Test Pilot',
            'owner' => 'owner-hash',
            'iss' => 'https://login.eveonline.com',
            'aud' => [self::CLIENT_ID, 'EVE Online'],
            'iat' => $issuedAt,
            'exp' => $issuedAt + 1200,
        ], $overrides);

        return JWT::encode($claims, $signingKey ?? self::$signingKey, 'RS256', 'JWT-Signature-Key');
    }

    private function _createJwksResponse(string $keyId = 'JWT-Signature-Key', ?\OpenSSLAsymmetricKey $key = null): MockResponse
    {
        $details = openssl_pkey_get_details($key ?? self::$signingKey);
        $jwk = [
            'alg' => 'RS256',
            'kty' => 'RSA',
            'use' => 'sig',
            'kid' => $keyId,
            'n' => JWT::urlsafeB64Encode($details['rsa']['n']),
            'e' => JWT::urlsafeB64Encode($details['rsa']['e']),
        ];

        return new MockResponse(json_encode(['keys' => [$jwk], 'SkipUnresolvedJsonWebKeys' => true]));
    }
}
