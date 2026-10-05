<?php

namespace App\Service\Wanderer;

use App\Service\Discord\DiscordWebhookService;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Reads map data from the Wanderer API using the map's API key.
 */
class WandererApiClient
{
    private const TIMEOUT_SECONDS = 10;

    public function __construct(
        private readonly HttpClientInterface $httpClient,
        private readonly DiscordWebhookService $settingsService
    ) {}

    public function isConfigured(): bool
    {
        return $this->_getBaseUrl() !== null
            && $this->getMapSlug() !== null
            && $this->settingsService->getSetting('wanderer_api_key') !== null;
    }

    public function getMapSlug(): ?string
    {
        return $this->settingsService->getSetting('wanderer_map_slug');
    }

    /**
     * Returns the map's current connections as delivered by Wanderer.
     *
     * @return array<int, array<string, mixed>>
     * @throws \RuntimeException when the API is not configured or the request fails
     */
    public function fetchConnections(): array
    {
        if (!$this->isConfigured()) {
            throw new \RuntimeException('Wanderer API is not configured.');
        }

        try {
            return $this->_requestConnections((string)$this->_getBaseUrl(), (string)$this->getMapSlug(), (string)$this->settingsService->getSetting('wanderer_api_key'));
        } catch (\Throwable $e) {
            throw new \RuntimeException(sprintf('Wanderer API request failed: %s', $e->getMessage()), 0, $e);
        }
    }

    /**
     * Checks URL, map slug and API key without saving them (admin "test connection" button).
     *
     * @return array{success: bool, message: string}
     */
    public function testConnection(string $baseUrl, string $mapSlug, string $apiKey): array
    {
        $baseUrl = rtrim(trim($baseUrl), '/');
        if ($baseUrl === '' || trim($mapSlug) === '' || trim($apiKey) === '') {
            return ['success' => false, 'message' => 'Bitte Wanderer-URL, Map-Slug und Map-API-Key ausfüllen.'];
        }
        if (!preg_match('#^https?://#i', $baseUrl)) {
            return ['success' => false, 'message' => 'Die Wanderer-URL muss mit https:// beginnen.'];
        }

        try {
            $connections = $this->_requestConnections($baseUrl, trim($mapSlug), trim($apiKey));
        } catch (\Symfony\Contracts\HttpClient\Exception\HttpExceptionInterface $e) {
            return ['success' => false, 'message' => $this->_describeHttpError($e)];
        } catch (\Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface $e) {
            return ['success' => false, 'message' => sprintf('Wanderer unter %s ist nicht erreichbar (%s).', $baseUrl, $e->getMessage())];
        } catch (\Throwable $e) {
            return ['success' => false, 'message' => sprintf('Unter %s antwortet keine Wanderer-API (%s).', $baseUrl, $e->getMessage())];
        }

        return [
            'success' => true,
            'message' => sprintf('Verbindung erfolgreich: Map "%s" erreichbar, aktuell %d Verbindung(en).', trim($mapSlug), count($connections)),
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function _requestConnections(string $baseUrl, string $mapSlug, string $apiKey): array
    {
        $response = $this->httpClient->request('GET', sprintf('%s/api/maps/%s/connections', $baseUrl, rawurlencode($mapSlug)), [
            'headers' => [
                'Authorization' => 'Bearer ' . $apiKey,
                'Accept' => 'application/json',
            ],
            'timeout' => self::TIMEOUT_SECONDS,
        ]);
        $data = $response->toArray();

        if (!isset($data['data']) || !is_array($data['data'])) {
            throw new \RuntimeException('response contains no "data" list');
        }

        return $data['data'];
    }

    private function _describeHttpError(\Symfony\Contracts\HttpClient\Exception\HttpExceptionInterface $exception): string
    {
        $response = $exception->getResponse();
        $statusCode = $response->getStatusCode();

        try {
            $errorText = (string)($response->toArray(false)['error'] ?? '');
        } catch (\Throwable $e) {
            $errorText = '';
        }

        if ($statusCode === 401 || $statusCode === 403) {
            return 'Der Map-API-Key wird von Wanderer abgelehnt. Bitte den Key in den Map-Einstellungen von Wanderer prüfen.';
        }
        if ($statusCode === 404 && $errorText !== '') {
            return 'Wanderer kennt keine Map mit diesem Slug. Bitte den Map-Slug prüfen (Teil der Map-URL in Wanderer).';
        }
        if ($statusCode === 404) {
            return 'Unter dieser URL antwortet keine Wanderer-API. Bitte die Wanderer-URL prüfen.';
        }

        return sprintf('Wanderer antwortet mit HTTP %d%s.', $statusCode, $errorText !== '' ? ': ' . $errorText : '');
    }

    private function _getBaseUrl(): ?string
    {
        $url = $this->settingsService->getSetting('wanderer_api_url');

        return $url !== null ? rtrim($url, '/') : null;
    }
}
