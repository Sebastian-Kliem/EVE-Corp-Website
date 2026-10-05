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

        $url = sprintf('%s/api/maps/%s/connections', $this->_getBaseUrl(), rawurlencode((string)$this->getMapSlug()));

        try {
            $response = $this->httpClient->request('GET', $url, [
                'headers' => [
                    'Authorization' => 'Bearer ' . $this->settingsService->getSetting('wanderer_api_key'),
                    'Accept' => 'application/json',
                ],
                'timeout' => self::TIMEOUT_SECONDS,
            ]);
            $data = $response->toArray();
        } catch (\Throwable $e) {
            throw new \RuntimeException(sprintf('Wanderer API request to %s failed: %s', $url, $e->getMessage()), 0, $e);
        }

        if (!isset($data['data']) || !is_array($data['data'])) {
            throw new \RuntimeException('Wanderer API returned no "data" list for connections.');
        }

        return $data['data'];
    }

    private function _getBaseUrl(): ?string
    {
        $url = $this->settingsService->getSetting('wanderer_api_url');

        return $url !== null ? rtrim($url, '/') : null;
    }
}
