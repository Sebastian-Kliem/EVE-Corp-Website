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

    private const MISSING_SCOPE_CACHE_TTL = 3600;

    // ESI error budget is shared per server IP, so the pause is stored in the shared cache for all processes
    private const ERROR_LIMIT_CACHE_KEY = 'esi_error_limit_pause_until';
    private const ERROR_LIMIT_THRESHOLD = 20;
    private const ERROR_LIMIT_DEFAULT_WAIT = 60;
    private const RATE_LIMIT_DEFAULT_WAIT = 60;
    private const RATE_LIMIT_MAX_WAIT = 300;

    // Consecutive 5xx/transport failures before ESI is treated as down, and for how long
    private const CIRCUIT_BREAKER_THRESHOLD = 10;
    private const CIRCUIT_BREAKER_COOLDOWN = 120;

    private int $consecutiveServerFailures = 0;
    private int $circuitOpenUntil = 0;

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
     * Returns the configured SSO scopes the character's token was not granted.
     */
    public function getMissingScopes(EveCharacter $character): array
    {
        try {
            $grantedScopes = $this->decodeTokenPayload((string) $character->getAccessToken())['scopes'];
        } catch (\Exception $e) {
            return [];
        }

        $missingScopes = [];
        foreach (explode(',', $this->eveSsoScopes) as $requiredScope) {
            $requiredScope = trim($requiredScope);
            if ($requiredScope !== '' && !in_array($requiredScope, $grantedScopes, true)) {
                $missingScopes[] = $requiredScope;
            }
        }

        return $missingScopes;
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
     * Checks if ESI is offline (circuit breaker active or scheduled downtime).
     */
    public function isOffline(): bool
    {
        if ($this->_isCircuitOpen()) {
            return true;
        }

        // Scheduled downtime window: 10:50 - 11:30 UTC
        $nowUtc = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        $timeStr = $nowUtc->format('H:i');
        if ($timeStr >= '10:50' && $timeStr <= '11:30') {
            return true;
        }

        return false;
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

        if ($this->isOffline()) {
            $reason = $this->_isCircuitOpen() ? 'circuit breaker active' : 'scheduled downtime (10:50 - 11:30 UTC)';
            throw new \RuntimeException('ESI is offline (' . $reason . ')');
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
                    $cachedVal['fromCache'] = true;
                    return $cachedVal;
                }
                return [
                    'data' => $cachedVal,
                    'headers' => [],
                    'fromCache' => true
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

                    // Skip endpoints known to be outside the token's scopes (avoids burning the ESI error limit)
                    $missingScopeCacheItem = $this->_getMissingScopeCacheItem($character, $path);
                    if ($missingScopeCacheItem?->isHit()) {
                        $this->logCron(sprintf('[EsiClient] Skipping %s for %s: required scope missing (cached).', $fullPathLog, $character->getName()), 'debug');
                        throw new EsiMissingScopeException($path, $character->getName(), $missingScopeCacheItem->get());
                    }
                }

                $this->_waitForErrorLimit($fullPathLog);

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

                $this->_trackErrorLimit($responseHeaders);
                $this->consecutiveServerFailures = 0;

                $result = [
                    'data' => $data,
                    'headers' => $responseHeaders,
                    'fromCache' => false
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

            } catch (EsiMissingScopeException $e) {
                throw $e;
            } catch (\Exception $e) {
                $statusCode = 0;
                $is420 = false;
                $isRateLimited = false;
                $retryAfter = 2;

                if ($e instanceof \Symfony\Contracts\HttpClient\Exception\HttpExceptionInterface) {
                    $statusCode = $e->getResponse()->getStatusCode();

                    // Error responses consume the error budget, so their headers matter most
                    $this->_trackErrorLimit($e->getResponse()->getHeaders(false));

                    // ESI answers 401 for tokens lacking the endpoint's scope; refreshing the token cannot fix that
                    if ($character && $statusCode === 401) {
                        $requiredScope = $this->_extractMissingScope($e->getResponse());
                        if ($requiredScope !== null) {
                            $this->_rememberMissingScope($character, $path, $requiredScope);
                            $this->logCron(sprintf('[EsiClient] %s lacks scope %s for %s. Skipping for %d seconds.', $character->getName(), $requiredScope, $fullPathLog, self::MISSING_SCOPE_CACHE_TTL), 'warning');
                            throw new EsiMissingScopeException($path, $character->getName(), $requiredScope);
                        }
                    }

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
                        $retryAfter = $this->_pauseForErrorLimit($e->getResponse()->getHeaders(false));
                        $this->logCron(sprintf('[EsiClient] Got HTTP 420 (Enhance Your Calm) for %s. Pausing all ESI requests for %d seconds...', $fullPathLog, $retryAfter), 'error');
                    }

                    // HTTP 429: rate limit of the route group exhausted for this token or IP
                    if ($statusCode === 429) {
                        $isRateLimited = true;
                        $retryAfter = $this->_getRateLimitWait($e->getResponse()->getHeaders(false));
                        $this->logCron(sprintf('[EsiClient] Got HTTP 429 (rate limited) for %s. Retry in %d seconds.', $fullPathLog, $retryAfter), 'warning');
                    }
                }

                // Count server errors (5xx) and transport exceptions (connection issues) towards the circuit breaker
                $isServerError = ($statusCode >= 500 && $statusCode < 600);
                $isTransportError = ($e instanceof \Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface);
                if ($isServerError || $isTransportError) {
                    if ($this->_recordServerFailure($e)) {
                        throw $e;
                    }
                } elseif ($statusCode > 0) {
                    // Any other HTTP answer proves ESI is reachable
                    $this->consecutiveServerFailures = 0;
                }

                // Client error (4xx) except HTTP 420 and 429 should fail immediately without retry
                $isClientError = ($statusCode >= 400 && $statusCode < 500 && !$is420 && !$isRateLimited);
                // Web requests must not block a worker while waiting for the rate limit window
                $isRateLimitedInWeb = $isRateLimited && \PHP_SAPI !== 'cli';

                if ($isClientError || $isRateLimitedInWeb || $attempt >= $maxRetries) {
                    // 403 means missing corporation roles; callers decide whether that is an error
                    $logLevel = $statusCode === 403 ? 'warning' : 'error';
                    $this->logCron(sprintf('[EsiClient] Request to %s failed permanently after %d attempts: %s%s', $fullPathLog, $attempt, $e->getMessage(), $this->_getErrorBodySnippet($e)), $logLevel);
                    throw $e;
                }

                $this->logCron(sprintf('[EsiClient] Request to %s fehlgeschlagen (Versuch %d/%d): %s. Erneuter Versuch in %d Sekunden...', $fullPathLog, $attempt, $maxRetries, $e->getMessage(), $retryAfter), 'warning');
                sleep($retryAfter);
            }
        }
    }

    /**
     * Requests all pages for a paginated GET endpoint using X-Pages header.
     * If the first page is from cache, returns immediately with fromCache => true.
     * Throws an exception if any page > 1 is empty or if request fails.
     */
    public function requestAllPages(string $path, array $options = [], ?EveCharacter $character = null): array
    {
        $page = 1;
        $totalPages = 1;
        $mergedData = [];
        $allFromCache = true;

        while ($page <= $totalPages) {
            $pageOptions = $options;
            $pageOptions['query'] = array_merge($pageOptions['query'] ?? [], ['page' => $page]);

            $attempt = 0;
            $maxPageRetries = 10;
            $data = null;
            $headers = [];

            while ($attempt < $maxPageRetries) {
                $attempt++;
                try {
                    $response = $this->requestWithHeaders('GET', $path, $pageOptions, $character, 5);

                    if (!($response['fromCache'] ?? false)) {
                        $allFromCache = false;
                    }

                    $data = $response['data'];
                    $headers = $response['headers'];

                    // ESI pagination hiccup: sometimes it returns empty page data on HTTP 200 for pages > 1
                    if ($page > 1 && (empty($data) || !is_array($data))) {
                        $this->logCron(sprintf('[EsiClient] Page %d of %d on path %s returned empty data (attempt %d/%d). Retrying...', $page, $totalPages, $path, $attempt, $maxPageRetries), 'warning');
                        sleep(2);
                        continue;
                    }

                    break; // Success
                } catch (EsiMissingScopeException $e) {
                    throw $e;
                } catch (\Exception $e) {
                    $isClientError = false;
                    if ($e instanceof \Symfony\Contracts\HttpClient\Exception\HttpExceptionInterface) {
                        $statusCode = $e->getResponse()->getStatusCode();
                        if ($statusCode >= 400 && $statusCode < 500 && $statusCode !== 420) {
                            $isClientError = true;
                        }
                    }
                    if ($isClientError || $attempt >= $maxPageRetries) {
                        throw $e;
                    }
                    $this->logCron(sprintf('[EsiClient] Request failed for page %d of %d on path %s (attempt %d/%d): %s. Retrying...', $page, $totalPages, $path, $attempt, $maxPageRetries, $e->getMessage()), 'warning');
                    sleep(2);
                }
            }

            if (empty($data) || !is_array($data)) {
                if ($page > 1) {
                    throw new \RuntimeException(sprintf('ESI returned empty data for page %d of %d on path %s after %d retries.', $page, $totalPages, $path, $maxPageRetries));
                }
                break;
            }

            $mergedData = array_merge($mergedData, $data);

            if ($page === 1 && isset($headers['x-pages'][0])) {
                $totalPages = (int)$headers['x-pages'][0];
            }

            $page++;
        }

        return [
            'data' => $mergedData,
            'fromCache' => $allFromCache
        ];
    }

    /**
     * Calculates the solar system route between origin and destination.
     * Supported flags: 'secure' (prefer/only Highsec), 'shortest', 'insecure' (prefer Low/Null).
     * Returns an array of solar system IDs along the path or null if no route found.
     *
     * @return int[]|null
     */
    public function getRoute(int $originSolarSystemId, int $destinationSolarSystemId, string $flag = 'secure'): ?array
    {
        if ($originSolarSystemId <= 0 || $destinationSolarSystemId <= 0) {
            return null;
        }

        if ($originSolarSystemId === $destinationSolarSystemId) {
            return [$originSolarSystemId];
        }

        try {
            $path = sprintf('route/%d/%d/', $originSolarSystemId, $destinationSolarSystemId);
            $result = $this->request('GET', $path, [
                'query' => [
                    'flag' => $flag,
                ],
            ]);

            if (is_array($result) && !empty($result)) {
                $route = [];
                foreach ($result as $systemId) {
                    $route[] = (int)$systemId;
                }
                return $route;
            }
        } catch (\Throwable $e) {
            $this->logger->debug(sprintf(
                '[EsiClient] No route found from %d to %d (flag: %s): %s',
                $originSolarSystemId,
                $destinationSolarSystemId,
                $flag,
                $e->getMessage()
            ));
        }

        return null;
    }

    // ESI explains client errors in the body (e.g. invalid IDs); the exception message omits it
    private function _getErrorBodySnippet(\Throwable $exception): string
    {
        if (!$exception instanceof \Symfony\Contracts\HttpClient\Exception\HttpExceptionInterface) {
            return '';
        }

        try {
            $body = trim($exception->getResponse()->getContent(false));
        } catch (\Throwable $e) {
            return '';
        }

        return $body === '' ? '' : ' Response: ' . mb_substr($body, 0, 200);
    }

    // Records the remaining ESI error budget and pauses all processes before it runs out
    private function _trackErrorLimit(array $responseHeaders): void
    {
        if (!isset($responseHeaders['x-esi-error-limit-remain'][0])) {
            return;
        }

        $remain = (int) $responseHeaders['x-esi-error-limit-remain'][0];
        if ($remain >= self::ERROR_LIMIT_THRESHOLD) {
            return;
        }

        $waitSeconds = $this->_pauseForErrorLimit($responseHeaders);
        $this->logCron(sprintf('[EsiClient] ESI error limit low (%d remaining). Pausing all ESI requests for %d seconds.', $remain, $waitSeconds), 'warning');
    }

    private function _pauseForErrorLimit(array $responseHeaders): int
    {
        $waitSeconds = self::ERROR_LIMIT_DEFAULT_WAIT;
        if (isset($responseHeaders['x-esi-error-limit-reset'][0])) {
            $waitSeconds = (int) $responseHeaders['x-esi-error-limit-reset'][0] + 1;
        } elseif (isset($responseHeaders['retry-after'][0])) {
            $waitSeconds = (int) $responseHeaders['retry-after'][0];
        }
        $waitSeconds = max(1, $waitSeconds);

        $cacheItem = $this->cachePool->getItem(self::ERROR_LIMIT_CACHE_KEY);
        $cacheItem->set(time() + $waitSeconds);
        $cacheItem->expiresAfter($waitSeconds);
        $this->cachePool->save($cacheItem);

        return $waitSeconds;
    }

    private function _isCircuitOpen(): bool
    {
        return $this->circuitOpenUntil > time();
    }

    // Returns true when this failure opened the circuit breaker
    private function _recordServerFailure(\Throwable $exception): bool
    {
        $this->consecutiveServerFailures++;
        if ($this->consecutiveServerFailures < self::CIRCUIT_BREAKER_THRESHOLD) {
            return false;
        }

        $this->circuitOpenUntil = time() + self::CIRCUIT_BREAKER_COOLDOWN;
        $this->logCron(sprintf('[EsiClient] ESI is down or unreachable (%d consecutive failures). Circuit breaker open for %d seconds. Error: %s', $this->consecutiveServerFailures, self::CIRCUIT_BREAKER_COOLDOWN, $exception->getMessage()), 'error');

        return true;
    }

    // Seconds until the rate limit window allows requests again, capped to keep cron runs moving
    private function _getRateLimitWait(array $responseHeaders): int
    {
        $waitSeconds = self::RATE_LIMIT_DEFAULT_WAIT;
        if (isset($responseHeaders['retry-after'][0]) && is_numeric($responseHeaders['retry-after'][0])) {
            $waitSeconds = (int) $responseHeaders['retry-after'][0];
        }

        return min(self::RATE_LIMIT_MAX_WAIT, max(1, $waitSeconds));
    }

    // Cron processes wait for the budget to reset; web requests fail fast instead of blocking a worker
    private function _waitForErrorLimit(string $fullPathLog): void
    {
        $cacheItem = $this->cachePool->getItem(self::ERROR_LIMIT_CACHE_KEY);
        if (!$cacheItem->isHit()) {
            return;
        }

        $waitSeconds = (int) $cacheItem->get() - time();
        if ($waitSeconds <= 0) {
            return;
        }

        if (\PHP_SAPI !== 'cli') {
            throw new \RuntimeException(sprintf('ESI error limit reached, retry in %d seconds.', $waitSeconds));
        }

        $this->logCron(sprintf('[EsiClient] ESI error limit pause active. Waiting %d seconds before %s.', $waitSeconds, $fullPathLog), 'warning');
        sleep($waitSeconds);
    }

    // Returns the scope named in ESI's "Token is not valid for any required scope" response, or null for other 401s
    private function _extractMissingScope(ResponseInterface $response): ?string
    {
        try {
            $body = $response->getContent(false);
        } catch (\Exception $e) {
            return null;
        }

        if (!str_contains($body, 'not valid for any required scope')) {
            return null;
        }

        return preg_match('/required scope:\s*([\w.\-]+)/', $body, $matches) === 1 ? $matches[1] : '';
    }

    private function _rememberMissingScope(EveCharacter $character, string $path, string $requiredScope): void
    {
        $cacheItem = $this->_getMissingScopeCacheItem($character, $path);
        if ($cacheItem === null) {
            return;
        }

        $cacheItem->set($requiredScope);
        $cacheItem->expiresAfter(self::MISSING_SCOPE_CACHE_TTL);
        $this->cachePool->save($cacheItem);
    }

    // Keyed by the token's scope set, so re-linking the character with more scopes invalidates the entry
    private function _getMissingScopeCacheItem(EveCharacter $character, string $path): ?\Psr\Cache\CacheItemInterface
    {
        try {
            $scopes = $this->decodeTokenPayload((string) $character->getAccessToken())['scopes'];
        } catch (\Exception $e) {
            return null;
        }

        sort($scopes);
        $cacheKey = 'esi_missing_scope_' . md5($character->getId() . '_' . $path . '_' . implode(' ', $scopes));

        return $this->cachePool->getItem($cacheKey);
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
