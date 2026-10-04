<?php

namespace App\Service\Esi;

use Firebase\JWT\JWK;
use Firebase\JWT\JWT;
use Psr\Cache\CacheItemPoolInterface;
use Psr\Clock\ClockInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Verifies EVE SSO access tokens against CCP's published signing keys and the expected claims.
 */
class SsoTokenValidator
{
    private const JWKS_URL = 'https://login.eveonline.com/oauth/jwks';
    private const JWKS_CACHE_KEY = 'eve_sso_jwks';
    private const JWKS_CACHE_TTL = 86400;
    private const ACCEPTED_ISSUERS = ['login.eveonline.com', 'https://login.eveonline.com'];
    private const EVE_AUDIENCE = 'EVE Online';
    private const CLOCK_LEEWAY_SECONDS = 60;

    public function __construct(
        private readonly HttpClientInterface $httpClient,
        private readonly CacheItemPoolInterface $cachePool,
        private readonly ClockInterface $clock,
        #[Autowire('%env(EVE_SSO_CLIENT_ID)%')]
        private readonly string $eveSsoClientId,
    ) {}

    /**
     * Returns the verified token claims or throws \UnexpectedValueException.
     */
    public function validate(string $accessToken): array
    {
        try {
            $claims = $this->_decode($accessToken, $this->_getSigningKeys(false));
        } catch (\UnexpectedValueException $e) {
            // CCP may have rotated its keys since they were cached
            if (!str_contains($e->getMessage(), '"kid" invalid')) {
                throw $e;
            }
            $claims = $this->_decode($accessToken, $this->_getSigningKeys(true));
        }

        $this->_assertClaims($claims);

        return $claims;
    }

    private function _decode(string $accessToken, array $signingKeys): array
    {
        $previousTimestamp = JWT::$timestamp;
        $previousLeeway = JWT::$leeway;
        JWT::$timestamp = $this->clock->now()->getTimestamp();
        JWT::$leeway = self::CLOCK_LEEWAY_SECONDS;

        try {
            return (array) JWT::decode($accessToken, $signingKeys);
        } catch (\UnexpectedValueException $e) {
            throw $e;
        } catch (\Exception $e) {
            throw new \UnexpectedValueException('Invalid EVE SSO token: ' . $e->getMessage(), 0, $e);
        } finally {
            JWT::$timestamp = $previousTimestamp;
            JWT::$leeway = $previousLeeway;
        }
    }

    private function _assertClaims(array $claims): void
    {
        if (!in_array($claims['iss'] ?? null, self::ACCEPTED_ISSUERS, true)) {
            throw new \UnexpectedValueException('EVE SSO token has an unexpected issuer.');
        }

        $audiences = (array) ($claims['aud'] ?? []);
        if (!in_array($this->eveSsoClientId, $audiences, true) || !in_array(self::EVE_AUDIENCE, $audiences, true)) {
            throw new \UnexpectedValueException('EVE SSO token was not issued for this application.');
        }

        if (!str_starts_with((string) ($claims['sub'] ?? ''), 'CHARACTER:EVE:')) {
            throw new \UnexpectedValueException('EVE SSO token does not belong to a character.');
        }
    }

    private function _getSigningKeys(bool $forceRefresh): array
    {
        $cacheItem = $this->cachePool->getItem(self::JWKS_CACHE_KEY);
        if ($forceRefresh || !$cacheItem->isHit()) {
            $cacheItem->set($this->httpClient->request('GET', self::JWKS_URL, ['timeout' => 10])->toArray());
            $cacheItem->expiresAfter(self::JWKS_CACHE_TTL);
            $this->cachePool->save($cacheItem);
        }

        $jwks = $cacheItem->get();
        // CCP adds a non-standard top level flag next to "keys"
        return JWK::parseKeySet(['keys' => $jwks['keys'] ?? []]);
    }
}
