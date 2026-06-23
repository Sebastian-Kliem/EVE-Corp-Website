<?php

namespace App\Service\Esi;

use App\Entity\EveCharacter;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Cache\CacheItemPoolInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;

class EsiClient
{
    private const BASE_URL = 'https://esi.evetech.net/latest/';
    private const SSO_AUTH_URL = 'https://login.eveonline.com/v2/oauth/authorize';
    private const SSO_TOKEN_URL = 'https://login.eveonline.com/v2/oauth/token';

    public function __construct(
        private readonly HttpClientInterface $httpClient,
        private readonly EntityManagerInterface $entityManager,
        private readonly CacheItemPoolInterface $cachePool,
        private readonly string $eveSsoClientId,
        private readonly string $eveSsoSecretKey,
        private readonly string $eveSsoCallbackUrl,
        private readonly string $eveSsoScopes
    ) {}

    /**
     * Generates the authorization URL for EVE Online SSO.
     */
    public function getAuthorizationUrl(string $state): string
    {
        $params = [
            'response_type' => 'code',
            'redirect_uri' => $this->eveSsoCallbackUrl,
            'client_id' => $this->eveSsoClientId,
            'state' => $state,
        ];

        if (!empty($this->eveSsoScopes)) {
            $params['scope'] = str_replace(',', ' ', $this->eveSsoScopes);
        }

        return self::SSO_AUTH_URL . '?' . http_build_query($params);
    }

    /**
     * Exchanges an authorization code for access and refresh tokens.
     */
    public function exchangeCode(string $code): array
    {
        $response = $this->httpClient->request('POST', self::SSO_TOKEN_URL, [
            'headers' => [
                'Authorization' => 'Basic ' . base64_encode($this->eveSsoClientId . ':' . $this->eveSsoSecretKey),
                'Content-Type' => 'application/x-www-form-urlencoded',
            ],
            'body' => [
                'grant_type' => 'authorization_code',
                'code' => $code,
            ],
        ]);

        return $response->toArray();
    }

    /**
     * Decodes the JWT payload from an EVE SSO access token.
     */
    public function decodeTokenPayload(string $accessToken): array
    {
        $parts = explode('.', $accessToken);
        if (count($parts) !== 3) {
            throw new \InvalidArgumentException('Invalid JWT access token format');
        }

        $payload = json_decode(base64_decode(strtr($parts[1], '-_', '+/')), true);
        if (!$payload) {
            throw new \RuntimeException('Failed to decode JWT payload');
        }

        $sub = $payload['sub'] ?? '';
        $characterId = null;
        if (str_starts_with($sub, 'CHARACTER:EVE:')) {
            $characterId = (int) substr($sub, 14);
        }

        if (!$characterId) {
            throw new \RuntimeException('Failed to extract Character ID from token');
        }

        return [
            'character_id' => $characterId,
            'name' => $payload['name'] ?? '',
            'owner_hash' => $payload['owner'] ?? '',
            'scopes' => is_string($payload['scp'] ?? null) ? [$payload['scp']] : ($payload['scp'] ?? []),
        ];
    }

    /**
     * Refreshes the access token for a character.
     */
    public function refreshToken(EveCharacter $character): bool
    {
        $refreshToken = $character->getRefreshToken();
        if (!$refreshToken) {
            return false;
        }

        try {
            $response = $this->httpClient->request('POST', self::SSO_TOKEN_URL, [
                'headers' => [
                    'Authorization' => 'Basic ' . base64_encode($this->eveSsoClientId . ':' . $this->eveSsoSecretKey),
                    'Content-Type' => 'application/x-www-form-urlencoded',
                ],
                'body' => [
                    'grant_type' => 'refresh_token',
                    'refresh_token' => $refreshToken,
                ],
            ]);

            $data = $response->toArray();
            
            $character->setAccessToken($data['access_token']);
            if (!empty($data['refresh_token'])) {
                $character->setRefreshToken($data['refresh_token']);
            }
            
            $expiresIn = (int) ($data['expires_in'] ?? 1200);
            $character->setTokenExpiresAt((new \DateTimeImmutable())->modify('+' . $expiresIn . ' seconds'));
            $character->setTokenValid(true);

            $this->entityManager->flush();

            return true;
        } catch (\Exception $e) {
            // Log error to system error log for easy developer troubleshooting
            error_log(sprintf('[EsiClient] Failed to refresh token for character %s (%d): %s', $character->getName(), $character->getId(), $e->getMessage()));
            
            // If the error indicates that the refresh token is invalid (e.g. invalid_grant or 400 Bad Request)
            // we mark the character's token as invalid so we can warn the user.
            if (str_contains(strtolower($e->getMessage()), 'invalid_grant') || str_contains($e->getMessage(), '400') || str_contains($e->getMessage(), '401')) {
                $character->setTokenValid(false);
                $this->entityManager->flush();
            }
            
            return false;
        }
    }

    /**
     * Performs a request to the ESI API.
     */
    public function request(string $method, string $path, array $options = [], ?EveCharacter $character = null): mixed
    {
        $method = strtoupper($method);
        
        // Cache only GET requests
        $useCache = ($method === 'GET');
        $cacheKey = null;
        $cacheItem = null;

        if ($useCache) {
            // Generate a secure, unique cache key based on path, options, and character ownership
            $cacheKey = 'esi_' . md5($path . '_' . json_encode($options) . '_' . ($character ? $character->getId() : 'public'));
            $cacheItem = $this->cachePool->getItem($cacheKey);
            if ($cacheItem->isHit()) {
                return $cacheItem->get();
            }
        }

        $headers = $options['headers'] ?? [];
        $headers['User-Agent'] = 'WH-Toolbox/1.0 (Contact: Sebastian Kliem)';

        if ($character) {
            $expiresAt = $character->getTokenExpiresAt();
            // If token is expired or expires in less than 30 seconds, refresh it first
            if (!$expiresAt || $expiresAt->getTimestamp() - time() < 30) {
                if (!$this->refreshToken($character)) {
                    throw new \RuntimeException('Failed to refresh ESI access token for character ' . $character->getName());
                }
            }

            $headers['Authorization'] = 'Bearer ' . $character->getAccessToken();
        }

        $options['headers'] = $headers;
        $url = self::BASE_URL . ltrim($path, '/');

        try {
            $response = $this->httpClient->request($method, $url, $options);
            $data = json_decode($response->getContent(), true);
        } catch (\Symfony\Contracts\HttpClient\Exception\HttpExceptionInterface $e) {
            // If ESI returned 401 Unauthorized (invalid/revoked token) and we have a character, try to refresh and retry once
            if ($character && $e->getResponse()->getStatusCode() === 401) {
                error_log(sprintf('[EsiClient] Got 401 from ESI. Forcing token refresh and retry for character %s (%d)...', $character->getName(), $character->getId()));
                if ($this->refreshToken($character)) {
                    $headers['Authorization'] = 'Bearer ' . $character->getAccessToken();
                    $options['headers'] = $headers;
                    
                    $response = $this->httpClient->request($method, $url, $options);
                    $data = json_decode($response->getContent(), true);
                } else {
                    $character->setTokenValid(false);
                    $this->entityManager->flush();
                    throw $e;
                }
            } else {
                throw $e;
            }
        }

        // Cache the response if it was a successful GET request and contains Expires header
        if ($useCache && $cacheItem !== null) {
            $responseHeaders = $response->getHeaders(false);
            $expires = $responseHeaders['expires'][0] ?? null;
            if ($expires) {
                try {
                    $expiryTime = new \DateTimeImmutable($expires);
                    $ttl = $expiryTime->getTimestamp() - time();
                    if ($ttl > 0) {
                        $cacheItem->set($data);
                        $cacheItem->expiresAfter($ttl);
                        $this->cachePool->save($cacheItem);
                    }
                } catch (\Exception $e) {
                    // Fallback: If date parsing fails, do not cache
                }
            }
        }

        return $data;
    }
}
