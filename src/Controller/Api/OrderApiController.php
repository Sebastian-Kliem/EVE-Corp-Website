<?php

namespace App\Controller\Api;

use App\Entity\Orders\CorpOrder;
use App\Entity\Orders\CorpOrderItem;
use App\Entity\User;
use App\Repository\CorpOrderItemRepository;
use App\Repository\CorpOrderRepository;
use App\Repository\EveCharacterRepository;
use App\Service\Esi\EsiClient;
use App\Service\OrderService;
use App\Service\SdeService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/api/orders')]
#[IsGranted('ROLE_MEMBER')]
class OrderApiController extends AbstractController
{
    public function __construct(
        private readonly OrderService $orderService,
        private readonly CorpOrderRepository $orderRepository,
        private readonly CorpOrderItemRepository $itemRepository,
        private readonly EveCharacterRepository $characterRepository,
        private readonly EsiClient $esiClient,
        private readonly SdeService $sdeService
    ) {}

    #[Route('', name: 'api_orders_list', methods: ['GET'])]
    public function list(Request $request): JsonResponse
    {
        $type = strtoupper($request->query->get('type', CorpOrder::TYPE_BUY));
        $archived = filter_var($request->query->get('archived', false), FILTER_VALIDATE_BOOLEAN);

        // Sync contracts with orders before listing
        $this->orderService->syncContractsWithOrders();

        if ($archived) {
            $orders = $this->orderRepository->findArchivedOrders($type);
        } else {
            $orders = $this->orderRepository->findActiveOrders($type);
        }

        $data = array_map(fn(CorpOrder $order) => $this->orderService->formatOrderForApi($order), $orders);

        return new JsonResponse($data);
    }

    #[Route('/appraise', name: 'api_orders_appraise', methods: ['POST'])]
    public function appraise(Request $request): JsonResponse
    {
        $body = json_decode($request->getContent(), true);
        $text = trim((string)($body['text'] ?? ''));
        $percent = (int)($body['percent'] ?? 100);
        $type = strtoupper((string)($body['type'] ?? CorpOrder::TYPE_BUY));

        if (empty($text)) {
            return new JsonResponse([
                'isFitting' => false,
                'fitTitle' => null,
                'shipName' => null,
                'shipTypeId' => null,
                'type' => $type,
                'percent' => $percent,
                'items' => [],
                'unresolved' => [],
                'totalPrice' => 0.0,
                'totalBasePrice' => 0.0,
                'totalVolume' => 0.0,
                'totalItemCount' => 0,
            ]);
        }

        $result = $this->orderService->appraise($text, $percent, $type);

        return new JsonResponse($result);
    }

    #[Route('/create', name: 'api_orders_create', methods: ['POST'])]
    public function create(Request $request): JsonResponse
    {
        $user = $this->getUser();
        if (!$user instanceof User) {
            return new JsonResponse(['error' => 'Nicht autorisiert.'], Response::HTTP_UNAUTHORIZED);
        }

        $body = json_decode($request->getContent(), true);
        $type = strtoupper((string)($body['type'] ?? CorpOrder::TYPE_BUY));
        $title = trim((string)($body['title'] ?? ''));
        $percent = (int)($body['percent'] ?? 100);
        $note = isset($body['note']) ? (string)$body['note'] : null;
        $items = $body['items'] ?? [];
        $isFitting = filter_var($body['isFitting'] ?? false, FILTER_VALIDATE_BOOLEAN);
        $shipTypeId = !empty($body['shipTypeId']) ? (int)$body['shipTypeId'] : null;

        if (empty($items) || !is_array($items)) {
            return new JsonResponse(['error' => 'Keine Gegenstände für die Bestellung angegeben.'], Response::HTTP_BAD_REQUEST);
        }

        $order = $this->orderService->createOrder(
            $user,
            $type,
            $title,
            $percent,
            $note,
            $items,
            $isFitting,
            $shipTypeId
        );

        return new JsonResponse([
            'success' => true,
            'order' => $this->orderService->formatOrderForApi($order),
        ]);
    }

    #[Route('/items/{id}/fulfill', name: 'api_orders_fulfill_item', methods: ['POST'])]
    public function fulfillItem(int $id, Request $request): JsonResponse
    {
        $user = $this->getUser();
        if (!$user instanceof User) {
            return new JsonResponse(['error' => 'Nicht autorisiert.'], Response::HTTP_UNAUTHORIZED);
        }

        $item = $this->itemRepository->find($id);
        if (!$item) {
            return new JsonResponse(['error' => 'Position nicht gefunden.'], Response::HTTP_NOT_FOUND);
        }

        $body = json_decode($request->getContent(), true);
        $status = filter_var($body['status'] ?? !$item->isFulfilled(), FILTER_VALIDATE_BOOLEAN);

        $order = $this->orderService->fulfillItem($item, $user, $status);

        return new JsonResponse([
            'success' => true,
            'order' => $this->orderService->formatOrderForApi($order),
        ]);
    }

    #[Route('/{id}/fulfill-all', name: 'api_orders_fulfill_all', methods: ['POST'])]
    public function fulfillAll(int $id): JsonResponse
    {
        $user = $this->getUser();
        if (!$user instanceof User) {
            return new JsonResponse(['error' => 'Nicht autorisiert.'], Response::HTTP_UNAUTHORIZED);
        }

        $order = $this->orderRepository->find($id);
        if (!$order) {
            return new JsonResponse(['error' => 'Bestellung nicht gefunden.'], Response::HTTP_NOT_FOUND);
        }

        $this->orderService->fulfillAll($order, $user);

        return new JsonResponse([
            'success' => true,
            'order' => $this->orderService->formatOrderForApi($order),
        ]);
    }

    #[Route('/{id}/cancel', name: 'api_orders_cancel', methods: ['POST'])]
    public function cancel(int $id): JsonResponse
    {
        $user = $this->getUser();
        if (!$user instanceof User) {
            return new JsonResponse(['error' => 'Nicht autorisiert.'], Response::HTTP_UNAUTHORIZED);
        }

        $order = $this->orderRepository->find($id);
        if (!$order) {
            return new JsonResponse(['error' => 'Bestellung nicht gefunden.'], Response::HTTP_NOT_FOUND);
        }

        if ($order->getUser() !== $user && !$this->isGranted('ROLE_OFFICER')) {
            return new JsonResponse(['error' => 'Keine Berechtigung zum Stornieren dieser Bestellung.'], Response::HTTP_FORBIDDEN);
        }

        $this->orderService->cancelOrder($order);

        return new JsonResponse([
            'success' => true,
            'order' => $this->orderService->formatOrderForApi($order),
        ]);
    }

    #[Route('/{id}/reopen', name: 'api_orders_reopen', methods: ['POST'])]
    public function reopen(int $id): JsonResponse
    {
        $user = $this->getUser();
        if (!$user instanceof User) {
            return new JsonResponse(['error' => 'Nicht autorisiert.'], Response::HTTP_UNAUTHORIZED);
        }

        $order = $this->orderRepository->find($id);
        if (!$order) {
            return new JsonResponse(['error' => 'Bestellung nicht gefunden.'], Response::HTTP_NOT_FOUND);
        }

        if ($order->getUser() !== $user && !$this->isGranted('ROLE_OFFICER')) {
            return new JsonResponse(['error' => 'Keine Berechtigung zum Wiedereröffnen dieser Bestellung.'], Response::HTTP_FORBIDDEN);
        }

        $this->orderService->reopenOrder($order);

        return new JsonResponse([
            'success' => true,
            'order' => $this->orderService->formatOrderForApi($order),
        ]);
    }

    #[Route('/{id}/open-in-game', name: 'api_orders_open_in_game', methods: ['POST'])]
    public function openInGame(int $id): JsonResponse
    {
        $user = $this->getUser();
        if (!$user instanceof User) {
            return new JsonResponse(['error' => 'Nicht autorisiert.'], Response::HTTP_UNAUTHORIZED);
        }

        $order = $this->orderRepository->find($id);
        if (!$order) {
            return new JsonResponse(['error' => 'Bestellung nicht gefunden.'], Response::HTTP_NOT_FOUND);
        }

        $contractId = $order->getContractId();
        if (!$contractId) {
            return new JsonResponse(['error' => 'Kein Ingame-Vertrag für diesen Auftrag hinterlegt.'], Response::HTTP_BAD_REQUEST);
        }

        // Get user's active character with token
        $character = $this->characterRepository->findOneBy(['user' => $user, 'tokenValid' => true]);
        if (!$character) {
            return new JsonResponse(['error' => 'Kein verknüpfter EVE-Charakter mit gültigem Token gefunden.'], Response::HTTP_BAD_REQUEST);
        }

        try {
            $this->esiClient->request('POST', 'ui/openwindow/contract/', [
                'query' => ['contract_id' => (int)$contractId]
            ], $character);

            return new JsonResponse(['success' => true, 'message' => 'Vertragsfenster im EVE-Client geöffnet.']);
        } catch (\Exception $e) {
            return new JsonResponse(['error' => 'Fehler beim Öffnen im Spielclient: ' . $e->getMessage()], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    #[Route('/open-market-item', name: 'api_orders_open_market_item', methods: ['POST'])]
    public function openMarketItem(Request $request): JsonResponse
    {
        $user = $this->getUser();
        if (!$user instanceof User) {
            return new JsonResponse(['error' => 'Nicht autorisiert.'], Response::HTTP_UNAUTHORIZED);
        }

        $body = json_decode($request->getContent(), true);
        $typeId = (int)($body['typeId'] ?? 0);

        if ($typeId <= 0) {
            return new JsonResponse(['error' => 'Ungültiges Item.'], Response::HTTP_BAD_REQUEST);
        }

        $character = $this->characterRepository->findOneBy(['user' => $user, 'tokenValid' => true]);
        if (!$character) {
            return new JsonResponse(['error' => 'Kein verknüpfter EVE-Charakter mit gültigem Token gefunden.'], Response::HTTP_BAD_REQUEST);
        }

        try {
            $this->esiClient->request('POST', 'ui/openwindow/marketdetails/', [
                'query' => ['type_id' => $typeId]
            ], $character);

            return new JsonResponse(['success' => true, 'message' => 'Marktfenster im EVE-Client geöffnet.']);
        } catch (\Exception $e) {
            return new JsonResponse(['error' => 'Fehler beim Öffnen des Markts im Spielclient: ' . $e->getMessage()], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }
}
