<?php

namespace App\Controller\Tool;

use App\Entity\User;
use App\Entity\TrackingList;
use App\Entity\TrackingListItem;
use App\Entity\EveCharacterAssetChange;
use App\Service\JitaPriceService;
use App\Service\SdeService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/dashboard/tracking')]
#[IsGranted('ROLE_MEMBER')]
class TrackingListController extends AbstractController
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager
    ) {}

    #[Route('', name: 'app_tracking_index', methods: ['GET'])]
    public function index(): Response
    {
        return $this->redirectToRoute('app_dashboard_assets_overview');
    }

    #[Route('/api/lists', name: 'app_tracking_api_lists_get', methods: ['GET'])]
    public function getLists(SdeService $sdeService): JsonResponse
    {
        $user = $this->getUser();
        
        // Fetch global lists (user is null) and private lists of current user
        $lists = $this->entityManager->getRepository(TrackingList::class)->createQueryBuilder('l')
            ->where('l.user IS NULL')
            ->orWhere('l.user = :user')
            ->setParameter('user', $user)
            ->getQuery()
            ->getResult();

        $result = [];
        foreach ($lists as $list) {
            $items = [];
            foreach ($list->getItems() as $item) {
                $items[] = [
                    'id' => $item->getId(),
                    'typeId' => $item->getTypeId(),
                    'typeName' => $sdeService->getItemName($item->getTypeId()),
                ];
            }

            $result[] = [
                'id' => $list->getId(),
                'name' => $list->getName(),
                'description' => $list->getDescription(),
                'isGlobal' => $list->isGlobal(),
                'isTemplate' => ($list->getUser() === null),
                'items' => $items,
            ];
        }

        return new JsonResponse($result);
    }

    #[Route('/api/lists', name: 'app_tracking_api_lists_create', methods: ['POST'])]
    public function createList(Request $request): JsonResponse
    {
        $data = json_decode($request->getContent(), true);
        $name = trim($data['name'] ?? '');

        if (empty($name)) {
            return new JsonResponse(['error' => 'Name darf nicht leer sein.'], Response::HTTP_BAD_REQUEST);
        }

        $list = new TrackingList();
        $list->setName($name);
        $list->setDescription(trim($data['description'] ?? ''));
        $list->setIsGlobal(false); // User lists are not global templates by default
        $list->setUser($this->getUser());

        $this->entityManager->persist($list);
        $this->entityManager->flush();

        return new JsonResponse(['success' => true, 'id' => $list->getId()]);
    }

    #[Route('/api/lists/{id}/copy', name: 'app_tracking_api_lists_copy', methods: ['POST'])]
    public function copyList(int $id): JsonResponse
    {
        $listToCopy = $this->entityManager->getRepository(TrackingList::class)->find($id);
        if (!$listToCopy) {
            return new JsonResponse(['error' => 'Vorlage nicht gefunden.'], Response::HTTP_NOT_FOUND);
        }

        $newList = new TrackingList();
        $newList->setName($listToCopy->getName() . ' (Kopie)');
        $newList->setDescription($listToCopy->getDescription());
        $newList->setIsGlobal(false);
        $newList->setUser($this->getUser());

        $this->entityManager->persist($newList);

        foreach ($listToCopy->getItems() as $item) {
            $newItem = new TrackingListItem();
            $newItem->setTrackingList($newList);
            $newItem->setTypeId($item->getTypeId());
            $this->entityManager->persist($newItem);
        }

        $this->entityManager->flush();

        return new JsonResponse(['success' => true, 'id' => $newList->getId()]);
    }

    #[Route('/api/lists/{id}', name: 'app_tracking_api_lists_delete', methods: ['DELETE'])]
    public function deleteList(int $id): JsonResponse
    {
        $list = $this->entityManager->getRepository(TrackingList::class)->find($id);
        if (!$list) {
            return new JsonResponse(['error' => 'Liste nicht gefunden.'], Response::HTTP_NOT_FOUND);
        }

        // Templates (user is null) can only be deleted by CEOs
        if ($list->getUser() === null && !$this->isGranted('ROLE_CEO')) {
            return new JsonResponse(['error' => 'Vorlagen können nur von CEOs gelöscht werden.'], Response::HTTP_FORBIDDEN);
        }

        // Private lists can only be deleted by their owner
        if ($list->getUser() !== null && $list->getUser() !== $this->getUser()) {
            return new JsonResponse(['error' => 'Du darfst nur deine eigenen Listen löschen.'], Response::HTTP_FORBIDDEN);
        }

        $this->entityManager->remove($list);
        $this->entityManager->flush();

        return new JsonResponse(['success' => true]);
    }

    #[Route('/api/lists/{id}/items', name: 'app_tracking_api_lists_add_item', methods: ['POST'])]
    public function addItem(int $id, Request $request): JsonResponse
    {
        $list = $this->entityManager->getRepository(TrackingList::class)->find($id);
        if (!$list) {
            return new JsonResponse(['error' => 'Liste nicht gefunden.'], Response::HTTP_NOT_FOUND);
        }

        // Do not allow editing templates or other users' lists
        if ($list->getUser() !== $this->getUser()) {
            return new JsonResponse(['error' => 'Du kannst keine Items zu Vorlagen oder fremden Listen hinzufügen.'], Response::HTTP_FORBIDDEN);
        }

        $data = json_decode($request->getContent(), true);
        $typeId = (int) ($data['typeId'] ?? 0);

        if ($typeId <= 0) {
            return new JsonResponse(['error' => 'Ungültige Type-ID.'], Response::HTTP_BAD_REQUEST);
        }

        // Check if already in list
        $itemRepository = $this->entityManager->getRepository(TrackingListItem::class);
        $existing = $itemRepository->findOneBy(['trackingList' => $list, 'typeId' => $typeId]);
        if ($existing) {
            return new JsonResponse(['error' => 'Item ist bereits in der Liste.'], Response::HTTP_BAD_REQUEST);
        }

        $item = new TrackingListItem();
        $item->setTrackingList($list);
        $item->setTypeId($typeId);

        $this->entityManager->persist($item);
        $this->entityManager->flush();

        return new JsonResponse(['success' => true, 'id' => $item->getId()]);
    }

    #[Route('/api/lists/{id}/items/bulk', name: 'app_tracking_api_lists_add_items_bulk', methods: ['POST'])]
    public function addItemsBulk(int $id, Request $request): JsonResponse
    {
        $list = $this->entityManager->getRepository(TrackingList::class)->find($id);
        if (!$list) {
            return new JsonResponse(['error' => 'Liste nicht gefunden.'], Response::HTTP_NOT_FOUND);
        }

        // Do not allow editing templates or other users' lists
        if ($list->getUser() !== $this->getUser()) {
            return new JsonResponse(['error' => 'Du kannst keine Items zu Vorlagen oder fremden Listen hinzufügen.'], Response::HTTP_FORBIDDEN);
        }

        $data = json_decode($request->getContent(), true);
        $typeIds = $data['typeIds'] ?? [];

        if (!is_array($typeIds) || empty($typeIds)) {
            return new JsonResponse(['error' => 'Keine gültigen Type-IDs übergeben.'], Response::HTTP_BAD_REQUEST);
        }

        $itemRepository = $this->entityManager->getRepository(TrackingListItem::class);
        $addedCount = 0;

        foreach ($typeIds as $typeId) {
            $typeId = (int)$typeId;
            if ($typeId <= 0) {
                continue;
            }

            // Check if already in list
            $existing = $itemRepository->findOneBy(['trackingList' => $list, 'typeId' => $typeId]);
            if ($existing) {
                continue;
            }

            $item = new TrackingListItem();
            $item->setTrackingList($list);
            $item->setTypeId($typeId);
            $this->entityManager->persist($item);
            $addedCount++;
        }

        if ($addedCount > 0) {
            $this->entityManager->flush();
        }

        return new JsonResponse(['success' => true, 'addedCount' => $addedCount]);
    }


    #[Route('/api/lists/items/{itemId}', name: 'app_tracking_api_lists_remove_item', methods: ['DELETE'])]
    public function removeItem(int $itemId): JsonResponse
    {
        $item = $this->entityManager->getRepository(TrackingListItem::class)->find($itemId);
        if (!$item) {
            return new JsonResponse(['error' => 'Eintrag nicht gefunden.'], Response::HTTP_NOT_FOUND);
        }

        // Check ownership of the list
        if ($item->getTrackingList()->getUser() !== $this->getUser()) {
            return new JsonResponse(['error' => 'Du kannst keine Items aus Vorlagen oder fremden Listen entfernen.'], Response::HTTP_FORBIDDEN);
        }

        $this->entityManager->remove($item);
        $this->entityManager->flush();

        return new JsonResponse(['success' => true]);
    }

    #[Route('/api/data', name: 'app_tracking_api_data', methods: ['GET'])]
    public function getTrackingData(Request $request, JitaPriceService $priceService, SdeService $sdeService): JsonResponse
    {
        $listId = $request->query->getInt('listId');
        $rangeType = $request->query->get('rangeType', 'days'); // 'hours', 'days', 'single_date'
        
        $list = $this->entityManager->getRepository(TrackingList::class)->find($listId);
        if (!$list) {
            return new JsonResponse(['error' => 'Liste nicht gefunden.'], Response::HTTP_NOT_FOUND);
        }

        // Get tracked type IDs
        $typeIds = [];
        foreach ($list->getItems() as $item) {
            $typeIds[] = $item->getTypeId();
        }

        if (empty($typeIds)) {
            return new JsonResponse([
                'listName' => $list->getName(),
                'dates' => [],
                'characters' => [],
                'itemBreakdown' => [],
                'totalValue' => 0.0
            ]);
        }

        // Fetch prices
        $prices = $priceService->getGlobalPrices();

        $dates = [];
        $cutoffDate = null;
        $endDate = null;
        $now = new \DateTimeImmutable();

        if ($rangeType === 'hours') {
            $hours = $request->query->getInt('hours', 24);
            $hours = min(max($hours, 1), 48); // Limit to max 48 hours
            
            $cutoffDate = $now->modify(sprintf('-%d hours', $hours))->setTime((int)$now->format('H'), 0, 0);
            $endDate = $now;

            // Generate hourly labels
            $tempDate = $cutoffDate;
            while ($tempDate <= $endDate) {
                $dates[] = $tempDate->format('H:00');
                $tempDate = $tempDate->modify('+1 hour');
            }
        } elseif ($rangeType === 'single_date') {
            $dateStr = $request->query->get('date');
            try {
                $selectedDate = new \DateTimeImmutable($dateStr);
            } catch (\Exception $e) {
                $selectedDate = new \DateTimeImmutable('today');
            }
            
            // Limit to last 30 days
            $maxPastDate = (new \DateTimeImmutable('today'))->modify('-30 days');
            if ($selectedDate < $maxPastDate) {
                $selectedDate = $maxPastDate;
            }

            $cutoffDate = $selectedDate->setTime(0, 0, 0);
            $endDate = $selectedDate->setTime(23, 59, 59);

            // Generate 24 hourly labels for that day
            for ($h = 0; $h < 24; $h++) {
                $dates[] = sprintf('%02d:00', $h);
            }
        } else {
            // Default: 'days'
            $days = $request->query->getInt('days', 30);
            $days = min(max($days, 1), 30); // Limit to max 30 days

            $cutoffDate = $now->modify(sprintf('-%d days', $days))->setTime(0, 0, 0);
            $endDate = $now;

            // Generate daily labels
            $period = new \DatePeriod(
                $cutoffDate,
                new \DateInterval('P1D'),
                $endDate->modify('+1 day')
            );
            foreach ($period as $date) {
                $dates[] = $date->format('Y-m-d');
            }
        }

        // Fetch changes from DB - filtered strictly by current User's characters!
        $changes = $this->entityManager->getRepository(EveCharacterAssetChange::class)->createQueryBuilder('c')
            ->select('c', 'char')
            ->join('c.character', 'char')
            ->where('c.typeId IN (:typeIds)')
            ->andWhere('c.loggedAt >= :cutoff')
            ->andWhere('c.loggedAt <= :endDate')
            ->andWhere('char.user = :currentUser') // Strict user check
            ->setParameter('typeIds', $typeIds)
            ->setParameter('cutoff', $cutoffDate)
            ->setParameter('endDate', $endDate)
            ->setParameter('currentUser', $this->getUser())
            ->orderBy('c.loggedAt', 'ASC')
            ->getQuery()
            ->getResult();

        $charData = [];
        $itemTotals = [];
        $totalValue = 0.0;

        /** @var EveCharacterAssetChange $change */
        foreach ($changes as $change) {
            $loggedAt = $change->getLoggedAt();
            
            // Map the timestamp to the correct label key
            if ($rangeType === 'hours' || $rangeType === 'single_date') {
                $labelKey = $loggedAt->format('H:00');
            } else {
                $labelKey = $loggedAt->format('Y-m-d');
            }

            $charName = $change->getCharacter()->getName();
            $typeId = $change->getTypeId();
            $qty = (int) $change->getQuantity();
            $price = $prices[$typeId] ?? 0.0;
            $value = $qty * $price;
            $totalValue += $value;

            // Group by character and date
            if (!isset($charData[$charName])) {
                $charData[$charName] = array_fill_keys($dates, 0.0);
            }
            if (isset($charData[$charName][$labelKey])) {
                $charData[$charName][$labelKey] += $value;
            }
        }

        // Format for React
        $formattedChars = [];
        foreach ($charData as $name => $valuesByDate) {
            $formattedChars[] = [
                'name' => $name,
                'data' => array_values($valuesByDate)
            ];
        }

        // Format item breakdown
        foreach ($changes as $change) {
            $typeId = $change->getTypeId();
            $qty = (int) $change->getQuantity();
            $price = $prices[$typeId] ?? 0.0;
            $value = $qty * $price;

            if (!isset($itemTotals[$typeId])) {
                $itemTotals[$typeId] = [
                    'typeId' => $typeId,
                    'typeName' => $sdeService->getItemName($typeId),
                    'quantity' => 0,
                    'value' => 0.0
                ];
            }
            $itemTotals[$typeId]['quantity'] += $qty;
            $itemTotals[$typeId]['value'] += $value;
        }

        $breakdown = array_values($itemTotals);
        usort($breakdown, function($a, $b) {
            return $b['value'] <=> $a['value'];
        });

        return new JsonResponse([
            'listName' => $list->getName(),
            'dates' => $dates,
            'characters' => $formattedChars,
            'itemBreakdown' => $breakdown,
            'totalValue' => $totalValue
        ]);
    }

    #[Route('/api/changes', name: 'app_tracking_api_changes_get', methods: ['GET'])]
    public function getChanges(Request $request): JsonResponse
    {
        $listId = $request->query->get('listId');
        $typeId = $request->query->getInt('typeId');
        $rangeType = $request->query->get('rangeType', 'days');
        
        if ($listId !== null && $listId !== '') {
            $list = $this->entityManager->getRepository(TrackingList::class)->find((int)$listId);
            if (!$list) {
                return new JsonResponse(['error' => 'Liste nicht gefunden.'], Response::HTTP_NOT_FOUND);
            }
        }

        $now = new \DateTimeImmutable();
        if ($rangeType === 'hours') {
            $hours = $request->query->getInt('hours', 24);
            $cutoffDate = $now->modify(sprintf('-%d hours', $hours))->setTime((int)$now->format('H'), 0, 0);
            $endDate = $now;
        } elseif ($rangeType === 'single_date') {
            $dateStr = $request->query->get('date');
            try {
                $selectedDate = new \DateTimeImmutable($dateStr);
            } catch (\Exception $e) {
                $selectedDate = new \DateTimeImmutable('today');
            }
            $cutoffDate = $selectedDate->setTime(0, 0, 0);
            $endDate = $selectedDate->setTime(23, 59, 59);
        } else {
            $days = $request->query->getInt('days', 30);
            $cutoffDate = $now->modify(sprintf('-%d days', $days))->setTime(0, 0, 0);
            $endDate = $now;
        }

        $changes = $this->entityManager->getRepository(EveCharacterAssetChange::class)->createQueryBuilder('c')
            ->select('c', 'char')
            ->join('c.character', 'char')
            ->where('c.typeId = :typeId')
            ->andWhere('c.loggedAt >= :cutoff')
            ->andWhere('c.loggedAt <= :endDate')
            ->andWhere('char.user = :currentUser')
            ->setParameter('typeId', $typeId)
            ->setParameter('cutoff', $cutoffDate)
            ->setParameter('endDate', $endDate)
            ->setParameter('currentUser', $this->getUser())
            ->orderBy('c.loggedAt', 'DESC')
            ->getQuery()
            ->getResult();

        $result = [];
        /** @var EveCharacterAssetChange $change */
        foreach ($changes as $change) {
            $result[] = [
                'id' => $change->getId(),
                'characterName' => $change->getCharacter()->getName(),
                'quantity' => (int) $change->getQuantity(),
                'loggedAt' => $change->getLoggedAt()->format('Y-m-d H:i:s')
            ];
        }

        return new JsonResponse($result);
    }

    #[Route('/api/changes/{id}', name: 'app_tracking_api_changes_delete', methods: ['DELETE'])]
    public function deleteChange(int $id): JsonResponse
    {
        $change = $this->entityManager->getRepository(EveCharacterAssetChange::class)->find($id);
        if (!$change) {
            return new JsonResponse(['error' => 'Eintrag nicht gefunden.'], Response::HTTP_NOT_FOUND);
        }

        if ($change->getCharacter()->getUser() !== $this->getUser()) {
            return new JsonResponse(['error' => 'Zugriff verweigert.'], Response::HTTP_FORBIDDEN);
        }

        $this->entityManager->remove($change);
        $this->entityManager->flush();

        return new JsonResponse(['success' => true]);
    }
}
