<?php

namespace App\Service;

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
        $order->setFulfiller($order->getFulfiller() ?? $fulfiller);
        foreach ($order->getItems()->toArray() as $item) {
            if (!$item->isFulfilled()) {
                $this->_assignItem($item, $fulfiller);
            }
        }

        $order->recalculateTotals();
        $this->entityManager->flush();

        return $order;
    }

    /**
     * Unaccepts / releases items of a CorpOrder back to OPEN, delivered items stay with their supplier.
     */
    public function unacceptOrder(CorpOrder $order, ?User $user = null): CorpOrder
    {
        foreach ($order->getItems()->toArray() as $item) {
            if (!$item->isFulfilled() || $item->isDelivered()) {
                continue;
            }
            if ($user === null || $item->getFulfiller() === $user || $order->getUser() === $user) {
                $this->_releaseItem($item);
            }
        }

        $this->_updateOrderFulfiller($order);
        $order->recalculateTotals();
        $this->entityManager->flush();

        return $order;
    }

    /**
     * Archives a CorpOrder (FULFILLED), open items stay open instead of being assigned to the archiving user.
     */
    public function completeOrder(CorpOrder $order): CorpOrder
    {
        $order->setStatus(CorpOrder::STATUS_FULFILLED);
        $order->setFulfilledAt(new \DateTimeImmutable());
        $order->recalculateTotals();
        $this->entityManager->flush();

        return $order;
    }

    /**
     * Takes (optionally only a part of) an item or releases it again.
     *
     * @param int|null $amount Partial amount to take, null takes the whole item
     */
    public function fulfillItem(CorpOrderItem $item, User $fulfiller, bool $status = true, ?int $amount = null): CorpOrder
    {
        $order = $item->getOrder();

        if ($status) {
            if ($amount !== null && $amount < $item->getAmount()) {
                $item = $this->_splitItem($item, $amount);
            }
            $this->_assignItem($item, $fulfiller);
            if ($order->getFulfiller() === null) {
                $order->setFulfiller($fulfiller);
            }
        } else {
            $this->_releaseItem($item);
            $this->_updateOrderFulfiller($order);
        }

        $order->recalculateTotals();
        $this->entityManager->flush();

        return $order;
    }

    /**
     * Checks whether the user may move the item to the given delivery status.
     */
    public function canChangeDeliveryStatus(CorpOrderItem $item, User $user, string $status, bool $isOfficer): bool
    {
        if (!in_array($status, CorpOrderItem::DELIVERY_STATUSES, true) || !$item->isFulfilled()) {
            return false;
        }

        $order = $item->getOrder();
        if (!$this->_isActive($order)) {
            return false;
        }
        if ($order->getType() !== CorpOrder::TYPE_BUY && in_array($status, CorpOrderItem::BUY_ONLY_DELIVERY_STATUSES, true)) {
            return false;
        }
        if ($isOfficer) {
            return true;
        }

        // Receipt is confirmed or taken back by the customer only
        if ($order->getUser() === $user) {
            if ($status === CorpOrderItem::DELIVERY_RECEIVED) {
                return true;
            }
            if ($item->isReceived() && $status === CorpOrderItem::DELIVERY_DELIVERED) {
                return true;
            }
        }

        // The supplier handles his own progress until the customer confirmed the receipt
        return $item->getFulfiller() === $user
            && !$item->isReceived()
            && $status !== CorpOrderItem::DELIVERY_RECEIVED;
    }

    /**
     * Sets the delivery status of one item, the caller checks the permission first.
     */
    public function setItemDeliveryStatus(CorpOrderItem $item, string $status): CorpOrder
    {
        $item->setDeliveryStatus($status);

        $order = $item->getOrder();
        $order->recalculateTotals();
        $this->entityManager->flush();

        return $order;
    }

    /**
     * Sets the delivery status on every item of the order the user may change (supplier: own items, customer: receipt).
     *
     * @return int Number of changed items
     */
    public function setOrderDeliveryStatus(CorpOrder $order, User $user, string $status, bool $isOfficer): int
    {
        $changedCount = 0;
        foreach ($order->getItems() as $item) {
            if ($item->getDeliveryStatus() === $status) {
                continue;
            }
            // Status changes in bulk only touch the own share, receipt applies to all suppliers
            if ($status !== CorpOrderItem::DELIVERY_RECEIVED && $item->getFulfiller() !== $user) {
                continue;
            }
            if (!$this->canChangeDeliveryStatus($item, $user, $status, $isOfficer)) {
                continue;
            }
            $item->setDeliveryStatus($status);
            $changedCount++;
        }

        if ($changedCount > 0) {
            $order->recalculateTotals();
            $this->entityManager->flush();
        }

        return $changedCount;
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
     * Brings a cancelled or archived order back to the active orders, suppliers and delivery status are kept.
     */
    public function reopenOrder(CorpOrder $order): CorpOrder
    {
        $order->setStatus(CorpOrder::STATUS_OPEN);
        $order->setFulfilledAt(null);
        $order->recalculateTotals();
        $this->entityManager->flush();

        return $order;
    }

    private function _isActive(?CorpOrder $order): bool
    {
        return $order !== null
            && ($order->getStatus() === CorpOrder::STATUS_OPEN || $order->getStatus() === CorpOrder::STATUS_IN_PROGRESS);
    }

    private function _assignItem(CorpOrderItem $item, User $fulfiller): void
    {
        $item->setFulfiller($fulfiller);
        $item->setDeliveryStatus(CorpOrderItem::DELIVERY_ACCEPTED);
        $this->_mergeIntoSiblingItem($item);
    }

    private function _releaseItem(CorpOrderItem $item): void
    {
        $item->setFulfiller(null);
        $item->setDeliveryStatus(null);
        $this->_mergeIntoSiblingItem($item);
    }

    /**
     * Splits the given amount off an item into a new item of the same order and returns the new item.
     */
    private function _splitItem(CorpOrderItem $item, int $amount): CorpOrderItem
    {
        $order = $item->getOrder();
        $multiplier = max(0, $order->getPercentToJita()) / 100.0;

        $splitItem = new CorpOrderItem();
        $splitItem->setTypeId($item->getTypeId());
        $splitItem->setName($item->getName());
        $splitItem->setSlot($item->getSlot());
        $splitItem->setSortOrder($item->getSortOrder());
        $splitItem->setUnitPrice($item->getUnitPrice());
        $splitItem->setUnitVolume($item->getUnitVolume());
        $this->_setItemAmount($splitItem, $amount, $multiplier);
        $this->_setItemAmount($item, $item->getAmount() - $amount, $multiplier);

        $order->addItem($splitItem);

        return $splitItem;
    }

    /**
     * Merges the item into another item of the same type, slot, supplier and status so parts do not pile up.
     */
    private function _mergeIntoSiblingItem(CorpOrderItem $item): void
    {
        $order = $item->getOrder();
        foreach ($order->getItems() as $sibling) {
            if ($sibling === $item
                || $sibling->getTypeId() !== $item->getTypeId()
                || $sibling->getSlot() !== $item->getSlot()
                || $sibling->getFulfiller() !== $item->getFulfiller()
                || $sibling->getDeliveryStatus() !== $item->getDeliveryStatus()
            ) {
                continue;
            }

            $multiplier = max(0, $order->getPercentToJita()) / 100.0;
            $this->_setItemAmount($sibling, $sibling->getAmount() + $item->getAmount(), $multiplier);
            $order->removeItem($item);
            return;
        }
    }

    private function _setItemAmount(CorpOrderItem $item, int $amount, float $multiplier): void
    {
        $item->setAmount($amount);
        $item->setTotalPrice(number_format(((float)$item->getUnitPrice() * $multiplier) * $amount, 2, '.', ''));
        $item->setTotalVolume(number_format((float)$item->getUnitVolume() * $amount, 2, '.', ''));
    }

    private function _updateOrderFulfiller(CorpOrder $order): void
    {
        $remainingFulfiller = null;
        foreach ($order->getItems() as $item) {
            if ($item->isFulfilled() && $item->getFulfiller()) {
                $remainingFulfiller = $item->getFulfiller();
                break;
            }
        }
        $order->setFulfiller($remainingFulfiller);
    }

    /**
     * Formats an order for JSON API response including live market price comparison.
     */
    public function formatOrderForApi(CorpOrder $order): array
    {
        $itemsData = [];
        $fulfilledItemCount = 0;
        $deliveredItemCount = 0;
        $receivedItemCount = 0;
        $totalItemCount = count($order->getItems());

        foreach ($order->getItems() as $item) {
            if ($item->isFulfilled()) {
                $fulfilledItemCount++;
            }
            if ($item->isDelivered()) {
                $deliveredItemCount++;
            }
            if ($item->isReceived()) {
                $receivedItemCount++;
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
                'deliveryStatus' => $item->getDeliveryStatus(),
                'deliveredAt' => $item->getDeliveredAt()?->format('d.m.Y H:i'),
            ];
        }

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
            'totalPrice' => (float)$order->getTotalPrice(),
            'totalVolume' => (float)$order->getTotalVolume(),
            'note' => $order->getNote() ?? '',
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
            'deliveredItemCount' => $deliveredItemCount,
            'receivedItemCount' => $receivedItemCount,
            'totalItemCount' => $totalItemCount,
            'isFullyReceived' => $totalItemCount > 0 && $receivedItemCount === $totalItemCount,
            'isFullyFulfilled' => $order->getStatus() === CorpOrder::STATUS_FULFILLED,
        ];
    }

    /**
     * Current Jita value of open orders compared to their snapshot price, loaded separately as it needs one ESI request per item type.
     *
     * @param CorpOrder[] $orders
     * @return array<int, array{liveTotalPrice: float, priceDiff: float, priceDiffPercent: float}> Order ID => live price
     */
    public function getLivePrices(array $orders): array
    {
        $livePrices = [];
        foreach ($orders as $order) {
            if ($order->getStatus() !== CorpOrder::STATUS_OPEN) {
                continue;
            }
            $livePrices[$order->getId()] = $this->_calculateLivePrice($order);
        }

        return $livePrices;
    }

    /**
     * @return array{liveTotalPrice: float, priceDiff: float, priceDiffPercent: float}
     */
    private function _calculateLivePrice(CorpOrder $order): array
    {
        $useJitaBuy = $order->getType() !== CorpOrder::TYPE_BUY;
        $multiplier = max(0, $order->getPercentToJita()) / 100.0;

        $liveTotalPrice = 0.0;
        foreach ($order->getItems() as $item) {
            $livePriceInfo = $this->jitaPriceService->getAverageJitaPrice($item->getTypeId(), $useJitaBuy);
            $liveUnitPrice = (float)($livePriceInfo['price'] ?? 0.0);
            $liveTotalPrice += ($liveUnitPrice * $multiplier) * $item->getAmount();
        }

        $snapshotTotal = (float)$order->getTotalPrice();
        $priceDiff = $liveTotalPrice - $snapshotTotal;
        $priceDiffPercent = $snapshotTotal > 0 ? ($priceDiff / $snapshotTotal) * 100 : 0.0;

        return [
            'liveTotalPrice' => round($liveTotalPrice, 2),
            'priceDiff' => round($priceDiff, 2),
            'priceDiffPercent' => round($priceDiffPercent, 1),
        ];
    }
}
