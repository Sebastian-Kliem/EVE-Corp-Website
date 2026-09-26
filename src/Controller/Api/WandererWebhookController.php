<?php

namespace App\Controller\Api;

use App\Service\Wanderer\WandererRouteService;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/api/webhooks')]
class WandererWebhookController extends AbstractController
{
    public function __construct(
        private readonly WandererRouteService $wandererRouteService,
        private readonly LoggerInterface $logger
    ) {}

    #[Route('/wanderer', name: 'api_webhook_wanderer', methods: ['POST'])]
    public function handleWandererWebhook(Request $request): JsonResponse
    {
        $rawContent = $request->getContent();
        $signature = $request->headers->get('x-wanderer-signature') ?? $request->headers->get('x-signature');
        $timestamp = $request->headers->get('x-wanderer-timestamp') ?? $request->headers->get('x-timestamp');

        // Verify signature if secret is configured
        if (!$this->wandererRouteService->verifySignature($rawContent, $signature, $timestamp)) {
            $this->logger->warning('[WandererWebhook] Rejected webhook request due to invalid HMAC signature.');
            return new JsonResponse(['error' => 'Invalid signature'], Response::HTTP_UNAUTHORIZED);
        }

        $payload = json_decode($rawContent, true);
        if (!is_array($payload)) {
            $this->logger->warning('[WandererWebhook] Received invalid JSON payload.');
            return new JsonResponse(['error' => 'Invalid JSON payload'], Response::HTTP_BAD_REQUEST);
        }

        try {
            $result = $this->wandererRouteService->processWebhookPayload($payload);

            return new JsonResponse([
                'status' => 'success',
                'data' => $result,
            ], Response::HTTP_OK);
        } catch (\Throwable $e) {
            $this->logger->error(sprintf('[WandererWebhook] Error processing webhook: %s', $e->getMessage()), [
                'exception' => $e,
            ]);

            return new JsonResponse([
                'status' => 'error',
                'message' => 'Internal error processing webhook',
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }
}
