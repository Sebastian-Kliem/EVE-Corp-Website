<?php

namespace App\Controller\Trading;

use App\Entity\EveCharacter;
use App\Entity\User;
use App\Repository\MarketPriceRepository;
use App\Service\Esi\EsiClient;
use App\Service\Market\MarketHub;
use App\Service\SdeService;
use App\Service\Trading\StationTradingQuery;
use App\Service\Trading\TradingFeeCalculator;
use App\Service\Trading\TradingItemDetails;
use App\Service\Trading\TradingOrderOverview;
use App\Service\Trading\TradingPerformanceCalculator;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/trading')]
#[IsGranted('ROLE_MEMBER')]
class TradingController extends AbstractController
{
    private const SEARCH_TYPE_LIMIT = 500;
    private const MAX_FILTER_ENTRIES = 1000;

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly TradingFeeCalculator $feeCalculator,
        private readonly SdeService $sdeService,
        private readonly ClockInterface $clock
    ) {}

    // Old single page URL from bookmarks and browser history
    #[Route('', name: 'app_trading', methods: ['GET'])]
    public function index(): Response
    {
        return $this->redirectToRoute('app_trading_station_trading', [], Response::HTTP_MOVED_PERMANENTLY);
    }

    #[Route('/station-trading', name: 'app_trading_station_trading', methods: ['GET'])]
    public function stationTrading(): Response
    {
        return $this->render('trading/station_trading.html.twig');
    }

    #[Route('/orders', name: 'app_trading_orders', methods: ['GET'])]
    public function orders(): Response
    {
        return $this->render('trading/orders.html.twig');
    }

    #[Route('/api/context', name: 'app_trading_context', methods: ['GET'])]
    public function context(MarketPriceRepository $marketPriceRepository, Connection $connection): JsonResponse
    {
        $user = $this->_user();
        $tradingCharacter = $this->_findTradingCharacter($user);

        $characters = [];
        foreach ($this->_ownCharacters($user) as $character) {
            $characters[] = ['id' => (string)$character->getId(), 'name' => $character->getName()];
        }
        usort($characters, fn(array $first, array $second): int => strcasecmp($first['name'], $second['name']));

        $hubs = [];
        foreach (MarketHub::HUBS as $stationId => $hub) {
            $hubs[] = [
                'stationId' => $stationId,
                'name' => $hub['name'],
                'fetchedAt' => $marketPriceRepository->findLastFetchedAt($hub['regionId'])?->format(\DateTimeInterface::ATOM),
            ];
        }
        $statsComputedAt = $connection->fetchOne('SELECT MAX(computed_at) FROM market_type_stats');

        return new JsonResponse([
            'characters' => $characters,
            'tradingCharacterId' => $tradingCharacter === null ? null : (string)$tradingCharacter->getId(),
            'fees' => $this->feeCalculator->calculate($tradingCharacter),
            'hubs' => $hubs,
            'statsComputedAt' => $statsComputedAt ? (new \DateTimeImmutable((string)$statsComputedAt))->format(\DateTimeInterface::ATOM) : null,
        ]);
    }

    #[Route('/api/settings', name: 'app_trading_settings', methods: ['POST'])]
    public function saveSettings(Request $request): JsonResponse
    {
        $user = $this->_user();
        $body = json_decode($request->getContent(), true) ?? [];
        $characterId = isset($body['tradingCharacterId']) && $body['tradingCharacterId'] !== '' ? (int)$body['tradingCharacterId'] : null;

        if ($characterId !== null && $this->_findOwnCharacter($user, $characterId) === null) {
            return new JsonResponse(['error' => 'Dieser Charakter gehört nicht zu deinem Konto.'], Response::HTTP_BAD_REQUEST);
        }

        $user->setTradingCharacterId($characterId);
        $this->entityManager->flush();

        return new JsonResponse(['success' => true]);
    }

    #[Route('/api/station', name: 'app_trading_station', methods: ['GET'])]
    public function station(Request $request, StationTradingQuery $query): JsonResponse
    {
        $user = $this->_user();
        $stationId = $request->query->getInt('stationId', MarketHub::JITA);
        if (!isset(MarketHub::HUBS[$stationId])) {
            return new JsonResponse(['error' => 'Unbekanntes Handelsdrehkreuz.'], Response::HTTP_BAD_REQUEST);
        }
        $fees = $this->_resolveFees($request, $this->_findTradingCharacter($user));
        $typeFilter = $this->_resolveStationTypeFilter((string)$request->query->get('search', ''), $user->getStationTradingFilter());

        $result = $query->find([
            'stationId' => $stationId,
            'regionId' => MarketHub::HUBS[$stationId]['regionId'],
            'salesTax' => $fees['salesTax'],
            'brokerFee' => $fees['brokerFee'],
            'capital' => max(0.0, (float)$request->query->get('capital', 0)),
            'minVolume' => max(0.0, (float)$request->query->get('minVolume', 0)),
            'minMargin' => max(0.0, (float)$request->query->get('minMargin', 0)) / 100,
            'maxBuyPrice' => max(0.0, (float)$request->query->get('maxBuyPrice', 0)),
            'minTradedDays' => max(0, $request->query->getInt('minTradedDays', 0)),
            'typeIds' => $typeFilter['typeIds'],
            'excludedTypeIds' => $typeFilter['excludedTypeIds'],
            'sort' => (string)$request->query->get('sort', 'potential'),
            'direction' => (string)$request->query->get('direction', 'desc'),
            'page' => max(1, $request->query->getInt('page', 1)),
        ]);

        $rows = [];
        foreach ($result['rows'] as $row) {
            $rows[] = $this->_formatStationRow($row);
        }

        return new JsonResponse([
            'rows' => $rows,
            'total' => $result['total'],
            'pageSize' => StationTradingQuery::PAGE_SIZE,
            'fees' => $fees,
        ]);
    }

    #[Route('/api/station-filter', name: 'app_trading_station_filter', methods: ['GET'])]
    public function stationFilter(): JsonResponse
    {
        return new JsonResponse($this->_formatStationFilter($this->_user()));
    }

    #[Route('/api/station-filter', name: 'app_trading_station_filter_save', methods: ['POST'])]
    public function saveStationFilter(Request $request): JsonResponse
    {
        $user = $this->_user();
        $body = json_decode($request->getContent(), true) ?? [];
        $mode = (string)($body['mode'] ?? User::STATION_FILTER_ALL);
        $marketGroupIds = is_array($body['marketGroupIds'] ?? null) ? $body['marketGroupIds'] : [];
        $hiddenTypeIds = is_array($body['hiddenTypeIds'] ?? null) ? $body['hiddenTypeIds'] : [];

        if (!in_array($mode, [User::STATION_FILTER_ALL, User::STATION_FILTER_ONLY, User::STATION_FILTER_EXCEPT], true)) {
            return new JsonResponse(['error' => 'Unbekannter Filtermodus.'], Response::HTTP_BAD_REQUEST);
        }
        if (count($marketGroupIds) > self::MAX_FILTER_ENTRIES || count($hiddenTypeIds) > self::MAX_FILTER_ENTRIES) {
            return new JsonResponse(['error' => 'Zu viele Einträge im Filter.'], Response::HTTP_BAD_REQUEST);
        }

        $user->setStationTradingFilter($mode, $marketGroupIds, $hiddenTypeIds);
        $this->entityManager->flush();

        return new JsonResponse($this->_formatStationFilter($user));
    }

    #[Route('/api/market-groups', name: 'app_trading_market_groups', methods: ['GET'])]
    public function marketGroups(): JsonResponse
    {
        return new JsonResponse($this->sdeService->getMarketGroups());
    }

    #[Route('/api/item', name: 'app_trading_item', methods: ['GET'])]
    public function item(Request $request, TradingItemDetails $itemDetails): JsonResponse
    {
        $user = $this->_user();
        $stationId = $request->query->getInt('stationId', MarketHub::JITA);
        $typeId = $request->query->getInt('typeId');
        if (!isset(MarketHub::HUBS[$stationId]) || $typeId <= 0) {
            return new JsonResponse(['error' => 'Ungültige Anfrage.'], Response::HTTP_BAD_REQUEST);
        }
        $regionId = MarketHub::HUBS[$stationId]['regionId'];

        $book = null;
        $bookError = null;
        try {
            $book = $itemDetails->loadStationBook($regionId, $stationId, $typeId, $this->_ownCharacters($user));
        } catch (\Exception $e) {
            $bookError = 'Orderbuch konnte nicht von ESI geladen werden.';
        }

        return new JsonResponse([
            'typeId' => $typeId,
            'itemName' => $this->sdeService->getItemName($typeId),
            'history' => $itemDetails->loadHistory($regionId, $typeId, $this->clock->now()),
            'book' => $book,
            'bookError' => $bookError,
        ]);
    }

    #[Route('/api/my-orders', name: 'app_trading_my_orders', methods: ['GET'])]
    public function myOrders(Request $request, TradingOrderOverview $orderOverview): JsonResponse
    {
        $tradingCharacter = $this->_findTradingCharacter($this->_user());
        if ($tradingCharacter === null) {
            return new JsonResponse(['orders' => [], 'characterName' => null]);
        }

        return new JsonResponse([
            'orders' => $orderOverview->build($tradingCharacter, $this->_resolveFees($request, $tradingCharacter)),
            'characterName' => $tradingCharacter->getName(),
        ]);
    }

    #[Route('/api/performance', name: 'app_trading_performance', methods: ['GET'])]
    public function performance(Request $request, TradingPerformanceCalculator $calculator): JsonResponse
    {
        $user = $this->_user();
        $filter = $this->_resolvePerformanceFilter($request, $user);
        if (is_string($filter)) {
            return new JsonResponse(['error' => $filter], Response::HTTP_BAD_REQUEST);
        }

        return new JsonResponse($calculator->calculate($this->_ownCharacters($user), $filter['from'], $filter['to'], $filter['characterId']));
    }

    #[Route('/api/performance/item', name: 'app_trading_performance_item', methods: ['GET'])]
    public function performanceItem(Request $request, TradingPerformanceCalculator $calculator): JsonResponse
    {
        $user = $this->_user();
        $filter = $this->_resolvePerformanceFilter($request, $user);
        $typeId = (int)$request->query->get('typeId', '');
        if (is_string($filter) || $typeId <= 0) {
            return new JsonResponse(['error' => is_string($filter) ? $filter : 'Ungültiges Item.'], Response::HTTP_BAD_REQUEST);
        }

        $result = $calculator->calculate($this->_ownCharacters($user), $filter['from'], $filter['to'], $filter['characterId'], $typeId);
        $characterNames = [];
        foreach ($this->_ownCharacters($user) as $character) {
            $characterNames[(string)$character->getId()] = $character->getName();
        }

        return new JsonResponse([
            'detail' => $result['detail'],
            'item' => $this->_findByTypeId($result['items'], $typeId),
            'position' => $this->_findByTypeId($result['positions'], $typeId),
            'characterNames' => $characterNames,
        ]);
    }

    /**
     * @return array{from: \DateTimeImmutable, to: \DateTimeImmutable, characterId: ?int}|string Error message on invalid input
     */
    private function _resolvePerformanceFilter(Request $request, User $user): array|string
    {
        $utc = new \DateTimeZone('UTC');
        try {
            $from = (new \DateTimeImmutable((string)$request->query->get('from', 'first day of this month'), $utc))->setTime(0, 0);
            // The end date is inclusive in the UI
            $to = (new \DateTimeImmutable((string)$request->query->get('to', 'today'), $utc))->setTime(0, 0)->modify('+1 day');
        } catch (\Exception $e) {
            return 'Ungültiger Zeitraum.';
        }

        // getInt() rejects the empty string of "all characters"
        $characterId = (int)$request->query->get('characterId', '') ?: null;
        if ($characterId !== null && $this->_findOwnCharacter($user, $characterId) === null) {
            return 'Dieser Charakter gehört nicht zu deinem Konto.';
        }

        return ['from' => $from, 'to' => $to, 'characterId' => $characterId];
    }

    /**
     * @param list<array<string, mixed>> $rows
     * @return ?array<string, mixed>
     */
    private function _findByTypeId(array $rows, int $typeId): ?array
    {
        foreach ($rows as $row) {
            if ($row['typeId'] === $typeId) {
                return $row;
            }
        }

        return null;
    }

    #[Route('/api/open-market', name: 'app_trading_open_market', methods: ['POST'])]
    public function openMarket(Request $request, EsiClient $esiClient): JsonResponse
    {
        $tradingCharacter = $this->_findTradingCharacter($this->_user());
        $body = json_decode($request->getContent(), true) ?? [];
        $typeId = (int)($body['typeId'] ?? 0);

        if ($tradingCharacter === null || !$tradingCharacter->isTokenValid()) {
            return new JsonResponse(['error' => 'Kein Handels-Charakter mit gültigem Token gewählt.'], Response::HTTP_BAD_REQUEST);
        }
        if ($typeId <= 0) {
            return new JsonResponse(['error' => 'Ungültiges Item.'], Response::HTTP_BAD_REQUEST);
        }

        try {
            $esiClient->request('POST', 'ui/openwindow/marketdetails/', ['query' => ['type_id' => $typeId]], $tradingCharacter);
        } catch (\Exception $e) {
            return new JsonResponse(['error' => 'Marktfenster konnte nicht geöffnet werden: ' . $e->getMessage()], Response::HTTP_BAD_GATEWAY);
        }

        return new JsonResponse(['success' => true]);
    }

    /**
     * Fee overrides from the page (in percent) win over the skill based values, standings are unknown to the tool.
     *
     * @return array{salesTax: float, brokerFee: float}
     */
    private function _resolveFees(Request $request, ?EveCharacter $tradingCharacter): array
    {
        $calculated = $this->feeCalculator->calculate($tradingCharacter);
        $salesTax = $request->query->get('salesTax');
        $brokerFee = $request->query->get('brokerFee');

        return [
            'salesTax' => $salesTax !== null && $salesTax !== '' ? max(0.0, (float)$salesTax) / 100 : $calculated['salesTax'],
            'brokerFee' => $brokerFee !== null && $brokerFee !== '' ? max(0.0, (float)$brokerFee) / 100 : $calculated['brokerFee'],
        ];
    }

    /**
     * Combines the search with the saved market group filter and the hidden items.
     *
     * @param array{mode: string, marketGroupIds: list<int>, hiddenTypeIds: list<int>} $stationFilter
     * @return array{typeIds: ?int[], excludedTypeIds: int[]}
     */
    private function _resolveStationTypeFilter(string $search, array $stationFilter): array
    {
        $typeIds = $this->_resolveSearchTypeIds($search);
        $excludedTypeIds = $stationFilter['hiddenTypeIds'];
        if ($stationFilter['marketGroupIds'] === []) {
            return ['typeIds' => $typeIds, 'excludedTypeIds' => $excludedTypeIds];
        }

        $groupTypeIds = $this->sdeService->getTypeIdsInMarketGroups($stationFilter['marketGroupIds']);
        if ($stationFilter['mode'] === User::STATION_FILTER_ONLY) {
            $typeIds = $typeIds === null ? $groupTypeIds : array_values(array_intersect($typeIds, $groupTypeIds));
        } elseif ($stationFilter['mode'] === User::STATION_FILTER_EXCEPT) {
            $excludedTypeIds = array_values(array_unique(array_merge($excludedTypeIds, $groupTypeIds)));
        }

        return ['typeIds' => $typeIds, 'excludedTypeIds' => $excludedTypeIds];
    }

    /**
     * @return array{mode: string, marketGroupIds: list<int>, hiddenItems: list<array{typeId: int, name: string}>}
     */
    private function _formatStationFilter(User $user): array
    {
        $stationFilter = $user->getStationTradingFilter();
        $hiddenItems = [];
        foreach ($stationFilter['hiddenTypeIds'] as $typeId) {
            $hiddenItems[] = ['typeId' => $typeId, 'name' => $this->sdeService->getItemName($typeId)];
        }
        usort($hiddenItems, fn(array $first, array $second): int => strcasecmp($first['name'], $second['name']));

        return [
            'mode' => $stationFilter['mode'],
            'marketGroupIds' => $stationFilter['marketGroupIds'],
            'hiddenItems' => $hiddenItems,
        ];
    }

    /**
     * @return ?int[] Null without a search, otherwise the matching type IDs (possibly none)
     */
    private function _resolveSearchTypeIds(string $search): ?array
    {
        if (trim($search) === '') {
            return null;
        }

        $typeIds = [];
        foreach ($this->sdeService->searchItems($search, self::SEARCH_TYPE_LIMIT) as $item) {
            $typeIds[] = (int)$item['id'];
        }

        return $typeIds;
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function _formatStationRow(array $row): array
    {
        $typeId = (int)$row['type_id'];

        return [
            'typeId' => $typeId,
            'itemName' => $this->sdeService->getItemName($typeId),
            'buyBest' => (float)$row['buy_best'],
            'sellBest' => (float)$row['sell_best'],
            'profitPerUnit' => (float)$row['profit_per_unit'],
            'margin' => (float)$row['margin'],
            'avgVolume7d' => (float)$row['avg_volume7d'],
            'avgVolume30d' => (float)$row['avg_volume30d'],
            'avgPrice30d' => $row['avg_price30d'] === null ? null : (float)$row['avg_price30d'],
            'tradedDays30d' => (int)$row['traded_days30d'],
            'priceVolatility30d' => $row['price_volatility30d'] === null ? null : (float)$row['price_volatility30d'],
            'buyCompetitors' => (int)$row['buy_depth_order_count'],
            'sellCompetitors' => (int)$row['sell_depth_order_count'],
            'competitors' => (int)$row['competitors'],
            'buyUpdated24h' => (int)$row['buy_updated24h'],
            'sellUpdated24h' => (int)$row['sell_updated24h'],
            'buyUpdated1h' => (int)$row['buy_updated1h'],
            'sellUpdated1h' => (int)$row['sell_updated1h'],
            'buyOrders' => (int)$row['buy_volume'],
            'sellOrders' => (int)$row['sell_volume'],
            'buySideShare' => $row['buy_side_share'] === null ? null : (float)$row['buy_side_share'],
            'fillable' => (bool)$row['fillable'],
            'avgLowest7d' => $row['avg_lowest7d'] === null ? null : (float)$row['avg_lowest7d'],
            'avgHighest7d' => $row['avg_highest7d'] === null ? null : (float)$row['avg_highest7d'],
            'expectedUnits' => (float)$row['expected_units'],
            'potential' => (float)$row['potential'],
        ];
    }

    private function _findTradingCharacter(User $user): ?EveCharacter
    {
        $characterId = $user->getTradingCharacterId();

        return $characterId === null ? null : $this->_findOwnCharacter($user, $characterId);
    }

    private function _findOwnCharacter(User $user, int $characterId): ?EveCharacter
    {
        $character = $this->entityManager->getRepository(EveCharacter::class)->find($characterId);

        return $character !== null && $character->getUser() === $user ? $character : null;
    }

    /**
     * @return EveCharacter[]
     */
    private function _ownCharacters(User $user): array
    {
        return $this->entityManager->getRepository(EveCharacter::class)->findBy(['user' => $user]);
    }

    private function _user(): User
    {
        $user = $this->getUser();
        if (!$user instanceof User) {
            throw $this->createAccessDeniedException();
        }

        return $user;
    }
}
