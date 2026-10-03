<?php

namespace App\Controller\Api;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Contracts\HttpClient\HttpClientInterface;

class EveImageProxyController extends AbstractController
{
    // Actions offered by the CCP Image Server per category
    private const ALLOWED_ACTIONS = [
        'types' => ['icon', 'render', 'bp', 'bpc'],
        'characters' => ['portrait'],
        'corporations' => ['logo'],
        'alliances' => ['logo'],
    ];

    // The CCP Image Server only accepts these sizes
    private const ALLOWED_SIZES = [32, 64, 128, 256, 512, 1024];

    public function __construct(
        private readonly HttpClientInterface $httpClient
    ) {}

    #[Route('/eve/image/{category}/{id}/{action}', name: 'app_eve_image_proxy', requirements: ['id' => '[1-9]\d{0,10}'])]
    public function proxy(string $category, int $id, string $action, Request $request): Response
    {
        // Allowed categories and actions to prevent arbitrary external requests and unbounded disk caching
        if (!in_array($action, self::ALLOWED_ACTIONS[$category] ?? [], true)) {
            throw $this->createNotFoundException('Invalid category or action.');
        }

        $size = $this->_normalizeSize($request->query->getInt('size', 64));
        
        // Define local cache path inside the writable var/ directory
        $projectDir = $this->getParameter('kernel.project_dir');
        $cacheDir = $projectDir . '/var/eve_image_cache/' . $category . '/' . $id;
        $cachePath = sprintf('%s/%s_%d.png', $cacheDir, $action, $size);

        // If cached file exists locally, serve it immediately (0ms external latency)
        if (file_exists($cachePath)) {
            $response = new BinaryFileResponse($cachePath);
            $response->setMaxAge(2592000); // 30 days
            $response->setPublic();
            return $response;
        }

        // Otherwise, fetch it from CCP Image Server in the background (Server-to-Server)
        // This keeps the user's IP completely private and ensures 100% GDPR compliance.
        $ccpUrl = sprintf('https://images.evetech.net/%s/%d/%s?size=%d', $category, $id, $action, $size);
        
        try {
            $response = $this->httpClient->request('GET', $ccpUrl, [
                'timeout' => 5,
            ]);
            
            if ($response->getStatusCode() === 200 && $this->_isImageResponse($response->getHeaders(false))) {
                $content = $response->getContent();

                // Only create cache directories for images that actually exist
                if (!is_dir($cacheDir)) {
                    mkdir($cacheDir, 0775, true);
                }
                file_put_contents($cachePath, $content);
                
                $fileResponse = new BinaryFileResponse($cachePath);
                $fileResponse->setMaxAge(2592000); // 30 days
                $fileResponse->setPublic();
                return $fileResponse;
            }
        } catch (\Exception $e) {
            // Log error if logger exists or simply let it fail gracefully
        }

        // Fallback: If external server fails or item doesn't exist, return the local fallback image
        $fallbackPath = $projectDir . '/assets/images/fallback_item.png';
        if (file_exists($fallbackPath)) {
            return new BinaryFileResponse($fallbackPath);
        }

        return new Response('Image not found or failed to fetch.', Response::HTTP_NOT_FOUND);
    }

    // Maps any requested size to the next supported one, so arbitrary values cannot create new cache files
    private function _normalizeSize(int $requestedSize): int
    {
        foreach (self::ALLOWED_SIZES as $allowedSize) {
            if ($allowedSize >= $requestedSize) {
                return $allowedSize;
            }
        }

        return self::ALLOWED_SIZES[array_key_last(self::ALLOWED_SIZES)];
    }

    private function _isImageResponse(array $headers): bool
    {
        $contentType = $headers['content-type'][0] ?? '';

        return str_starts_with($contentType, 'image/');
    }
}
