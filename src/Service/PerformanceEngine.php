<?php

namespace App\Service;

use App\Entity\EveCharacter;
use App\Entity\EveCharacterAssetChange;
use App\Entity\EveCharacterContract;
use App\Entity\EveCharacterMarketTransaction;
use App\Entity\EveCharacterWalletJournalEntry;
use App\Entity\TrackingList;
use App\Entity\TrackingListItem;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;

class PerformanceEngine
{
    private readonly \Doctrine\Persistence\ManagerRegistry $doctrine;

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly SdeService $sdeService,
        private readonly JitaPriceService $jitaPriceService,
        \Doctrine\Persistence\ManagerRegistry $doctrine
    ) {
        $this->doctrine = $doctrine;
    }

    /**
     * Calculates daily performance for a user's characters.
     * 
     * @param User $user
     * @param \DateTimeImmutable|null $startDate
     * @param \DateTimeImmutable|null $endDate
     * @return array
     */
    public function calculateDailyPerformance(User $user, ?\DateTimeImmutable $startDate = null, ?\DateTimeImmutable $endDate = null): array
    {
        if ($startDate === null) {
            $startDate = (new \DateTimeImmutable('-90 days'))->setTime(0, 0, 0);
        }
        if ($endDate === null) {
            $endDate = (new \DateTimeImmutable())->setTime(23, 59, 59);
        }

        // 1. Get user's characters
        $characters = $this->entityManager->getRepository(EveCharacter::class)->findBy(['user' => $user]);
        if (empty($characters)) {
            return [];
        }

        $characterMap = [];
        $characterIds = [];
        $earliestCutoff = null;
        foreach ($characters as $char) {
            $characterIds[] = $char->getId();
            $characterMap[$char->getId()] = $char;

            $cutoff = $char->getPerformanceCutoffDate();
            if ($cutoff !== null) {
                if ($earliestCutoff === null || $cutoff < $earliestCutoff) {
                    $earliestCutoff = $cutoff;
                }
            }
        }

        // Apply dynamic cutoff date based on characters' activation dates
        if ($earliestCutoff !== null && $startDate < $earliestCutoff) {
            $startDate = $earliestCutoff;
        }

        // 2. Fetch all asset changes in the range
        $assetChanges = $this->entityManager->getRepository(EveCharacterAssetChange::class)->createQueryBuilder('c')
            ->where('c.character IN (:characters)')
            ->andWhere('c.loggedAt >= :start')
            ->andWhere('c.loggedAt <= :end')
            ->setParameter('characters', $characters)
            ->setParameter('start', $startDate)
            ->setParameter('end', $endDate)
            ->orderBy('c.loggedAt', 'ASC')
            ->getQuery()
            ->getResult();

        // 3. Fetch all market buy transactions in the range
        $marketBuys = $this->entityManager->getRepository(EveCharacterMarketTransaction::class)->createQueryBuilder('t')
            ->where('t.character IN (:characters)')
            ->andWhere('t.isBuy = true')
            ->andWhere('t.date >= :start')
            ->andWhere('t.date <= :end')
            ->setParameter('characters', $characters)
            ->setParameter('start', $startDate)
            ->setParameter('end', $endDate)
            ->getQuery()
            ->getResult();

        // 4. Fetch completed contracts in the range
        $contracts = $this->entityManager->getRepository(EveCharacterContract::class)->createQueryBuilder('c')
            ->where('c.character IN (:characters)')
            ->andWhere('c.dateCompleted >= :start')
            ->andWhere('c.dateCompleted <= :end')
            ->andWhere('c.status = :status')
            ->setParameter('characters', $characters)
            ->setParameter('start', $startDate)
            ->setParameter('end', $endDate)
            ->setParameter('status', 'finished')
            ->getQuery()
            ->getResult();

        // 5. Fetch wallet reward journal entries (Bounties, Agent mission rewards, Daily goal payouts)
        $journalEntries = $this->entityManager->getRepository(EveCharacterWalletJournalEntry::class)->createQueryBuilder('j')
            ->where('j.character IN (:characters)')
            ->andWhere('j.date >= :start')
            ->andWhere('j.date <= :end')
            ->andWhere('j.refType IN (:refTypes)')
            ->setParameter('characters', $characters)
            ->setParameter('start', $startDate)
            ->setParameter('end', $endDate)
            ->setParameter('refTypes', ['bounty_payout', 'agent_mission_reward'])
            ->getQuery()
            ->getResult();

        // 6. Gather all unique type IDs to resolve names and metadata in bulk
        $typeIds = [];
        /** @var EveCharacterAssetChange $change */
        foreach ($assetChanges as $change) {
            $typeIds[] = $change->getTypeId();
        }
        /** @var EveCharacterMarketTransaction $buy */
        foreach ($marketBuys as $buy) {
            $typeIds[] = $buy->getTypeId();
        }
        /** @var EveCharacterContract $contract */
        foreach ($contracts as $contract) {
            foreach ($contract->getItems() as $item) {
                if (isset($item['typeId'])) {
                    $typeIds[] = (int)$item['typeId'];
                }
            }
        }
        $typeIds = array_values(array_unique(array_filter($typeIds)));

        // 7. Resolve SDE item metadata in bulk
        $itemMetadata = $this->resolveItemMetadata($typeIds);

        // 8. Load Abyss Loot template type IDs to flag them
        $abyssTypeIds = $this->getAbyssTypeIds();

        // 9. Fetch global Jita prices
        $globalPrices = $this->jitaPriceService->getGlobalPrices();

        // 10. Process changes day-by-day
        $dailyLedger = [];

        // Resolve raw equivalents for all type IDs
        $compressionMap = [];
        foreach ($typeIds as $tid) {
            $compressionMap[$tid] = $this->resolveCompression($tid, $itemMetadata);
        }

        // Aggregate market buys: [date][rawTypeId] => quantity
        $marketBuyAgg = [];
        /** @var EveCharacterMarketTransaction $buy */
        foreach ($marketBuys as $buy) {
            $charId = $buy->getCharacter()->getId();
            $char = $characterMap[$charId] ?? null;
            $cutoff = $char?->getPerformanceCutoffDate();
            if ($cutoff !== null && $buy->getDate() < $cutoff) {
                continue;
            }

            $dateStr = $buy->getDate()->format('Y-m-d');
            $tid = $buy->getTypeId();
            $qty = (int)$buy->getQuantity();

            $comp = $compressionMap[$tid] ?? ['typeId' => $tid, 'ratio' => 1];
            $rawTid = $comp['typeId'];
            $ratio = $comp['ratio'];

            if (!isset($marketBuyAgg[$dateStr][$rawTid])) {
                $marketBuyAgg[$dateStr][$rawTid] = 0;
            }
            $marketBuyAgg[$dateStr][$rawTid] += ($qty * $ratio);
        }

        // Aggregate contract receipts: [date][rawTypeId] => quantity
        $contractRecAgg = [];
        /** @var EveCharacterContract $contract */
        foreach ($contracts as $contract) {
            $charId = $contract->getCharacter()->getId();
            $char = $characterMap[$charId] ?? null;
            $cutoff = $char?->getPerformanceCutoffDate();
            if ($cutoff !== null && $contract->getDateCompleted() < $cutoff) {
                continue;
            }

            $dateStr = $contract->getDateCompleted()->format('Y-m-d');
            $charId = $contract->getCharacter()->getId();
            
            $isAcceptor = ((int)$contract->getAcceptorId() === $charId);
            $isIssuer = ((int)$contract->getIssuerId() === $charId);

            foreach ($contract->getItems() as $item) {
                if (!isset($item['typeId']) || !isset($item['quantity'])) {
                    continue;
                }
                $tid = (int)$item['typeId'];
                $qty = (int)$item['quantity'];
                $isIncluded = (bool)($item['isIncluded'] ?? true);

                $received = false;
                if ($isAcceptor && $isIncluded) {
                    $received = true;
                } elseif ($isIssuer && !$isIncluded) {
                    $received = true;
                }

                if ($received) {
                    $comp = $compressionMap[$tid] ?? ['typeId' => $tid, 'ratio' => 1];
                    $rawTid = $comp['typeId'];
                    $ratio = $comp['ratio'];

                    if (!isset($contractRecAgg[$dateStr][$rawTid])) {
                        $contractRecAgg[$dateStr][$rawTid] = 0;
                    }
                    $contractRecAgg[$dateStr][$rawTid] += ($qty * $ratio);
                }
            }
        }

        // Process asset changes (net change per user aggregated by sync runs): [date][rawTypeId] => quantity
        // Group changes by 15-minute run windows to cancel out inter-character transfers
        $runAgg = [];
        /** @var EveCharacterAssetChange $change */
        foreach ($assetChanges as $change) {
            $charId = $change->getCharacter()->getId();
            $char = $characterMap[$charId] ?? null;
            $cutoff = $char?->getPerformanceCutoffDate();
            if ($cutoff !== null && $change->getLoggedAt() < $cutoff) {
                continue;
            }

            $loggedAt = $change->getLoggedAt();
            $dateStr = $loggedAt->format('Y-m-d');
            $timestamp = $loggedAt->getTimestamp();
            // Round to 15 minutes (900 seconds)
            $roundedTime = floor($timestamp / 900) * 900;
            
            $tid = $change->getTypeId();
            $qty = (int)$change->getQuantity();

            $comp = $compressionMap[$tid] ?? ['typeId' => $tid, 'ratio' => 1];
            $rawTid = $comp['typeId'];
            $ratio = $comp['ratio'];

            $groupKey = $dateStr . '_' . $roundedTime;
            if (!isset($runAgg[$groupKey][$rawTid])) {
                $runAgg[$groupKey][$rawTid] = 0;
            }
            $runAgg[$groupKey][$rawTid] += ($qty * $ratio);
        }

        // Only sum positive net changes per day to capture actual earnings/gains
        $assetChangeAgg = [];
        foreach ($runAgg as $groupKey => $items) {
            $dateStr = explode('_', $groupKey)[0];
            foreach ($items as $rawTid => $netQty) {
                if ($netQty > 0) {
                    if (!isset($assetChangeAgg[$dateStr][$rawTid])) {
                        $assetChangeAgg[$dateStr][$rawTid] = 0;
                    }
                    $assetChangeAgg[$dateStr][$rawTid] += $netQty;
                }
            }
        }

        // 11. Combine and calculate net items gained
        $dates = array_unique(array_merge(
            array_keys($assetChangeAgg),
            array_keys($marketBuyAgg),
            array_keys($contractRecAgg)
        ));
        sort($dates);

        foreach ($dates as $dateStr) {
            $dayData = [
                'date' => $dateStr,
                'summary' => [
                    'totalValue' => 0.0,
                    'byCategory' => [
                        'gas' => 0.0,
                        'ore_ice' => 0.0,
                        'blue_loot' => 0.0,
                        'abyss_loot' => 0.0,
                        'hacking_salvage' => 0.0,
                        'wallet_rewards' => 0.0,
                        'other' => 0.0
                    ]
                ],
                'details' => []
            ];

            // A. Process wallet journal rewards first
            /** @var EveCharacterWalletJournalEntry $entry */
            foreach ($journalEntries as $entry) {
                if ($entry->getDate()->format('Y-m-d') !== $dateStr) {
                    continue;
                }
                
                $char = $characterMap[$entry->getCharacter()->getId()] ?? null;
                $cutoff = $char?->getPerformanceCutoffDate();
                if ($cutoff !== null && $entry->getDate() < $cutoff) {
                    continue;
                }

                $amount = (float)$entry->getAmount();
                if ($amount <= 0) {
                    continue;
                }

                $charName = $entry->getCharacter()->getName();
                $refType = $entry->getRefType();
                
                $rewardName = match ($refType) {
                    'bounty_payout' => 'Kopfgeld-Auszahlung (Bounty)',
                    'agent_mission_reward' => 'Missionsbelohnung (Agent)',
                    'daily_goal_payouts' => 'Tägliche Belohnung (Daily Goal)',
                    default => 'Auszahlung'
                };

                $dayData['summary']['byCategory']['wallet_rewards'] += $amount;
                $dayData['summary']['totalValue'] += $amount;

                $dayData['details'][] = [
                    'character' => $charName,
                    'category' => 'wallet_rewards',
                    'typeName' => $rewardName,
                    'quantity' => 1,
                    'price' => $amount,
                    'totalValue' => $amount,
                    'isWallet' => true,
                    'typeId' => 0
                ];
            }

            // B. Process net item changes (user-level)
            $tidsForDate = array_unique(array_merge(
                isset($assetChangeAgg[$dateStr]) ? array_keys($assetChangeAgg[$dateStr]) : [],
                isset($marketBuyAgg[$dateStr]) ? array_keys($marketBuyAgg[$dateStr]) : [],
                isset($contractRecAgg[$dateStr]) ? array_keys($contractRecAgg[$dateStr]) : []
            ));

            // To attribute items to characters, we can check which character had the maximum positive change for this item.
            $getAttributedCharacter = function(int $rawTid, string $dateStr) use ($assetChanges, $compressionMap, $characterMap) {
                $maxQty = 0;
                $bestCharName = null;
                foreach ($assetChanges as $change) {
                    if ($change->getLoggedAt()->format('Y-m-d') === $dateStr) {
                        $tid = $change->getTypeId();
                        $comp = $compressionMap[$tid] ?? ['typeId' => $tid, 'ratio' => 1];
                        if ($comp['typeId'] === $rawTid) {
                            $qty = (int)$change->getQuantity() * $comp['ratio'];
                            if ($qty > $maxQty) {
                                $maxQty = $qty;
                                $bestCharName = $change->getCharacter()->getName();
                            }
                        }
                    }
                }
                if ($bestCharName === null && !empty($characterMap)) {
                    $firstChar = reset($characterMap);
                    $bestCharName = $firstChar->getName();
                }
                return $bestCharName ?? 'Unbekannter Charakter';
            };

            foreach ($tidsForDate as $rawTid) {
                $changeQty = $assetChangeAgg[$dateStr][$rawTid] ?? 0;
                $buyQty = $marketBuyAgg[$dateStr][$rawTid] ?? 0;
                $contractQty = $contractRecAgg[$dateStr][$rawTid] ?? 0;

                // Net quantity acquired (user-level): total increases MINUS what was bought or contract-traded
                $netQty = $changeQty - $buyQty - $contractQty;

                if ($netQty <= 0) {
                    continue;
                }

                // Get metadata and price
                $meta = $itemMetadata[$rawTid] ?? [
                    'name' => 'Item #' . $rawTid,
                    'categoryId' => 0,
                    'groupId' => 0,
                    'groupName' => 'Other'
                ];

                $price = $globalPrices[$rawTid] ?? 0.0;
                $totalValue = $netQty * $price;

                // Determine Category
                $category = 'other';
                if (in_array($rawTid, $abyssTypeIds, true)) {
                    $category = 'abyss_loot';
                } elseif ($meta['groupId'] === 711 || $meta['groupId'] === 4168) {
                    $category = 'gas';
                } elseif ($meta['categoryId'] === 25) {
                    $category = 'ore_ice';
                } elseif ($meta['groupId'] === 880) {
                    $category = 'blue_loot';
                } elseif (
                    $meta['groupId'] === 754 || 
                    $meta['groupId'] === 966 || 
                    $meta['groupId'] === 333 ||
                    in_array($meta['groupId'], [728, 729, 730, 731, 732, 733, 734, 735, 979, 1304, 367776], true) ||
                    $rawTid === 34
                ) {
                    $category = 'hacking_salvage';
                }

                $dayData['summary']['byCategory'][$category] += $totalValue;
                $dayData['summary']['totalValue'] += $totalValue;

                $dayData['details'][] = [
                    'character' => $getAttributedCharacter($rawTid, $dateStr),
                    'category' => $category,
                    'typeName' => $meta['name'],
                    'quantity' => $netQty,
                    'price' => $price,
                    'totalValue' => $totalValue,
                    'isWallet' => false,
                    'typeId' => $rawTid
                ];
            }

            if (!empty($dayData['details'])) {
                // Sort details by totalValue descending
                usort($dayData['details'], fn($a, $b) => $b['totalValue'] <=> $a['totalValue']);
                $dailyLedger[$dateStr] = $dayData;
            }
        }

        // Return sorted descending by date
        krsort($dailyLedger);
        return $dailyLedger;
    }

    /**
     * Resolves metadata for type IDs in bulk using the SDE database connection.
     * 
     * @param int[] $typeIds
     * @return array
     */
    private function resolveItemMetadata(array $typeIds): array
    {
        if (empty($typeIds)) {
            return [];
        }

        try {
            $sdeConn = $this->doctrine->getConnection('sde');
            $placeholders = implode(',', array_fill(0, count($typeIds), '?'));
            $rows = $sdeConn->fetchAllAssociative(
                "SELECT t.typeID, t.typeName, g.groupID, g.groupName, g.categoryID 
                 FROM invTypes t 
                 JOIN invGroups g ON t.groupID = g.groupID 
                 WHERE t.typeID IN ($placeholders)",
                $typeIds
            );

            $metadata = [];
            foreach ($rows as $row) {
                $metadata[(int)$row['typeID']] = [
                    'name' => $row['typeName'],
                    'categoryId' => (int)$row['categoryID'],
                    'groupId' => (int)$row['groupID'],
                    'groupName' => $row['groupName']
                ];
            }
            return $metadata;
        } catch (\Exception $e) {
            return [];
        }
    }

    /**
     * Gets all type IDs seeded in the Abyss Loot tracking list.
     * 
     * @return int[]
     */
    private function getAbyssTypeIds(): array
    {
        try {
            $abyssList = $this->entityManager->getRepository(TrackingList::class)
                ->findOneBy(['name' => 'Abyss Loot']);
            if (!$abyssList) {
                return [];
            }

            $items = $this->entityManager->getRepository(TrackingListItem::class)
                ->findBy(['trackingList' => $abyssList]);

            return array_map(fn($item) => $item->getTypeId(), $items);
        } catch (\Exception $e) {
            return [];
        }
    }

    /**
     * Resolves an item type ID to its raw equivalent and its compression ratio.
     * For example, "Compressed Veldspar" -> "Veldspar" (ratio 1).
     * In modern EVE, compressing any item results in 1 unit of compressed item from 1 unit of raw item.
     */
    private function resolveCompression(int $typeId, array $itemMetadata): array
    {
        $meta = $itemMetadata[$typeId] ?? null;
        if (!$meta) {
            return ['typeId' => $typeId, 'ratio' => 1];
        }

        $name = $meta['name'];
        if (str_starts_with($name, 'Compressed ')) {
            $rawName = substr($name, 11);
            
            // Search in resolved metadata
            foreach ($itemMetadata as $tid => $m) {
                if (strtolower($m['name']) === strtolower($rawName)) {
                    return ['typeId' => $tid, 'ratio' => 1];
                }
            }

            // Fallback SDE query
            try {
                $sdeConn = $this->entityManager->getConnection('sde');
                $rawRow = $sdeConn->fetchAssociative(
                    "SELECT typeID FROM invTypes WHERE typeName = :name LIMIT 1",
                    ['name' => $rawName]
                );
                if ($rawRow) {
                    $rawTypeId = (int)$rawRow['typeID'];
                    return ['typeId' => $rawTypeId, 'ratio' => 1];
                }
            } catch (\Exception $e) {
                // Ignore
            }
        }

        return ['typeId' => $typeId, 'ratio' => 1];
    }
}
