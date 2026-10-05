<?php

namespace App\Service;

use App\Entity\EveCharacterContract;
use App\Entity\Orders\CorpOrder;
use App\Entity\Orders\CorpOrderItem;
use App\Entity\User;
use App\Repository\CorpOrderRepository;
use Doctrine\ORM\EntityManagerInterface;

class OrderService
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly ItemParserService $itemParserService,
        private readonly JitaPriceService $jitaPriceService,
        private readonly SdeService $sdeService
    ) {}

    /**
     * Parses and calculates appraisal values (prices, volumes, slots) for a given raw text.
     *
     * @param string $rawText
     * @param int $percent
     * @param string $type BUY | SELL
     * @return array
     */
    public function appraise(string $rawText, int $percent = 100, string $type = CorpOrder::TYPE_BUY): array
    {
        $parsed = $this->itemParserService->parse($rawText);
        $isBuyMode = strtoupper($type) === CorpOrder::TYPE_BUY;
        // For Buy orders, base market price is Jita Sell ($isBuyOrder = false)
        // For Sell orders, base market price is Jita Buy ($isBuyOrder = true)
        $useJitaBuy = !$isBuyMode;

        $itemsWithPrices = [];
        $totalPrice = 0.0;
        $totalBasePrice = 0.0;
        $totalVolume = 0.0;
        $totalItemCount = 0;

        foreach ($parsed['items'] as $item) {
            $typeId = (int)$item['typeId'];
            $qty = (int)$item['quantity'];
            $priceInfo = $this->jitaPriceService->getAverageJitaPrice($typeId, $useJitaBuy);
            $baseUnitPrice = (float)($priceInfo['price'] ?? 0.0);

            $multiplier = max(0, $percent) / 100.0;
            $adjustedUnitPrice = $baseUnitPrice * $multiplier;
            $itemTotalPrice = $adjustedUnitPrice * $qty;
            $itemBaseTotalPrice = $baseUnitPrice * $qty;

            $unitVolume = (float)$item['packagedVolume'];
            $itemTotalVolume = $unitVolume * $qty;

            $totalPrice += $itemTotalPrice;
            $totalBasePrice += $itemBaseTotalPrice;
            $totalVolume += $itemTotalVolume;
            $totalItemCount += $qty;

            $itemsWithPrices[] = array_merge($item, [
                'unitPrice' => round($baseUnitPrice, 2),
                'adjustedUnitPrice' => round($adjustedUnitPrice, 2),
                'totalPrice' => round($itemTotalPrice, 2),
                'baseTotalPrice' => round($itemBaseTotalPrice, 2),
                'priceWarning' => $priceInfo['warning'],
                'priceMessage' => $priceInfo['message'],
            ]);
        }

        return [
            'isFitting' => $parsed['isFitting'],
            'fitTitle' => $parsed['fitTitle'],
            'shipName' => $parsed['shipName'],
            'shipTypeId' => $parsed['shipTypeId'],
            'type' => strtoupper($type),
            'percent' => $percent,
            'items' => $itemsWithPrices,
            'unresolved' => $parsed['unresolved'],
            'totalPrice' => round($totalPrice, 2),
            'totalBasePrice' => round($totalBasePrice, 2),
            'totalVolume' => round($totalVolume, 2),
            'totalItemCount' => $totalItemCount,
        ];
    }

    /**
     * Creates a new CorpOrder with its items.
     *
     * @param User $user
     * @param string $type BUY | SELL
     * @param string $title
     * @param int $percent
     * @param string|null $note
     * @param array $items Array of [typeId, name, quantity, unitPrice, unitVolume, slot, sortOrder]
     * @param bool $isFitting
     * @param int|null $shipTypeId
     * @return CorpOrder
     */
    public function createOrder(
        User $user,
        string $type,
        string $title,
        int $percent,
        ?string $note,
        array $items,
        bool $isFitting = false,
        ?int $shipTypeId = null
    ): CorpOrder {
        $order = new CorpOrder();
        $order->setUser($user);
        $order->setType($type);
        $order->setTitle(trim($title) ?: ($isFitting ? 'Fitting Bestellung' : 'Material Auftrag'));
        $order->setPercentToJita($percent);
        $order->setNote($note ? trim($note) : null);
        $order->setIsFitting($isFitting);
        $order->setShipTypeId($shipTypeId);
        $order->setStatus(CorpOrder::STATUS_OPEN);

        $multiplier = max(0, $percent) / 100.0;
        $totalPrice = 0.0;
        $totalVolume = 0.0;

        foreach ($items as $itemData) {
            $typeId = (int)($itemData['typeId'] ?? 0);
            $amount = max(1, (int)($itemData['quantity'] ?? $itemData['amount'] ?? 1));
            $name = (string)($itemData['name'] ?? $this->sdeService->getItemName($typeId));
            $unitPrice = (float)($itemData['unitPrice'] ?? 0.0);
            $unitVolume = (float)($itemData['unitVolume'] ?? $itemData['packagedVolume'] ?? $this->sdeService->getItemVolume($typeId, true));
            $slot = (string)($itemData['slot'] ?? 'cargo');
            $sortOrder = (int)($itemData['sortOrder'] ?? 100);

            $itemTotalPrice = ($unitPrice * $multiplier) * $amount;
            $itemTotalVolume = $unitVolume * $amount;

            $totalPrice += $itemTotalPrice;
            $totalVolume += $itemTotalVolume;

            $orderItem = new CorpOrderItem();
            $orderItem->setTypeId($typeId);
            $orderItem->setName($name);
            $orderItem->setAmount($amount);
            $orderItem->setUnitPrice(number_format($unitPrice, 2, '.', ''));
            $orderItem->setTotalPrice(number_format($itemTotalPrice, 2, '.', ''));
            $orderItem->setUnitVolume(number_format($unitVolume, 2, '.', ''));
            $orderItem->setTotalVolume(number_format($itemTotalVolume, 2, '.', ''));
            $orderItem->setSlot($slot);
            $orderItem->setSortOrder($sortOrder);
            $orderItem->setIsFulfilled(false);

            $order->addItem($orderItem);
        }

        $order->setTotalPrice(number_format($totalPrice, 2, '.', ''));
        $order->setTotalVolume(number_format($totalVolume, 2, '.', ''));

        $this->entityManager->persist($order);
        $this->entityManager->flush();

        return $order;
    }

    /**
     * Accepts / mass-fulfills all open items of a CorpOrder.
     */
    public function acceptOrder(CorpOrder $order, User $fulfiller): CorpOrder
    {
        $order->setFulfiller($fulfiller);
        foreach ($order->getItems() as $item) {
            if (!$item->isFulfilled()) {
                $item->setIsFulfilled(true);
                $item->setFulfiller($fulfiller);
                $item->setFulfilledAt(new \DateTimeImmutable());
            }
        }

        $order->recalculateTotals();
        $this->entityManager->flush();

        return $order;
    }

    /**
     * Unaccepts / releases items of a CorpOrder back to OPEN.
     */
    public function unacceptOrder(CorpOrder $order, ?User $user = null): CorpOrder
    {
        foreach ($order->getItems() as $item) {
            if ($user === null || $item->getFulfiller() === $user || $order->getUser() === $user) {
                $item->setIsFulfilled(false);
                $item->setFulfiller(null);
                $item->setFulfilledAt(null);
            }
        }

        $remainingFulfiller = null;
        foreach ($order->getItems() as $item) {
            if ($item->isFulfilled() && $item->getFulfiller()) {
                $remainingFulfiller = $item->getFulfiller();
                break;
            }
        }
        $order->setFulfiller($remainingFulfiller);

        $order->recalculateTotals();
        $this->entityManager->flush();

        return $order;
    }

    /**
     * Marks a CorpOrder as completed (FULFILLED).
     */
    public function completeOrder(CorpOrder $order, User $user): CorpOrder
    {
        if ($order->getFulfiller() === null) {
            $order->setFulfiller($user);
        }
        foreach ($order->getItems() as $item) {
            $item->setIsFulfilled(true);
            if ($item->getFulfiller() === null) {
                $item->setFulfiller($order->getFulfiller() ?? $user);
                $item->setFulfilledAt(new \DateTimeImmutable());
            }
        }

        $order->setStatus(CorpOrder::STATUS_FULFILLED);
        $order->setFulfilledAt(new \DateTimeImmutable());
        $order->recalculateTotals();
        $this->entityManager->flush();

        return $order;
    }

    /**
     * Toggles or sets fulfillment status of a specific CorpOrderItem.
     */
    public function fulfillItem(CorpOrderItem $item, User $fulfiller, bool $status = true): CorpOrder
    {
        $item->setIsFulfilled($status);
        $item->setFulfiller($status ? $fulfiller : null);
        $item->setFulfilledAt($status ? new \DateTimeImmutable() : null);

        $order = $item->getOrder();

        if ($order !== null) {
            if ($status && $order->getFulfiller() === null) {
                $order->setFulfiller($fulfiller);
            } elseif (!$status) {
                $remainingFulfiller = null;
                foreach ($order->getItems() as $otherItem) {
                    if ($otherItem->isFulfilled() && $otherItem->getFulfiller()) {
                        $remainingFulfiller = $otherItem->getFulfiller();
                        break;
                    }
                }
                $order->setFulfiller($remainingFulfiller);
            }

            $order->recalculateTotals();
        }

        $this->entityManager->flush();

        return $order;
    }

    /**
     * Fulfills all remaining open items of a CorpOrder at once (alias for acceptOrder).
     */
    public function fulfillAll(CorpOrder $order, User $fulfiller): CorpOrder
    {
        return $this->acceptOrder($order, $fulfiller);
    }

    /**
     * Cancels an order.
     */
    public function cancelOrder(CorpOrder $order): CorpOrder
    {
        $order->setStatus(CorpOrder::STATUS_CANCELLED);
        $this->entityManager->flush();

        return $order;
    }

    /**
     * Reopens a cancelled or fulfilled order.
     */
    public function reopenOrder(CorpOrder $order): CorpOrder
    {
        $order->setFulfiller(null);
        foreach ($order->getItems() as $item) {
            $item->setIsFulfilled(false);
            $item->setFulfiller(null);
        }
        $order->setStatus(CorpOrder::STATUS_OPEN);
        $order->setFulfilledAt(null);
        $order->recalculateTotals();
        $this->entityManager->flush();

        return $order;
    }

    /**
     * Formats an order for JSON API response including live market price comparison.
     */
    public function formatOrderForApi(CorpOrder $order): array
    {
        $isBuyOrder = $order->getType() === CorpOrder::TYPE_BUY;
        $useJitaBuy = !$isBuyOrder;

        $itemsData = [];
        $liveTotalPrice = 0.0;
        $fulfilledItemCount = 0;
        $totalItemCount = count($order->getItems());

        $multiplier = max(0, $order->getPercentToJita()) / 100.0;

        foreach ($order->getItems() as $item) {
            $livePriceInfo = $this->jitaPriceService->getAverageJitaPrice($item->getTypeId(), $useJitaBuy);
            $liveUnitPrice = (float)($livePriceInfo['price'] ?? 0.0);
            $liveItemTotal = ($liveUnitPrice * $multiplier) * $item->getAmount();
            $liveTotalPrice += $liveItemTotal;

            if ($item->isFulfilled()) {
                $fulfilledItemCount++;
            }

            $itemsData[] = [
                'id' => $item->getId(),
                'typeId' => $item->getTypeId(),
                'name' => $item->getName(),
                'amount' => $item->getAmount(),
                'unitPrice' => (float)$item->getUnitPrice(),
                'totalPrice' => (float)$item->getTotalPrice(),
                'unitVolume' => (float)$item->getUnitVolume(),
                'totalVolume' => (float)$item->getTotalVolume(),
                'slot' => $item->getSlot(),
                'sortOrder' => $item->getSortOrder(),
                'isFulfilled' => $item->isFulfilled(),
                'fulfiller' => $item->getFulfiller() ? [
                    'id' => $item->getFulfiller()->getId(),
                    'displayName' => $item->getFulfiller()->getDisplayName() ?: $item->getFulfiller()->getUserIdentifier(),
                ] : null,
                'fulfilledAt' => $item->getFulfilledAt()?->format('d.m.Y H:i'),
                'liveUnitPrice' => round($liveUnitPrice, 2),
                'liveTotalPrice' => round($liveItemTotal, 2),
            ];
        }

        $snapshotTotal = (float)$order->getTotalPrice();
        $priceDiff = $liveTotalPrice - $snapshotTotal;
        $priceDiffPercent = $snapshotTotal > 0 ? ($priceDiff / $snapshotTotal) * 100 : 0.0;

        $fulfillersMap = [];
        if ($order->getFulfiller()) {
            $fId = $order->getFulfiller()->getId();
            $fulfillersMap[$fId] = [
                'id' => $fId,
                'displayName' => $order->getFulfiller()->getDisplayName() ?: $order->getFulfiller()->getUserIdentifier(),
            ];
        }
        foreach ($order->getItems() as $item) {
            if ($item->getFulfiller()) {
                $fId = $item->getFulfiller()->getId();
                if (!isset($fulfillersMap[$fId])) {
                    $fulfillersMap[$fId] = [
                        'id' => $fId,
                        'displayName' => $item->getFulfiller()->getDisplayName() ?: $item->getFulfiller()->getUserIdentifier(),
                    ];
                }
            }
        }

        return [
            'id' => $order->getId(),
            'type' => $order->getType(),
            'status' => $order->getStatus(),
            'title' => $order->getTitle(),
            'isFitting' => $order->isFitting(),
            'shipTypeId' => $order->getShipTypeId(),
            'percentToJita' => $order->getPercentToJita(),
            'totalPrice' => $snapshotTotal,
            'totalVolume' => (float)$order->getTotalVolume(),
            'note' => $order->getNote() ?? '',
            'contractId' => $order->getContractId(),
            'createdAt' => $order->getCreatedAt()->format('d.m.Y H:i'),
            'updatedAt' => $order->getUpdatedAt()->format('d.m.Y H:i'),
            'fulfilledAt' => $order->getFulfilledAt()?->format('d.m.Y H:i'),
            'user' => [
                'id' => $order->getUser()->getId(),
                'displayName' => $order->getUser()->getDisplayName() ?: $order->getUser()->getUserIdentifier(),
            ],
            'fulfiller' => $order->getFulfiller() ? [
                'id' => $order->getFulfiller()->getId(),
                'displayName' => $order->getFulfiller()->getDisplayName() ?: $order->getFulfiller()->getUserIdentifier(),
            ] : null,
            'fulfillers' => array_values($fulfillersMap),
            'items' => $itemsData,
            'fulfilledItemCount' => $fulfilledItemCount,
            'totalItemCount' => $totalItemCount,
            'isFullyFulfilled' => $order->getStatus() === CorpOrder::STATUS_FULFILLED,
            'liveTotalPrice' => round($liveTotalPrice, 2),
            'priceDiff' => round($priceDiff, 2),
            'priceDiffPercent' => round($priceDiffPercent, 1),
        ];
    }

    /**
     * Matches open contracts from the database with active orders.
     */
    public function syncContractsWithOrders(): int
    {
        $orderRepo = $this->entityManager->getRepository(CorpOrder::class);
        $contractRepo = $this->entityManager->getRepository(EveCharacterContract::class);

        /** @var CorpOrder[] $activeOrders */
        $activeOrders = $orderRepo->createQueryBuilder('o')
            ->where('o.status IN (:activeStatuses)')
            ->setParameter('activeStatuses', [CorpOrder::STATUS_OPEN, CorpOrder::STATUS_IN_PROGRESS])
            ->getQuery()
            ->getResult();

        $updatedCount = 0;

        foreach ($activeOrders as $order) {
            // Check if contract already linked
            if ($order->getContractId()) {
                /** @var EveCharacterContract|null $contract */
                $contract = $contractRepo->findOneBy(['contractId' => $order->getContractId()]);
                if ($contract) {
                    $status = strtolower($contract->getStatus() ?? '');
                    if (in_array($status, ['finished', 'finished_issuer', 'finished_contractor'], true)) {
                        $order->setStatus(CorpOrder::STATUS_FULFILLED);
                        foreach ($order->getItems() as $item) {
                            $item->setIsFulfilled(true);
                        }
                        $updatedCount++;
                    } elseif ($status === 'in_progress' && $order->getStatus() === CorpOrder::STATUS_OPEN) {
                        $order->setStatus(CorpOrder::STATUS_IN_PROGRESS);
                        $updatedCount++;
                    }
                }
                continue;
            }

            // Search for contracts matching "Order #<id>" or "WH-Order #<id>" in title
            $matchedContract = $this->_findContractForOrder($order);

            if ($matchedContract) {
                $order->setContractId($matchedContract->getContractId());
                $status = strtolower($matchedContract->getStatus() ?? '');
                if (in_array($status, ['finished', 'finished_issuer', 'finished_contractor'], true)) {
                    $order->setStatus(CorpOrder::STATUS_FULFILLED);
                    foreach ($order->getItems() as $item) {
                        $item->setIsFulfilled(true);
                    }
                } else {
                    $order->setStatus(CorpOrder::STATUS_IN_PROGRESS);
                }
                $updatedCount++;
            }
        }

        if ($updatedCount > 0) {
            $this->entityManager->flush();
        }

        return $updatedCount;
    }

    /**
     * Checks whether a contract title references exactly the given order id (e.g. #1 must not match #12).
     */
    public function contractTitleMatchesOrder(?string $title, int $orderId): bool
    {
        if ($title === null) {
            return false;
        }

        return preg_match('/Order #' . $orderId . '(?!\d)/i', $title) === 1;
    }

    private function _findContractForOrder(CorpOrder $order): ?EveCharacterContract
    {
        /** @var EveCharacterContract[] $candidates */
        $candidates = $this->entityManager->getRepository(EveCharacterContract::class)
            ->createQueryBuilder('c')
            ->where('c.title LIKE :pattern')
            ->setParameter('pattern', sprintf('%%Order #%d%%', $order->getId()))
            ->getQuery()
            ->getResult();

        foreach ($candidates as $candidate) {
            if ($this->contractTitleMatchesOrder($candidate->getTitle(), $order->getId())) {
                return $candidate;
            }
        }

        return null;
    }
}
