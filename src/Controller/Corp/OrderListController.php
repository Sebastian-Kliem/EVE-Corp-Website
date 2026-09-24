<?php

namespace App\Controller\Corp;

use App\Entity\DefenseDoctrineFit;
use App\Entity\Orders\CorpOrder;
use App\Repository\CorpOrderRepository;
use App\Repository\DefenseDoctrineFitRepository;
use App\Service\OrderService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/corp')]
#[IsGranted('ROLE_MEMBER')]
final class OrderListController extends AbstractController
{
    #[Route('/orders', name: 'app_order_list')]
    public function index(
        CorpOrderRepository $orderRepository,
        DefenseDoctrineFitRepository $fitRepository,
        OrderService $orderService
    ): Response {
        $orderService->syncContractsWithOrders();

        $activeBuyOrders = $orderRepository->findActiveOrders(CorpOrder::TYPE_BUY);
        $activeSellOrders = $orderRepository->findActiveOrders(CorpOrder::TYPE_SELL);
        $archivedBuyOrders = $orderRepository->findArchivedOrders(CorpOrder::TYPE_BUY);
        $archivedSellOrders = $orderRepository->findArchivedOrders(CorpOrder::TYPE_SELL);

        $doctrineFits = array_map(function (DefenseDoctrineFit $fit) {
            return [
                'id' => $fit->getId(),
                'title' => $fit->getTitle(),
                'shipName' => $fit->getShipName(),
                'shipTypeId' => $fit->getShipTypeId(),
                'role' => $fit->getRole() ?: 'Allgemein',
                'eft' => $fit->getEft(),
            ];
        }, $fitRepository->findAllOrdered());

        $formattedBuy = array_map(fn(CorpOrder $o) => $orderService->formatOrderForApi($o), $activeBuyOrders);
        $formattedSell = array_map(fn(CorpOrder $o) => $orderService->formatOrderForApi($o), $activeSellOrders);
        $formattedArchivedBuy = array_map(fn(CorpOrder $o) => $orderService->formatOrderForApi($o), $archivedBuyOrders);
        $formattedArchivedSell = array_map(fn(CorpOrder $o) => $orderService->formatOrderForApi($o), $archivedSellOrders);

        return $this->render('tool/order_list/orderList.html.twig', [
            'initialBuyOrders' => $formattedBuy,
            'initialSellOrders' => $formattedSell,
            'initialArchivedBuyOrders' => $formattedArchivedBuy,
            'initialArchivedSellOrders' => $formattedArchivedSell,
            'doctrineFits' => $doctrineFits,
        ]);
    }
}
