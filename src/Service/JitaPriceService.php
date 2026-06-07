<?php

namespace App\Service;

use App\Service\Esi\EsiClient;

class JitaPriceService
{
    private const REGION_THE_FORGE = 10000002;
    private const LOCATION_JITA_STATION = 60003760;

    public function __construct(
        private readonly EsiClient $esiClient
    ) {}

    /**
     * Gets the average Jita price for a given type ID.
     * 
     * @param int $typeId The EVE item type ID
     * @param bool $isBuyOrder True for Buy orders (returns average of top buy orders), false for Sell orders (returns average of top sell orders)
     * @return array{
     *     price: float|null,
     *     count: int,
     *     warning: bool,
     *     message: string|null
     * }
     */
    public function getAverageJitaPrice(int $typeId, bool $isBuyOrder): array
    {
        try {
            // EsiClient handles caching internally based on the HTTP Response headers (Expires)
            $orders = $this->esiClient->request(
                'GET',
                sprintf('markets/%d/orders/', self::REGION_THE_FORGE),
                [
                    'query' => [
                        'type_id' => $typeId,
                        'order_type' => $isBuyOrder ? 'buy' : 'sell'
                    ]
                ]
            );

            // Filter for Jita IV - Moon 4 station
            $jitaOrders = array_filter($orders, function ($order) use ($isBuyOrder) {
                return $order['location_id'] === self::LOCATION_JITA_STATION
                    && $order['is_buy_order'] === $isBuyOrder;
            });

            if (empty($jitaOrders)) {
                return [
                    'price' => null,
                    'count' => 0,
                    'warning' => true,
                    'message' => 'Keine Jita-Preise gefunden.'
                ];
            }

            // Sort prices:
            // For buy orders, the buyer wants to pay the most competitive (highest) price to get the item.
            // So the "best" buy prices are the highest. We sort descending.
            // For sell orders, the seller wants to sell at the most competitive (lowest) price to get buyers.
            // So the "best" sell prices are the lowest. We sort ascending.
            usort($jitaOrders, function ($a, $b) use ($isBuyOrder) {
                if ($isBuyOrder) {
                    return $b['price'] <=> $a['price'];
                } else {
                    return $a['price'] <=> $b['price'];
                }
            });

            // Take up to 10 best orders
            $topOrders = array_slice($jitaOrders, 0, 10);
            $totalPrice = 0.0;
            foreach ($topOrders as $order) {
                $totalPrice += (float)$order['price'];
            }
            
            $count = count($topOrders);
            $average = $totalPrice / $count;

            $warning = $count < 10;
            $message = $warning ? sprintf('Nur %d statt 10 Preise vorhanden.', $count) : null;

            return [
                'price' => $average,
                'count' => $count,
                'warning' => $warning,
                'message' => $message
            ];
        } catch (\Exception $e) {
            return [
                'price' => null,
                'count' => 0,
                'warning' => true,
                'message' => 'Fehler beim Abrufen der ESI-Preise: ' . $e->getMessage()
            ];
        }
    }

    /**
     * Fetch all global market prices from ESI.
     * Returns a map of typeId => averagePrice
     * 
     * @return array<int, float>
     */
    public function getGlobalPrices(): array
    {
        try {
            $data = $this->esiClient->request('GET', 'markets/prices/');
            if (!is_array($data)) {
                return [];
            }

            $prices = [];
            foreach ($data as $row) {
                if (isset($row['type_id']) && isset($row['average_price'])) {
                    $prices[(int)$row['type_id']] = (float)$row['average_price'];
                }
            }
            return $prices;
        } catch (\Exception $e) {
            error_log('[JitaPriceService] Failed to fetch global prices: ' . $e->getMessage());
            return [];
        }
    }
}
