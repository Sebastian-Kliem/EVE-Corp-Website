<?php

namespace App\Service\Trading;

use App\Entity\EveCharacter;
use App\Entity\EveCharacterMarketOrder;
use App\Entity\Market\MarketPrice;
use App\Entity\Market\TradingOrderCheck;
use App\Repository\TradingOrderCheckRepository;
use App\Service\Market\MarketHub;
use App\Service\SdeService;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\ParameterType;
use Doctrine\ORM\EntityManagerInterface;

/**
 * The trading character's orders with their order book position and the price that wins the top spot again.
 */
class TradingOrderOverview
{
    public const STATUS_OUTBID = 'outbid';
    public const STATUS_BEST = 'best';
    public const STATUS_GONE = 'gone';
    public const STATUS_UNCHECKED = 'unchecked';

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly TradingOrderCheckRepository $checkRepository,
        private readonly SdeService $sdeService
    ) {}

    /**
     * @param array{salesTax: float, brokerFee: float} $fees
     * @return list<array<string, mixed>> Outbid orders first
     */
    public function build(EveCharacter $character, array $fees): array
    {
        $orders = $this->entityManager->getRepository(EveCharacterMarketOrder::class)->findBy(['character' => $character]);

        $orderIds = [];
        foreach ($orders as $order) {
            $orderIds[] = (int)$order->getOrderId();
        }
        $checks = $this->checkRepository->findByOrderIds($orderIds);
        $stationPrices = $this->_loadStationPrices($orders);

        $rows = [];
        foreach ($orders as $order) {
            $row = $this->_buildRow($order, $checks[(int)$order->getOrderId()] ?? null);
            $rows[] = $this->_addMarginAfterAdjustment($row, $stationPrices[$row['locationId'] . '|' . $row['typeId']] ?? null, $fees);
        }

        $statusOrder = [self::STATUS_OUTBID => 0, self::STATUS_BEST => 1, self::STATUS_GONE => 2, self::STATUS_UNCHECKED => 3];
        usort($rows, function (array $first, array $second) use ($statusOrder): int {
            return [$statusOrder[$first['status']], $first['itemName']] <=> [$statusOrder[$second['status']], $second['itemName']];
        });

        return $rows;
    }

    /**
     * @return array<string, mixed>
     */
    private function _buildRow(EveCharacterMarketOrder $order, ?TradingOrderCheck $check): array
    {
        $locationId = (int)$order->getLocationId();
        $isBuy = (bool)$order->isBuy();
        $price = $check?->getCurrentPrice() !== null ? (float)$check->getCurrentPrice() : (float)$order->getPrice();
        $bestCompetitorPrice = $check?->getBestCompetitorPrice() !== null ? (float)$check->getBestCompetitorPrice() : null;

        $status = $this->_resolveStatus($check);
        $suggestedPrice = null;
        if ($status === self::STATUS_OUTBID && $bestCompetitorPrice !== null) {
            $suggestedPrice = $isBuy ? MarketPriceTick::overbid($bestCompetitorPrice) : MarketPriceTick::undercut($bestCompetitorPrice);
        }

        return [
            'orderId' => (string)$order->getOrderId(),
            'typeId' => (int)$order->getTypeId(),
            'itemName' => $this->sdeService->getItemName($order->getTypeId()),
            'locationId' => (string)$locationId,
            'locationName' => MarketHub::HUBS[$locationId]['name'] ?? $this->sdeService->getLocationName($locationId),
            'isBuy' => $isBuy,
            'price' => $price,
            'volumeRemain' => $check?->getCurrentVolumeRemain() ?? (int)$order->getVolumeRemain(),
            'volumeTotal' => (int)$order->getVolumeTotal(),
            'issued' => $order->getIssued()?->format(\DateTimeInterface::ATOM),
            'status' => $status,
            'bestCompetitorPrice' => $bestCompetitorPrice,
            'competitorsAhead' => $check?->getCompetitorsAhead() ?? 0,
            'competitorVolumeAhead' => $check?->getCompetitorVolumeAhead() ?? 0,
            'suggestedPrice' => $suggestedPrice,
            'checkedAt' => $check?->getCheckedAt()?->format(\DateTimeInterface::ATOM),
        ];
    }

    /**
     * Margin against the best price of the other side once the order sits at the suggested (or its current) price.
     *
     * @param array<string, mixed> $row
     * @param ?array{buyBest: ?float, sellBest: ?float} $stationPrice
     * @param array{salesTax: float, brokerFee: float} $fees
     * @return array<string, mixed>
     */
    private function _addMarginAfterAdjustment(array $row, ?array $stationPrice, array $fees): array
    {
        $price = $row['suggestedPrice'] ?? $row['price'];
        $oppositePrice = $stationPrice === null ? null : ($row['isBuy'] ? $stationPrice['sellBest'] : $stationPrice['buyBest']);

        $row['oppositeBestPrice'] = $oppositePrice;
        $row['priceChange'] = $row['suggestedPrice'] !== null && $row['price'] > 0 ? $row['suggestedPrice'] / $row['price'] - 1 : null;
        $row['marginAfter'] = null;
        if ($oppositePrice !== null && $price > 0) {
            $buyPrice = $row['isBuy'] ? $price : $oppositePrice;
            $sellPrice = $row['isBuy'] ? $oppositePrice : $price;
            $buyCost = $buyPrice * (1 + $fees['brokerFee']);
            $row['marginAfter'] = ($sellPrice * (1 - $fees['salesTax'] - $fees['brokerFee']) - $buyCost) / $buyCost;
        }

        return $row;
    }

    /**
     * @param EveCharacterMarketOrder[] $orders
     * @return array<string, array{buyBest: ?float, sellBest: ?float}> "locationId|typeId" => best prices at the station
     */
    private function _loadStationPrices(array $orders): array
    {
        $locationIds = [];
        $typeIds = [];
        foreach ($orders as $order) {
            $locationIds[(int)$order->getLocationId()] = true;
            $typeIds[(int)$order->getTypeId()] = true;
        }
        if ($typeIds === []) {
            return [];
        }

        $rows = $this->entityManager->getConnection()->fetchAllAssociative(
            'SELECT location_id, type_id, buy_best, sell_best FROM market_price WHERE scope = ? AND location_id IN (?) AND type_id IN (?)',
            [MarketPrice::SCOPE_STATION, array_keys($locationIds), array_keys($typeIds)],
            [ParameterType::STRING, ArrayParameterType::INTEGER, ArrayParameterType::INTEGER]
        );

        $prices = [];
        foreach ($rows as $row) {
            $prices[$row['location_id'] . '|' . $row['type_id']] = [
                'buyBest' => $row['buy_best'] === null ? null : (float)$row['buy_best'],
                'sellBest' => $row['sell_best'] === null ? null : (float)$row['sell_best'],
            ];
        }

        return $prices;
    }

    private function _resolveStatus(?TradingOrderCheck $check): string
    {
        if ($check === null) {
            return self::STATUS_UNCHECKED;
        }
        if (!$check->isInBook()) {
            return self::STATUS_GONE;
        }

        return $check->getCompetitorsAhead() > 0 ? self::STATUS_OUTBID : self::STATUS_BEST;
    }
}
