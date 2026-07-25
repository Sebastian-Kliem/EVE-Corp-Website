<?php

namespace App\Controller\Personal;

use App\Entity\User;
use App\Entity\EveCharacter;
use App\Entity\EveCharacterMarketOrder;
use App\Service\LocationService;
use App\Service\SdeService;
use App\Service\Esi\EsiClient;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/personal/market')]
#[IsGranted('ROLE_MEMBER')]
class MarketController extends AbstractController
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly LocationService $locationService,
        private readonly SdeService $sdeService
    ) {}

    #[Route('', name: 'app_dashboard_market_overview', methods: ['GET'])]
    public function index(): Response
    {
        $currentUser = $this->getUser();
        if (!$currentUser instanceof User) {
            return $this->redirectToRoute('app_login');
        }

        return $this->render('profile/profile_market/market_overview.html.twig');
    }

    #[Route('/data', name: 'app_dashboard_market_data', methods: ['GET'])]
    public function getMarketData(EsiClient $esiClient): JsonResponse
    {
        $currentUser = $this->getUser();
        if (!$currentUser instanceof User) {
            return new JsonResponse(['error' => 'Unauthorized'], Response::HTTP_UNAUTHORIZED);
        }

        // Fetch all characters associated with this user
        $characters = $this->entityManager->getRepository(EveCharacter::class)->findBy(['user' => $currentUser]);
        if (empty($characters)) {
            return new JsonResponse([]);
        }

        // Gather all character orders and compile unique groups of type_id + location_id
        $characterOrders = [];
        $uniqueGroups = [];

        foreach ($characters as $character) {
            $ownOrders = $this->entityManager->getRepository(EveCharacterMarketOrder::class)->findBy([
                'character' => $character
            ]);
            if (!empty($ownOrders)) {
                $characterOrders[$character->getId()] = $ownOrders;
                foreach ($ownOrders as $order) {
                    $key = $order->getTypeId() . '_' . $order->getLocationId();
                    if (!isset($uniqueGroups[$key])) {
                        $uniqueGroups[$key] = [
                            'type_id' => $order->getTypeId(),
                            'location_id' => $order->getLocationId(),
                            'helper_char' => $character
                        ];
                    }
                }
            }
        }

        // Query competitor orders from ESI for each unique group
        $outbidStatusMap = [];

        foreach ($uniqueGroups as $key => $group) {
            $typeId = $group['type_id'];
            $locationId = $group['location_id'];
            $helperChar = $group['helper_char'];

            $regionId = $this->locationService->getRegionIdForLocation((int)$locationId, $helperChar);
            $competingOrders = [];

            if ($locationId >= 60000000 && $locationId < 64000000) {
                if ($regionId) {
                    try {
                        $response = $esiClient->requestAllPages(
                            sprintf('markets/%d/orders/', $regionId),
                            [
                                'query' => [
                                    'type_id' => $typeId,
                                    'order_type' => 'all'
                                ]
                            ]
                        );
                        if (!empty($response['data'])) {
                            foreach ($response['data'] as $oData) {
                                if ((string)$oData['location_id'] === (string)$locationId) {
                                    $competingOrders[] = $oData;
                                }
                            }
                        }
                    } catch (\Exception $e) {}
                }
            } elseif ($locationId >= 1000000000000) {
                $authChar = null;
                foreach ($characters as $char) {
                    if (!empty($char->getRefreshToken())) {
                        $authChar = $char;
                        break;
                    }
                }
                if ($authChar) {
                    try {
                        $response = $esiClient->requestAllPages(
                            sprintf('markets/structures/%d/', $locationId),
                            [],
                            $authChar
                        );
                        if (!empty($response['data'])) {
                            foreach ($response['data'] as $oData) {
                                if ((int)$oData['type_id'] === $typeId) {
                                    $competingOrders[] = $oData;
                                }
                            }
                        }
                    } catch (\Exception $e) {}
                }
            }

            // Exclude our own order IDs to isolate competitor prices
            $ownOrderIds = [];
            foreach ($characters as $char) {
                if (isset($characterOrders[$char->getId()])) {
                    foreach ($characterOrders[$char->getId()] as $oo) {
                        $ownOrderIds[(string)$oo->getOrderId()] = true;
                    }
                }
            }

            $competitorSells = [];
            $competitorBuys = [];

            foreach ($competingOrders as $co) {
                $orderId = (string)$co['order_id'];
                if (isset($ownOrderIds[$orderId])) {
                    continue;
                }
                if ($co['is_buy_order'] ?? false) {
                    $competitorBuys[] = (float)$co['price'];
                } else {
                    $competitorSells[] = (float)$co['price'];
                }
            }

            $outbidStatusMap[$key] = [
                'lowest_competitor_sell' => !empty($competitorSells) ? min($competitorSells) : null,
                'highest_competitor_buy' => !empty($competitorBuys) ? max($competitorBuys) : null
            ];
        }

        $data = [];

        foreach ($characters as $character) {
            if (!isset($characterOrders[$character->getId()])) {
                continue;
            }

            $ordersList = [];
            foreach ($characterOrders[$character->getId()] as $order) {
                $locationInfo = $this->locationService->resolveLocation((int)$order->getLocationId());
                $key = $order->getTypeId() . '_' . $order->getLocationId();

                $isOutbid = false;
                if (isset($outbidStatusMap[$key])) {
                    $lowestSell = $outbidStatusMap[$key]['lowest_competitor_sell'];
                    $highestBuy = $outbidStatusMap[$key]['highest_competitor_buy'];

                    if ($order->isBuy()) {
                        if ($highestBuy !== null && (float)$order->getPrice() < $highestBuy) {
                            $isOutbid = true;
                        }
                    } else {
                        if ($lowestSell !== null && (float)$order->getPrice() > $lowestSell) {
                            $isOutbid = true;
                        }
                    }
                }

                $ordersList[] = [
                    'order_id' => $order->getOrderId(),
                    'type_id' => $order->getTypeId(),
                    'item_name' => $this->sdeService->getItemName($order->getTypeId()),
                    'price' => (float)$order->getPrice(),
                    'volume_total' => $order->getVolumeTotal(),
                    'volume_remain' => $order->getVolumeRemain(),
                    'is_buy' => $order->isBuy(),
                    'location_id' => $order->getLocationId(),
                    'location_name' => $locationInfo['name'],
                    'system_name' => $locationInfo['systemName'],
                    'range' => $order->getRange(),
                    'min_volume' => $order->getMinVolume() ?? 1,
                    'is_outbid' => $isOutbid
                ];
            }

            // Sort orders alphabetically by item name
            usort($ordersList, fn($a, $b) => strcasecmp($a['item_name'], $b['item_name']));

            $data[] = [
                'character_id' => $character->getId(),
                'character_name' => $character->getName(),
                'orders' => $ordersList
            ];
        }

        // Sort characters alphabetically by name
        usort($data, fn($a, $b) => strcasecmp($a['character_name'], $b['character_name']));

        return new JsonResponse($data);
    }

    #[Route('/details', name: 'app_dashboard_market_details', methods: ['GET'])]
    public function getMarketDetails(Request $request, EsiClient $esiClient): JsonResponse
    {
        $currentUser = $this->getUser();
        if (!$currentUser instanceof User) {
            return new JsonResponse(['error' => 'Unauthorized'], Response::HTTP_UNAUTHORIZED);
        }

        $typeId = $request->query->getInt('type_id');
        $locationId = $request->query->get('location_id');

        if (!$typeId || !$locationId) {
            return new JsonResponse(['error' => 'Missing parameters'], Response::HTTP_BAD_REQUEST);
        }

        // Fetch all characters associated with this user
        $characters = $this->entityManager->getRepository(EveCharacter::class)->findBy(['user' => $currentUser]);
        if (empty($characters)) {
            return new JsonResponse([]);
        }

        // Fetch own orders in this group to know who they belong to
        $ownOrders = $this->entityManager->getRepository(EveCharacterMarketOrder::class)->findBy([
            'typeId' => $typeId,
            'locationId' => $locationId,
            'character' => $characters
        ]);

        $ownOrderIds = [];
        foreach ($ownOrders as $order) {
            $ownOrderIds[$order->getOrderId()] = [
                'character_name' => $order->getCharacter()->getName(),
            ];
        }

        // Resolve region ID for station
        $helperChar = !empty($ownOrders) ? $ownOrders[0]->getCharacter() : $characters[0];
        $regionId = $this->locationService->getRegionIdForLocation((int)$locationId, $helperChar);

        $competingOrders = [];

        if ($locationId >= 60000000 && $locationId < 64000000) {
            // NPC Station - fetch regional market
            if ($regionId) {
                try {
                    $response = $esiClient->requestAllPages(
                        sprintf('markets/%d/orders/', $regionId),
                        [
                            'query' => [
                                'type_id' => $typeId,
                                'order_type' => 'all'
                            ]
                        ]
                    );
                    if (!empty($response['data'])) {
                        foreach ($response['data'] as $oData) {
                            if ((string)$oData['location_id'] === (string)$locationId) {
                                $competingOrders[] = $oData;
                            }
                        }
                    }
                } catch (\Exception $e) {
                    // Ignore and keep going
                }
            }
        } elseif ($locationId >= 1000000000000) {
            // Player Structure - fetch structure market using auth character
            $authChar = null;
            foreach ($characters as $char) {
                if (!empty($char->getRefreshToken())) {
                    $authChar = $char;
                    break;
                }
            }

            if ($authChar) {
                try {
                    $response = $esiClient->requestAllPages(
                        sprintf('markets/structures/%d/', $locationId),
                        [],
                        $authChar
                    );
                    if (!empty($response['data'])) {
                        foreach ($response['data'] as $oData) {
                            if ((int)$oData['type_id'] === $typeId) {
                                $competingOrders[] = $oData;
                            }
                        }
                    }
                } catch (\Exception $e) {
                    // Ignore and keep going
                }
            }
        }

        // Combine own + competing and de-duplicate by order_id
        $allOrders = [];
        foreach ($competingOrders as $co) {
            $orderId = (string)$co['order_id'];
            $isOwn = isset($ownOrderIds[$orderId]);
            $charName = $isOwn ? $ownOrderIds[$orderId]['character_name'] : null;

            $allOrders[$orderId] = [
                'order_id' => $orderId,
                'price' => (float)$co['price'],
                'volume_total' => (int)$co['volume_total'],
                'volume_remain' => (int)$co['volume_remain'],
                'is_buy' => (bool)($co['is_buy_order'] ?? false),
                'range' => (string)($co['range'] ?? 'region'),
                'min_volume' => isset($co['min_volume']) ? (int)$co['min_volume'] : 1,
                'is_own' => $isOwn,
                'character_name' => $charName,
            ];
        }

        // Ensure all our own orders are in the list
        foreach ($ownOrders as $oo) {
            $orderId = $oo->getOrderId();
            if (!isset($allOrders[$orderId])) {
                $allOrders[$orderId] = [
                    'order_id' => $orderId,
                    'price' => (float)$oo->getPrice(),
                    'volume_total' => $oo->getVolumeTotal(),
                    'volume_remain' => $oo->getVolumeRemain(),
                    'is_buy' => $oo->isBuy(),
                    'range' => $oo->getRange(),
                    'min_volume' => $oo->getMinVolume() ?? 1,
                    'is_own' => true,
                    'character_name' => $oo->getCharacter()->getName(),
                ];
            }
        }

        // Split into buy and sell orders
        $buyOrders = [];
        $sellOrders = [];

        foreach ($allOrders as $o) {
            if ($o['is_buy']) {
                $buyOrders[] = $o;
            } else {
                $sellOrders[] = $o;
            }
        }

        // Sort: Buy orders highest price first (descending); Sell orders lowest price first (ascending)
        usort($buyOrders, fn($a, $b) => $b['price'] <=> $a['price']);
        usort($sellOrders, fn($a, $b) => $a['price'] <=> $b['price']);

        return new JsonResponse([
            'buy_orders' => $buyOrders,
            'sell_orders' => $sellOrders
        ]);
    }
}
