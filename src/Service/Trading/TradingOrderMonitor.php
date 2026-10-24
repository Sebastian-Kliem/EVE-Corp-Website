<?php

namespace App\Service\Trading;

use App\Entity\EveCharacter;
use App\Entity\EveCharacterMarketOrder;
use App\Entity\User;
use App\Repository\TradingOrderCheckRepository;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Compares the orders of every user's trading character with the hub order book while the hub sync has it in memory.
 */
class TradingOrderMonitor
{
    /** @var list<EveCharacterMarketOrder> Trading character orders at the station of the running sync */
    private array $watchedOrders = [];
    /** @var array<int, array<int, true>> Trading character ID => order IDs of all characters of its user */
    private array $ownOrderIdsByCharacter = [];

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly TradingOrderCheckRepository $checkRepository
    ) {}

    /**
     * @return int[] Type IDs the order book sync has to keep for this station
     */
    public function prepareStation(int $stationId): array
    {
        $this->watchedOrders = [];
        $this->ownOrderIdsByCharacter = [];

        $typeIds = [];
        foreach ($this->_findTradingCharacters() as $tradingCharacter) {
            $ownCharacters = $this->entityManager->getRepository(EveCharacter::class)->findBy(['user' => $tradingCharacter->getUser()]);
            $stationOrders = $this->entityManager->getRepository(EveCharacterMarketOrder::class)->findBy([
                'character' => $ownCharacters,
                'locationId' => (string)$stationId,
            ]);

            $ownOrderIds = [];
            foreach ($stationOrders as $order) {
                $ownOrderIds[(int)$order->getOrderId()] = true;
                if ($order->getCharacter()->getId() === $tradingCharacter->getId()) {
                    $this->watchedOrders[] = $order;
                    $typeIds[(int)$order->getTypeId()] = true;
                }
            }
            $this->ownOrderIdsByCharacter[(int)$tradingCharacter->getId()] = $ownOrderIds;
        }

        return array_keys($typeIds);
    }

    /**
     * @param array<int, list<array{order_id: int, price: float, volume: int, is_buy: bool, issued: int}>> $bookOrdersByType Station orders per type
     */
    public function storeStationResults(int $stationId, array $bookOrdersByType, \DateTimeImmutable $checkedAt): int
    {
        $rows = [];
        foreach ($this->watchedOrders as $order) {
            $characterId = (int)$order->getCharacter()->getId();
            $position = $this->calculatePosition(
                (int)$order->getOrderId(),
                (bool)$order->isBuy(),
                (float)$order->getPrice(),
                $bookOrdersByType[(int)$order->getTypeId()] ?? [],
                $this->ownOrderIdsByCharacter[$characterId] ?? []
            );
            $rows[] = [
                'order_id' => (int)$order->getOrderId(),
                'character_id' => $characterId,
                'location_id' => $stationId,
                'type_id' => (int)$order->getTypeId(),
                'is_buy' => $order->isBuy() ? 1 : 0,
                'in_book' => $position['inBook'] ? 1 : 0,
                'current_price' => $position['currentPrice'] === null ? null : number_format($position['currentPrice'], 2, '.', ''),
                'current_volume_remain' => $position['currentVolumeRemain'],
                'best_competitor_price' => $position['bestCompetitorPrice'] === null ? null : number_format($position['bestCompetitorPrice'], 2, '.', ''),
                'competitors_ahead' => $position['competitorsAhead'],
                'competitor_volume_ahead' => $position['competitorVolumeAhead'],
                'checked_at' => $checkedAt->format('Y-m-d H:i:s'),
            ];
        }

        $this->checkRepository->replaceStation($stationId, $rows);
        $this->watchedOrders = [];
        $this->ownOrderIdsByCharacter = [];

        return count($rows);
    }

    /**
     * Competitors are ahead with a better price, or with the same price and an older order (EVE fills the older one first).
     *
     * @param list<array{order_id: int, price: float, volume: int, is_buy: bool, issued: int}> $bookOrders Station orders of the type
     * @param array<int, true> $ownOrderIds Orders of the user's characters, they never compete
     * @return array{inBook: bool, currentPrice: ?float, currentVolumeRemain: ?int, bestCompetitorPrice: ?float, competitorsAhead: int, competitorVolumeAhead: int}
     */
    public function calculatePosition(int $orderId, bool $isBuy, float $syncedPrice, array $bookOrders, array $ownOrderIds): array
    {
        $ownBookOrder = null;
        foreach ($bookOrders as $bookOrder) {
            if ($bookOrder['order_id'] === $orderId) {
                $ownBookOrder = $bookOrder;
                break;
            }
        }
        $ownPrice = $ownBookOrder['price'] ?? $syncedPrice;
        $ownIssued = $ownBookOrder['issued'] ?? PHP_INT_MAX;

        $bestCompetitorPrice = null;
        $competitorsAhead = 0;
        $competitorVolumeAhead = 0;
        foreach ($bookOrders as $bookOrder) {
            if ($bookOrder['is_buy'] !== $isBuy || isset($ownOrderIds[$bookOrder['order_id']])) {
                continue;
            }
            $price = $bookOrder['price'];
            if ($bestCompetitorPrice === null || ($isBuy ? $price > $bestCompetitorPrice : $price < $bestCompetitorPrice)) {
                $bestCompetitorPrice = $price;
            }
            $isBetter = $isBuy ? $price > $ownPrice : $price < $ownPrice;
            $isOlderAtSamePrice = abs($price - $ownPrice) < 0.005 && $bookOrder['issued'] < $ownIssued;
            if ($isBetter || $isOlderAtSamePrice) {
                $competitorsAhead++;
                $competitorVolumeAhead += $bookOrder['volume'];
            }
        }

        return [
            'inBook' => $ownBookOrder !== null,
            'currentPrice' => $ownBookOrder['price'] ?? null,
            'currentVolumeRemain' => $ownBookOrder['volume'] ?? null,
            'bestCompetitorPrice' => $bestCompetitorPrice,
            'competitorsAhead' => $competitorsAhead,
            'competitorVolumeAhead' => $competitorVolumeAhead,
        ];
    }

    /**
     * @return list<EveCharacter>
     */
    private function _findTradingCharacters(): array
    {
        $characters = [];
        foreach ($this->entityManager->getRepository(User::class)->findAll() as $user) {
            $characterId = $user->getTradingCharacterId();
            if ($characterId === null) {
                continue;
            }
            $character = $this->entityManager->getRepository(EveCharacter::class)->find($characterId);
            // The setting could point to a character that moved to another user
            if ($character !== null && $character->getUser() === $user) {
                $characters[] = $character;
            }
        }

        return $characters;
    }
}
