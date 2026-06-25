<?php

namespace App\Service\Esi;

use App\Entity\EveCharacter;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Cache\CacheItemPoolInterface;
use Psr\Log\LoggerInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;

class EsiClient
{
    private const BASE_URL = 'https://esi.evetech.net/latest/';
    private const SSO_AUTH_URL = 'https://login.eveonline.com/v2/oauth/authorize';
    private const SSO_TOKEN_URL = 'https://login.eveonline.com/v2/oauth/token';

    private static bool $esiOffline = false;

    public function __construct(
        private readonly HttpClientInterface $httpClient,
        private readonly EntityManagerInterface $entityManager,
        private readonly CacheItemPoolInterface $cachePool,
        private readonly LoggerInterface $logger,
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
        $result = $this->requestWithHeaders($method, $path, $options, $character);
        return $result['data'];
    }

    public function requestWithHeaders(string $method, string $path, array $options = [], ?EveCharacter $character = null, int $maxRetries = 3): array
    {
        $method = strtoupper($method);
        
        if (self::$esiOffline) {
            throw new \RuntimeException('ESI is offline (circuit breaker active)');
        }
        
        // Cache only GET requests
        $useCache = ($method === 'GET');
        $cacheKey = null;
        $cacheItem = null;

        $queryString = !empty($options['query']) ? '?' . http_build_query($options['query']) : '';
        $fullPathLog = $path . $queryString;

        if ($useCache) {
            // Generate a secure, unique cache key based on path, options, and character ownership
            $cacheKey = 'esi_wh_' . md5($path . '_' . json_encode($options) . '_' . ($character ? $character->getId() : 'public'));
            $cacheItem = $this->cachePool->getItem($cacheKey);
            if ($cacheItem->isHit()) {
                $cachedVal = $cacheItem->get();
                $this->logCron(sprintf('[EsiClient] GET %s vom Cache geholt.', $fullPathLog), 'info');
                if (is_array($cachedVal) && isset($cachedVal['data']) && array_key_exists('headers', $cachedVal)) {
                    return $cachedVal;
                }
                return [
                    'data' => $cachedVal,
                    'headers' => []
                ];
            }
        }

        $headers = $options['headers'] ?? [];
        $headers['User-Agent'] = 'WH-Toolbox/1.0 (Contact: Sebastian Kliem)';

        $attempt = 0;
        while (true) {
            $attempt++;
            try {
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

                $this->logCron(sprintf('[EsiClient] Sending actual API request: %s %s (attempt %d)', $method, $fullPathLog, $attempt), 'debug');

                $response = $this->httpClient->request($method, $url, $options);
                $data = json_decode($response->getContent(), true);
                $responseHeaders = $response->getHeaders(false);

                // Log page count info if X-Pages header is present
                if (isset($responseHeaders['x-pages'][0])) {
                    $this->logCron(sprintf('[EsiClient] ESI Request %s %s - Gesamtzahl der Seiten: %d', $method, $fullPathLog, (int)$responseHeaders['x-pages'][0]), 'info');
                }

                // Handle error limit remainder if present
                $remain = isset($responseHeaders['x-esi-error-limit-remain'][0]) ? (int)$responseHeaders['x-esi-error-limit-remain'][0] : null;
                $reset = isset($responseHeaders['x-esi-error-limit-reset'][0]) ? (int)$responseHeaders['x-esi-error-limit-reset'][0] : null;

                if ($remain !== null && $remain < 10) {
                    $sleepTime = $reset !== null ? min($reset, 5) : 2;
                    $this->logCron(sprintf('[EsiClient] ESI Error limit low (%d remaining). Throttling for %d seconds...', $remain, $sleepTime), 'warning');
                    sleep($sleepTime);
                }

                $result = [
                    'data' => $data,
                    'headers' => $responseHeaders
                ];

                // Cache the response if it was a successful GET request and contains Expires header
                if ($useCache && $cacheItem !== null) {
                    $expires = $responseHeaders['expires'][0] ?? null;
                    if ($expires) {
                        try {
                            $expiryTime = new \DateTimeImmutable($expires);
                            $ttl = $expiryTime->getTimestamp() - time();
                            if ($ttl > 0) {
                                $cacheItem->set($result);
                                $cacheItem->expiresAfter($ttl);
                                $this->cachePool->save($cacheItem);
                            }
                        } catch (\Exception $e) {
                            // Fallback: If date parsing fails, do not cache
                        }
                    }
                }

                return $result;

            } catch (\Exception $e) {
                $statusCode = 0;
                $is420 = false;
                $retryAfter = 2;

                if ($e instanceof \Symfony\Contracts\HttpClient\Exception\HttpExceptionInterface) {
                    $statusCode = $e->getResponse()->getStatusCode();

                    // If ESI returned 401 Unauthorized (invalid/revoked token) and we have a character, try to refresh and retry once
                    if ($character && $statusCode === 401 && $attempt === 1) {
                        $this->logCron(sprintf('[EsiClient] Got 401 from ESI. Forcing token refresh and retry for character %s (%d)...', $character->getName(), $character->getId()), 'notice');
                        if ($this->refreshToken($character)) {
                            $headers['Authorization'] = 'Bearer ' . $character->getAccessToken();
                            continue; // Retry immediately
                        } else {
                            $character->setTokenValid(false);
                            $this->entityManager->flush();
                            throw $e;
                        }
                    }

                    // HTTP 420: Enhance Your Calm
                    if ($statusCode === 420) {
                        $is420 = true;
                        $responseHeaders = $e->getResponse()->getHeaders(false);
                        $retryAfter = isset($responseHeaders['retry-after'][0]) ? (int)$responseHeaders['retry-after'][0] : 10;
                        $this->logCron(sprintf('[EsiClient] Got HTTP 420 (Enhance Your Calm) for %s. Sleeping for %d seconds...', $fullPathLog, $retryAfter), 'error');
                    }
                }

                // Activate circuit breaker on server error (5xx) or transport exception (connection issues)
                $isServerError = ($statusCode >= 500 && $statusCode < 600);
                $isTransportError = ($e instanceof \Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface);
                if ($isServerError || $isTransportError) {
                    self::$esiOffline = true;
                    $this->logger->error(sprintf('[EsiClient] ESI is down or unreachable. Activating circuit breaker. Error: %s', $e->getMessage()));
                }

                // Client error (4xx) except HTTP 420 should fail immediately without retry
                $isClientError = ($statusCode >= 400 && $statusCode < 500 && !$is420);

                if ($isClientError || $attempt >= $maxRetries) {
                    $this->logCron(sprintf('[EsiClient] Request to %s failed permanently after %d attempts: %s', $fullPathLog, $attempt, $e->getMessage()), 'error');
                    throw $e;
                }

                $this->logCron(sprintf('[EsiClient] Request to %s fehlgeschlagen (Versuch %d/%d): %s. Erneuter Versuch in %d Sekunden...', $fullPathLog, $attempt, $maxRetries, $e->getMessage(), $retryAfter), 'warning');
                sleep($retryAfter);
            }
        }
    }

    /**
     * Helper to log both to standard logger and directly to the dedicated var/log/cron.log file.
     */
    private function logCron(string $message, string $level = 'info'): void
    {
        $this->logger->log($level, $message);
        
        try {
            $logFile = dirname(__FILE__, 4) . '/var/log/cron.log';
            $logDir = dirname($logFile);
            if (!is_dir($logDir)) {
                mkdir($logDir, 0777, true);
            }
            $formatted = sprintf("[%s] [%s] %s\n", (new \DateTimeImmutable())->format('Y-m-d H:i:s'), strtoupper($level), $message);
            file_put_contents($logFile, $formatted, FILE_APPEND);
        } catch (\Exception $e) {
            // Ignore write errors to prevent breaking the ESI client
        }
    }
}
