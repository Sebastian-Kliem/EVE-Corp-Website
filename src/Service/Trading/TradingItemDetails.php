<?php

namespace App\Service\Trading;

use App\Entity\EveCharacter;
use App\Entity\EveCharacterMarketOrder;
use App\Service\Esi\EsiClient;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Detail view of one type at a hub: daily history and the live order book of the station, loaded only when a row is opened.
 */
class TradingItemDetails
{
    public const HISTORY_DAYS = 90;
    private const BOOK_DEPTH = 10;

    public function __construct(
        private readonly Connection $connection,
        private readonly EsiClient $esiClient,
        private readonly EntityManagerInterface $entityManager
    ) {}

    /**
     * @return list<array{date: string, average: float, highest: float, lowest: float, volume: int, orderCount: int}> Oldest day first
     */
    public function loadHistory(int $regionId, int $typeId, \DateTimeImmutable $now): array
    {
        $rows = $this->connection->fetchAllAssociative(
            'SELECT date, average, highest, lowest, volume, order_count FROM market_history WHERE region_id = ? AND type_id = ? AND date >= ? ORDER BY date ASC',
            [$regionId, $typeId, $now->modify(sprintf('-%d days', self::HISTORY_DAYS))->format('Y-m-d')]
        );

        $history = [];
        foreach ($rows as $row) {
            $history[] = [
                'date' => (string)$row['date'],
                'average' => (float)$row['average'],
                'highest' => (float)$row['highest'],
                'lowest' => (float)$row['lowest'],
                'volume' => (int)$row['volume'],
                'orderCount' => (int)$row['order_count'],
            ];
        }

        return $history;
    }

    /**
     * ESI caches the type filtered book for 5 minutes, repeated clicks are served from the cache.
     *
     * @param EveCharacter[] $ownCharacters Their orders are marked in the book
     * @return array{buy: list<array<string, mixed>>, sell: list<array<string, mixed>>, buyCount: int, sellCount: int}
     */
    public function loadStationBook(int $regionId, int $stationId, int $typeId, array $ownCharacters): array
    {
        $response = $this->esiClient->requestAllPages(sprintf('markets/%d/orders/', $regionId), [
            'query' => ['type_id' => $typeId, 'order_type' => 'all'],
        ]);

        $ownOrderIds = $this->_findOwnOrderIds($ownCharacters, $stationId, $typeId);
        $buyOrders = [];
        $sellOrders = [];
        foreach ($response['data'] ?? [] as $order) {
            if ((int)$order['location_id'] !== $stationId) {
                continue;
            }
            $bookOrder = [
                'price' => (float)$order['price'],
                'volumeRemain' => (int)$order['volume_remain'],
                'issued' => (string)$order['issued'],
                'isOwn' => isset($ownOrderIds[(int)$order['order_id']]),
            ];
            if (!empty($order['is_buy_order'])) {
                $buyOrders[] = $bookOrder;
            } else {
                $sellOrders[] = $bookOrder;
            }
        }

        usort($buyOrders, fn(array $first, array $second): int => $second['price'] <=> $first['price']);
        usort($sellOrders, fn(array $first, array $second): int => $first['price'] <=> $second['price']);

        return [
            'buy' => array_slice($buyOrders, 0, self::BOOK_DEPTH),
            'sell' => array_slice($sellOrders, 0, self::BOOK_DEPTH),
            'buyCount' => count($buyOrders),
            'sellCount' => count($sellOrders),
        ];
    }

    /**
     * @param EveCharacter[] $ownCharacters
     * @return array<int, true>
     */
    private function _findOwnOrderIds(array $ownCharacters, int $stationId, int $typeId): array
    {
        if ($ownCharacters === []) {
            return [];
        }

        $orderIds = [];
        $orders = $this->entityManager->getRepository(EveCharacterMarketOrder::class)->findBy([
            'character' => $ownCharacters,
            'locationId' => (string)$stationId,
            'typeId' => $typeId,
        ]);
        foreach ($orders as $order) {
            $orderIds[(int)$order->getOrderId()] = true;
        }

        return $orderIds;
    }
}
