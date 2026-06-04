<?php

namespace App\Controller;

use App\Entity\Orders\BuyOrder;
use App\Entity\Orders\SellOrder;
use App\Repository\BuyOrderRepository;
use App\Repository\SellOrderRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use App\Repository\UserRepository;
use App\Service\SdeService;

#[IsGranted('ROLE_MEMBER')]
final class OrderListController extends AbstractController
{
    #[Route('/order/list', name: 'app_order_list')]
    public function index(
        Request             $request,
        BuyOrderRepository  $orderRepository,
        SellOrderRepository $sellRepository,
        UserRepository      $userRepository
    ): Response
    {
        $orders = $orderRepository->findAll();
        $sell = $sellRepository->findAll();

        $editingBuyOrder = null;
        $editingSellOrder = null;
        $users = [];

        $editBuyId = $request->query->get('edit_buy');
        if ($editBuyId) {
            $buyOrder = $orderRepository->find($editBuyId);
            if ($buyOrder) {
                if ($buyOrder->getBuyer() === $this->getUser() || $this->isGranted('ROLE_CEO')) {
                    $editingBuyOrder = $buyOrder;
                    $users = array_map(fn($user) => $user->getUsername(), $userRepository->findAll());
                } else {
                    $this->addFlash('error', 'Du darfst diesen Kaufauftrag nicht bearbeiten.');
                }
            }
        }

        $editSellId = $request->query->get('edit_sell');
        if ($editSellId) {
            $sellOrder = $sellRepository->find($editSellId);
            if ($sellOrder) {
                if ($sellOrder->getSeller() === $this->getUser() || $this->isGranted('ROLE_CEO')) {
                    $editingSellOrder = $sellOrder;
                    $users = array_map(fn($user) => $user->getUsername(), $userRepository->findAll());
                } else {
                    $this->addFlash('error', 'Du darfst diesen Verkaufsauftrag nicht bearbeiten.');
                }
            }
        }

        return $this->render('order_list/orderList.html.twig', [
            'buy_orders' => $orders,
            'sell_orders' => $sell,
            'editing_buy_order' => $editingBuyOrder,
            'editing_sell_order' => $editingSellOrder,
            'users' => $users,
        ]);
    }

    #[IsGranted('ROLE_MEMBER')]
    #[Route('/order/create_order', name: 'app_order_create_order', methods: ['POST', 'GET'])]
    public function create_order(
        EntityManagerInterface $entityManager,
        Request                $request,
        SdeService             $sdeService,
    ): Response
    {
        if ($request->isMethod('POST')) {
            $itemId = $request->get('itemname');
            if (!$sdeService->isValidItem($itemId)) {
                $this->addFlash('error', 'Ungültiges Item ausgewählt. Bitte wähle ein Item aus der Vorschlagsliste.');
                return $this->redirectToRoute('app_order_list');
            }

            $amount = $request->get('amount');
            if (!is_numeric($amount) || (int)$amount <= 0) {
                $this->addFlash('error', 'Die Menge muss eine Zahl größer als 0 sein.');
                return $this->redirectToRoute('app_order_list');
            }

            $order = new BuyOrder();
            $order->setItem($itemId);
            $order->setAmount((int)$amount);
            $order->setPercentToJitaBuy((int)$request->get('jita_buy', 100));

            $order->setBuyer($this->getUser());

            $entityManager->persist($order);
            $entityManager->flush();
        }

        return $this->redirectToRoute('app_order_list');
    }

    #[IsGranted('ROLE_MEMBER')]
    #[Route('/order/{id}/update_order', name: 'update_order', methods: ['POST', 'GET'])]
    public function updateOrderByID(
        BuyOrder               $order,
        EntityManagerInterface $entityManager,
        Request                $request,
        UserRepository         $userRepository,
        SdeService             $sdeService,
    ): Response
    {
        if ($order->getBuyer() !== $this->getUser() && !$this->isGranted('ROLE_CEO')) {
            $this->addFlash('error', 'Du darfst diesen Kaufauftrag nicht bearbeiten.');
            return $this->redirectToRoute('app_order_list');
        }

        if ($request->getMethod() === "POST") {
            $itemId = $request->get('itemname');
            if (!$sdeService->isValidItem($itemId)) {
                $this->addFlash('error', 'Ungültiges Item ausgewählt. Bitte wähle ein Item aus der Vorschlagsliste.');
                return $this->redirectToRoute('app_order_list');
            }

            $amount = $request->get('amount');
            if (!is_numeric($amount) || (int)$amount <= 0) {
                $this->addFlash('error', 'Die Menge muss eine Zahl größer als 0 sein.');
                return $this->redirectToRoute('app_order_list');
            }

            $order->setItem($itemId);
            $order->setAmount((int)$amount);
            $order->setPercentToJitaBuy((int)$request->get('jita_buy', 100));

            $sellerName = $request->get('seller');
            if ($sellerName) {
                $seller = $userRepository->findOneBy(['username' => $sellerName]);
                $order->setFulfiller($seller);
            } else {
                $order->setFulfiller(null);
            }

            $entityManager->persist($order);
            $entityManager->flush();

            return $this->redirectToRoute('app_order_list');

        }

        return $this->redirectToRoute('app_order_list', ['edit_buy' => $order->getId()]);
    }

    #[IsGranted('ROLE_MEMBER')]
    #[Route('/order/{id}/set_order_fulfiller', name: 'set_order_fulfiller', methods: ['POST'])]
    public function setOrderFullfiller(
        BuyOrder               $order,
        EntityManagerInterface $entityManager
    ): Response
    {
        $order->setFulfiller($this->getUser());
        $entityManager->flush();
        return $this->redirectToRoute('app_order_list');
    }

    #[IsGranted('ROLE_MEMBER')]
    #[Route('/order/order/delete/{id}', name: 'app_order_delete', methods: ['POST', 'DELETE'])]
    public function deleteOrder(
        BuyOrder               $order,
        EntityManagerInterface $entityManager
    ): Response
    {
        if ($order->getBuyer() !== $this->getUser() && !$this->isGranted('ROLE_CEO')) {
            $this->addFlash('error', 'Du darfst diesen Kaufauftrag nicht löschen.');
            return $this->redirectToRoute('app_order_list');
        }

        $entityManager->remove($order);
        $entityManager->flush();
        $this->addFlash('danger', 'Bestellung erfolgreich gelöscht.');
        return $this->redirectToRoute('app_order_list');
    }


    #[IsGranted('ROLE_MEMBER')]
    #[Route('/order/create_sell', name: 'app_order_create_sell', methods: ['POST', 'GET'])]
    public function create_sell(
        EntityManagerInterface $entityManager,
        Request                $request,
        SdeService             $sdeService,
    ): Response
    {
        if ($request->isMethod('POST')) {
            $itemId = $request->get('itemname');
            if (!$sdeService->isValidItem($itemId)) {
                $this->addFlash('error', 'Ungültiges Item ausgewählt. Bitte wähle ein Item aus der Vorschlagsliste.');
                return $this->redirectToRoute('app_order_list');
            }

            $amount = $request->get('amount');
            if (!is_numeric($amount) || (int)$amount <= 0) {
                $this->addFlash('error', 'Die Menge muss eine Zahl größer als 0 sein.');
                return $this->redirectToRoute('app_order_list');
            }

            $sell = new SellOrder();
            $sell->setItem($itemId);
            $sell->setAmount((int)$amount);
            $sell->setPercentToJitaSell((int)$request->get('jita_sell', 100));

            $sell->setSeller($this->getUser());

            $entityManager->persist($sell);
            $entityManager->flush();
        }

        return $this->redirectToRoute('app_order_list');
    }

    #[IsGranted('ROLE_MEMBER')]
    #[Route('/order/{id}/update_sell', name: 'update_sell', methods: ['POST', 'GET'])]
    public function update_sell(
        SellOrder              $sell,
        EntityManagerInterface $entityManager,
        Request                $request,
        UserRepository         $userRepository,
        SdeService             $sdeService,
    ): Response
    {
        if ($sell->getSeller() !== $this->getUser() && !$this->isGranted('ROLE_CEO')) {
            $this->addFlash('error', 'Du darfst diesen Verkaufsauftrag nicht bearbeiten.');
            return $this->redirectToRoute('app_order_list');
        }

        if ($request->getMethod() === "POST") {
            $itemId = $request->get('itemname');
            if (!$sdeService->isValidItem($itemId)) {
                $this->addFlash('error', 'Ungültiges Item ausgewählt. Bitte wähle ein Item aus der Vorschlagsliste.');
                return $this->redirectToRoute('app_order_list');
            }

            $amount = $request->get('amount');
            if (!is_numeric($amount) || (int)$amount <= 0) {
                $this->addFlash('error', 'Die Menge muss eine Zahl größer als 0 sein.');
                return $this->redirectToRoute('app_order_list');
            }

            $sell->setItem($itemId);
            $sell->setAmount((int)$amount);
            $sell->setPercentToJitaSell((int)$request->get('jita_sell', 100));

            $buyerName = $request->get('buyer');
            if ($buyerName) {
                $buyer = $userRepository->findOneBy(['username' => $buyerName]);
                $sell->setBuyer($buyer);
            } else {
                $sell->setBuyer(null);
            }

            $entityManager->persist($sell);
            $entityManager->flush();

            return $this->redirectToRoute('app_order_list');

        }

        return $this->redirectToRoute('app_order_list', ['edit_sell' => $sell->getId()]);
    }

    #[IsGranted('ROLE_MEMBER')]
    #[Route('/order/{id}/set_sell_fulfiller', name: 'set_sell_fulfiller', methods: ['POST'])]
    public function setSellFullfiller(
        SellOrder              $sell,
        EntityManagerInterface $entityManager
    ): Response
    {
        $sell->setBuyer($this->getUser());
        $entityManager->flush();
        return $this->redirectToRoute('app_order_list');
    }

    #[IsGranted('ROLE_MEMBER')]
    #[Route('/order/sell/delete/{id}', name: 'app_sell_delete', methods: ['POST', 'DELETE'])]
    public function deleteSellOrder(
        SellOrder              $sell,
        EntityManagerInterface $entityManager
    ): Response
    {
        if ($sell->getSeller() !== $this->getUser() && !$this->isGranted('ROLE_CEO')) {
            $this->addFlash('error', 'Du darfst diesen Verkaufsauftrag nicht löschen.');
            return $this->redirectToRoute('app_order_list');
        }

        $entityManager->remove($sell);
        $entityManager->flush();
        $this->addFlash('danger', 'Bestellung erfolgreich gelöscht.');
        return $this->redirectToRoute('app_order_list');
    }
}
