<?php

namespace App\Service;

use App\Service\Esi\EsiClient;

class ReactionPriceService
{
    public const HUBS = [
        'jita' => [
            'name' => 'Jita',
            'fullName' => 'Jita IV - Moon 4 - Caldari Navy Assembly Plant',
            'regionId' => 10000002,
            'stationId' => 60003760,
            'solarSystemId' => 30000142,
        ],
        'amarr' => [
            'name' => 'Amarr',
            'fullName' => 'Amarr VIII (Oris) - Emperor Family Academy',
            'regionId' => 10000043,
            'stationId' => 60008494,
            'solarSystemId' => 30002187,
        ],
        'dodixie' => [
            'name' => 'Dodixie',
            'fullName' => 'Dodixie IX - Moon 20 - Federation Navy Assembly Plant',
            'regionId' => 10000032,
            'stationId' => 60011866,
            'solarSystemId' => 30002659,
        ],
        'hek' => [
            'name' => 'Hek',
            'fullName' => 'Hek VIII - Moon 12 - Boundless Creation Factory',
            'regionId' => 10000042,
            'stationId' => 60005686,
            'solarSystemId' => 30002053,
        ],
    ];

    public const HYBRID_POLYMERS = [
        30303 => 'Fulleroferrocene',
        30304 => 'PPD Fullerene Fibers',
        30305 => 'Fullerene Intercalated Graphite',
        30306 => 'Methanofullerene',
        30307 => 'Lanthanum Metallofullerene',
        30308 => 'Scandium Metallofullerene',
        30309 => 'Graphene Nanoribbons',
        30310 => 'Carbon-86 Epoxy Resin',
        30311 => 'C3-FTM Acid',
    ];

    public const REACTION_FORMULAS = [
        30303 => ['bpTypeId' => 46158, 'bpName' => 'Fulleroferrocene Reaction Formula'],
        30304 => ['bpTypeId' => 46159, 'bpName' => 'PPD Fullerene Fibers Reaction Formula'],
        30305 => ['bpTypeId' => 46160, 'bpName' => 'Fullerene Intercalated Graphite Reaction Formula'],
        30306 => ['bpTypeId' => 46157, 'bpName' => 'Methanofullerene Reaction Formula'],
        30307 => ['bpTypeId' => 46161, 'bpName' => 'Lanthanum Metallofullerene Reaction Formula'],
        30308 => ['bpTypeId' => 46162, 'bpName' => 'Scandium Metallofullerene Reaction Formula'],
        30309 => ['bpTypeId' => 46163, 'bpName' => 'Graphene Nanoribbons Reaction Formula'],
        30310 => ['bpTypeId' => 46164, 'bpName' => 'Carbon-86 Epoxy Resin Reaction Formula'],
        30311 => ['bpTypeId' => 46165, 'bpName' => 'C3-FTM Acid Reaction Formula'],
    ];

    // Raw Gas to Compressed Gas mapping
    public const COMPRESSED_GAS_MAP = [
        30370 => 62399, // C50 -> Compressed C50
        30371 => 62397, // C60 -> Compressed C60
        30372 => 62398, // C70 -> Compressed C70
        30373 => 62403, // C72 -> Compressed C72
        30374 => 62400, // C84 -> Compressed C84
        30375 => 62402, // C28 -> Compressed C28
        30376 => 62404, // C32 -> Compressed C32
        30377 => 62406, // C320 -> Compressed C320
        30378 => 62405, // C540 -> Compressed C540
    ];

    // All possible input material type IDs (Gases, Minerals, Fuel Blocks)
    private const INPUT_TYPE_IDS = [
        // Minerals
        34 => 'Tritanium',
        35 => 'Pyerite',
        36 => 'Mexallon',
        37 => 'Isogen',
        38 => 'Nocxium',
        39 => 'Zydrine',
        40 => 'Megacyte',
        // Fuel Blocks
        4246 => 'Hydrogen Fuel Block',
        4312 => 'Oxygen Fuel Block',
        4247 => 'Helium Fuel Block',
        4051 => 'Nitrogen Fuel Block',
        // Fullerite Gases
        30370 => 'Fullerite-C50',
        30371 => 'Fullerite-C60',
        30372 => 'Fullerite-C70',
        30373 => 'Fullerite-C72',
        30374 => 'Fullerite-C84',
        30375 => 'Fullerite-C28',
        30376 => 'Fullerite-C32',
        30377 => 'Fullerite-C320',
        30378 => 'Fullerite-C540',
    ];

    public function __construct(
        private readonly EsiClient $esiClient,
        private readonly SdeService $sdeService
    ) {}

    /**
     * Prepares all calculations and market data for the reaction calculator.
     */
    public function getReactionCalculatorData(): array
    {
        $reactions = [];
        $marketPrices = [];

        // 1. Fetch formulas using SdeService
        foreach (self::HYBRID_POLYMERS as $polymerTypeId => $polymerName) {
            $formula = self::REACTION_FORMULAS[$polymerTypeId] ?? null;
            if (!$formula) {
                continue;
            }

            $bpDetails = $this->sdeService->getBlueprintDetails($formula['bpTypeId'], 9);

            $reactions[] = [
                'polymerTypeId' => $polymerTypeId,
                'polymerName' => $polymerName,
                'formulaTypeId' => $formula['bpTypeId'],
                'formulaName' => $formula['bpName'],
                'outputQuantity' => $bpDetails['products'][0]['quantity'] ?? 1,
                'materials' => $bpDetails['materials'] ?? [],
            ];
        }

        // 2. Fetch polymer market prices across all 4 hubs
        foreach (self::HYBRID_POLYMERS as $typeId => $name) {
            $marketPrices[$typeId] = [];
            foreach (self::HUBS as $hubKey => $hubInfo) {
                $marketPrices[$typeId][$hubKey] = $this->fetchHubMarketData($typeId, $hubInfo['regionId'], $hubInfo['stationId']);
            }
        }

        // 3. Fetch input material Jita prices
        $jitaHub = self::HUBS['jita'];
        foreach (self::INPUT_TYPE_IDS as $typeId => $name) {
            $marketPrices[$typeId] = [
                'jita' => $this->fetchHubMarketData($typeId, $jitaHub['regionId'], $jitaHub['stationId'])
            ];
        }

        // 4. Fetch compressed fullerite gas Jita prices
        foreach (self::COMPRESSED_GAS_MAP as $rawId => $compressedId) {
            $marketPrices[$compressedId] = [
                'jita' => $this->fetchHubMarketData($compressedId, $jitaHub['regionId'], $jitaHub['stationId'])
            ];
        }

        // 5. Fetch global prices for Adjusted Prices (Estimated Item Value) for inputs AND polymers
        $adjustedPrices = $this->fetchAdjustedPrices();

        // 6. Fetch System Cost Indices for reactions
        $systemCostIndices = $this->fetchReactionSystemCostIndices();

        return [
            'reactions' => $reactions,
            'marketPrices' => $marketPrices,
            'compressedGasMap' => self::COMPRESSED_GAS_MAP,
            'adjustedPrices' => $adjustedPrices,
            'systemCostIndices' => $systemCostIndices,
            'hubs' => self::HUBS,
        ];
    }

    /**
     * Fetches reaction activity system cost indices from ESI.
     */
    private function fetchReactionSystemCostIndices(): array
    {
        try {
            $systems = $this->esiClient->request('GET', 'industry/systems/');
            if (!is_array($systems)) {
                return [];
            }

            $indices = [];
            foreach ($systems as $system) {
                $systemId = (int)($system['solar_system_id'] ?? 0);
                if (!$systemId) {
                    continue;
                }

                $costIndices = $system['cost_indices'] ?? [];
                foreach ($costIndices as $ci) {
                    $activity = $ci['activity'] ?? '';
                    if ($activity === 'reaction') {
                        $indices[$systemId] = (float)($ci['cost_index'] ?? 0.0);
                    }
                }
            }
            return $indices;
        } catch (\Exception $e) {
            error_log('[ReactionPriceService] Failed to fetch system cost indices: ' . $e->getMessage());
            return [];
        }
    }

    /**
     * Fetches global markets/prices/ to retrieve adjusted prices (EIV) for both polymers and materials.
     */
    private function fetchAdjustedPrices(): array
    {
        try {
            $data = $this->esiClient->request('GET', 'markets/prices/');
            if (!is_array($data)) {
                return [];
            }

            $adjustedPrices = [];
            // Map types we are interested in
            $validTypes = self::HYBRID_POLYMERS + self::INPUT_TYPE_IDS;
            foreach (self::COMPRESSED_GAS_MAP as $rawId => $compId) {
                $validTypes[$compId] = 'Compressed Gas';
            }

            foreach ($data as $row) {
                $typeId = (int)($row['type_id'] ?? 0);
                if (isset($validTypes[$typeId])) {
                    $adjustedPrices[$typeId] = (float)($row['adjusted_price'] ?? 0.0);
                }
            }
            return $adjustedPrices;
        } catch (\Exception $e) {
            error_log('[ReactionPriceService] Failed to fetch adjusted prices: ' . $e->getMessage());
            return [];
        }
    }

    /**
     * Fetches and aggregates market data for a specific item at a specific station.
     */
    private function fetchHubMarketData(int $typeId, int $regionId, int $stationId): array
    {
        try {
            // Fetch all orders for this type in the region
            $orders = $this->esiClient->request(
                'GET',
                sprintf('markets/%d/orders/', $regionId),
                [
                    'query' => [
                        'type_id' => $typeId,
                        'order_type' => 'all', // Fetch both buy and sell orders
                    ]
                ]
            );

            if (!is_array($orders)) {
                return $this->emptyHubData();
            }

            // Filter for the specific station/hub
            $stationOrders = array_filter($orders, function ($order) use ($stationId) {
                return (int)($order['location_id'] ?? 0) === $stationId;
            });

            $maxBuyPrice = null;
            $totalBuyVolume = 0;
            $minSellPrice = null;
            $totalSellVolume = 0;

            foreach ($stationOrders as $order) {
                $price = (float)($order['price'] ?? 0.0);
                $volume = (int)($order['volume_remain'] ?? 0);
                $isBuy = (bool)($order['is_buy_order'] ?? false);

                if ($isBuy) {
                    $totalBuyVolume += $volume;
                    if ($maxBuyPrice === null || $price > $maxBuyPrice) {
                        $maxBuyPrice = $price;
                    }
                } else {
                    $totalSellVolume += $volume;
                    if ($minSellPrice === null || $price < $minSellPrice) {
                        $minSellPrice = $price;
                    }
                }
            }

            return [
                'maxBuyPrice' => $maxBuyPrice,
                'totalBuyVolume' => $totalBuyVolume,
                'minSellPrice' => $minSellPrice,
                'totalSellVolume' => $totalSellVolume,
            ];

        } catch (\Exception $e) {
            error_log(sprintf('[ReactionPriceService] Failed to fetch market data for type %d in region %d: %s', $typeId, $regionId, $e->getMessage()));
            return $this->emptyHubData();
        }
    }

    private function emptyHubData(): array
    {
        return [
            'maxBuyPrice' => null,
            'totalBuyVolume' => 0,
            'minSellPrice' => null,
            'totalSellVolume' => 0,
        ];
    }
}
