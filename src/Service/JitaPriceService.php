<?php

namespace App\Service;

use App\Service\Esi\EsiClient;

class JitaPriceService
{
    private const REGION_THE_FORGE = 10000002;
    private const LOCATION_JITA_STATION = 60003760;

    // Maximale Abweichung von der jeweils besten Order (15%)
    private const BEST_PRICE_TOLERANCE = 0.15;

    // Maximale Abweichung vom globalen 24h-Durchschnittspreis (30%)
    private const GLOBAL_PRICE_TOLERANCE = 0.30;

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

            // Get global average price for sanity check/fallback
            $globalPrices = $this->getGlobalPrices();
            $globalPrice = $globalPrices[$typeId] ?? null;

            // 1. Filter by global price tolerance if global price is available
            if ($globalPrice !== null && !empty($jitaOrders)) {
                $minAllowed = $globalPrice * (1 - self::GLOBAL_PRICE_TOLERANCE);
                $maxAllowed = $globalPrice * (1 + self::GLOBAL_PRICE_TOLERANCE);

                $jitaOrders = array_filter($jitaOrders, function ($order) use ($minAllowed, $maxAllowed) {
                    $price = (float)$order['price'];
                    return $price >= $minAllowed && $price <= $maxAllowed;
                });
            }

            if (empty($jitaOrders)) {
                // Fallback to global price if no valid Jita orders are found but global price exists
                if ($globalPrice !== null) {
                    return [
                        'price' => $globalPrice,
                        'count' => 0,
                        'warning' => true,
                        'message' => 'Keine validen Jita-Preise gefunden. Nutze globalen ESI-Durchschnittspreis.'
                    ];
                }

                return [
                    'price' => null,
                    'count' => 0,
                    'warning' => true,
                    'message' => 'Keine Jita-Preise oder globalen ESI-Preise gefunden.'
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

            // 2. Filter by tolerance from the best order
            $bestPrice = (float)$jitaOrders[0]['price'];
            $jitaOrders = array_filter($jitaOrders, function ($order) use ($bestPrice, $isBuyOrder) {
                $price = (float)$order['price'];
                if ($isBuyOrder) {
                    return $price >= ($bestPrice * (1 - self::BEST_PRICE_TOLERANCE));
                } else {
                    return $price <= ($bestPrice * (1 + self::BEST_PRICE_TOLERANCE));
                }
            });

            // Re-sort because array_filter preserves keys but doesn't break usort ordering, 
            // though array_slice is safer with re-indexed arrays
            $jitaOrders = array_values($jitaOrders);

            // Take up to 10 best orders
            $topOrders = array_slice($jitaOrders, 0, 10);
            $totalPrice = 0.0;
            foreach ($topOrders as $order) {
                $totalPrice += (float)$order['price'];
            }
            
            $count = count($topOrders);
            if ($count === 0) {
                if ($globalPrice !== null) {
                    return [
                        'price' => $globalPrice,
                        'count' => 0,
                        'warning' => true,
                        'message' => 'Keine validen Jita-Preise nach Filterung gefunden. Nutze globalen ESI-Durchschnittspreis.'
                    ];
                }
                return [
                    'price' => null,
                    'count' => 0,
                    'warning' => true,
                    'message' => 'Keine validen Jita-Preise gefunden.'
                ];
            }

            $average = $totalPrice / $count;

            $warning = $count < 10;
            $message = $warning ? sprintf('Nur %d statt 10 Preise nach Ausreißer-Filterung vorhanden.', $count) : null;

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
