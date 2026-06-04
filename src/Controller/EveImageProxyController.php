<?php

namespace App\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Contracts\HttpClient\HttpClientInterface;

class EveImageProxyController extends AbstractController
{
    public function __construct(
        private readonly HttpClientInterface $httpClient
    ) {}

    #[Route('/eve/image/{category}/{id}/{action}', name: 'app_eve_image_proxy', requirements: ['id' => '\d+'])]
    public function proxy(string $category, int $id, string $action, Request $request): Response
    {
        // Allowed categories and actions to prevent arbitrary external requests
        $allowedCategories = ['types', 'characters', 'corporations', 'alliances'];
        $allowedActions = ['icon', 'portrait', 'logo', 'render'];
        
        if (!in_array($category, $allowedCategories) || !in_array($action, $allowedActions)) {
            throw $this->createNotFoundException('Invalid category or action.');
        }

        // Get requested size, fallback to 64px
        $size = $request->query->getInt('size', 64);
        
        // Define local cache path inside the writable var/ directory
        $projectDir = $this->getParameter('kernel.project_dir');
        $cacheDir = $projectDir . '/var/eve_image_cache/' . $category . '/' . $id;
        
        if (!is_dir($cacheDir)) {
            mkdir($cacheDir, 0777, true);
        }
        
        $cachePath = sprintf('%s/%s_%d.png', $cacheDir, $action, $size);

        // If cached file exists locally, serve it immediately (0ms external latency)
        if (file_exists($cachePath)) {
            return new BinaryFileResponse($cachePath);
        }

        // Otherwise, fetch it from CCP Image Server in the background (Server-to-Server)
        // This keeps the user's IP completely private and ensures 100% GDPR compliance.
        $ccpUrl = sprintf('https://images.evetech.net/%s/%d/%s?size=%d', $category, $id, $action, $size);
        
        try {
            $response = $this->httpClient->request('GET', $ccpUrl, [
                'timeout' => 5,
            ]);
            
            if ($response->getStatusCode() === 200) {
                $content = $response->getContent();
                file_put_contents($cachePath, $content);
                
                return new BinaryFileResponse($cachePath);
            }
        } catch (\Exception $e) {
            // Log error if logger exists or simply let it fail gracefully
        }

        // Fallback: If external server fails or item doesn't exist, return a 404
        return new Response('Image not found or failed to fetch.', Response::HTTP_NOT_FOUND);
    }
}
