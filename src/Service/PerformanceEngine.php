<?php

namespace App\Service;

use App\Entity\EveCharacter;
use App\Entity\EveCharacterAssetChange;
use App\Entity\EveCharacterContract;
use App\Entity\EveCharacterMarketTransaction;
use App\Entity\EveCharacterWalletJournalEntry;
use App\Entity\EveKillmail;
use App\Entity\PerformanceExclusion;
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

        // Get tracked type IDs for filtering
        $trackedTypeIds = $this->getTrackedTypeIds();
        $trackedTypeIdsMap = array_fill_keys($trackedTypeIds, true);

        // Fetch exclusions for the user
        $exclusions = $this->entityManager->getRepository(PerformanceExclusion::class)->findBy(['user' => $user]);
        $exclusionMap = [];
        /** @var PerformanceExclusion $ex */
        foreach ($exclusions as $ex) {
            $exDateStr = $ex->getDate()->format('Y-m-d');
            $exKey = sprintf(
                '%s_%s_%s_%s',
                $exDateStr,
                $ex->getCategory(),
                $ex->getTypeName(),
                $ex->getCharacterName()
            );
            $exclusionMap[$exKey] = true;
        }

        // Query the earliest asset change date for each character
        $minAssetChangeDates = [];
        $minTxDates = [];

        $rawAssetChangesMin = $this->entityManager->getRepository(EveCharacterAssetChange::class)->createQueryBuilder('c')
            ->select('IDENTITY(c.character) as charId, MIN(c.loggedAt) as minDate')
            ->where('c.character IN (:characters)')
            ->groupBy('charId')
            ->setParameter('characters', $characters)
            ->getQuery()
            ->getResult();

        foreach ($rawAssetChangesMin as $row) {
            if ($row['minDate']) {
                $minAssetChangeDates[(int)$row['charId']] = new \DateTimeImmutable($row['minDate']);
            }
        }

        $rawTxMin = $this->entityManager->getRepository(EveCharacterMarketTransaction::class)->createQueryBuilder('t')
            ->select('IDENTITY(t.character) as charId, MIN(t.date) as minDate')
            ->where('t.character IN (:characters)')
            ->groupBy('charId')
            ->setParameter('characters', $characters)
            ->getQuery()
            ->getResult();

        foreach ($rawTxMin as $row) {
            if ($row['minDate']) {
                $minTxDates[(int)$row['charId']] = new \DateTimeImmutable($row['minDate']);
            }
        }

        $characterMap = [];
        $characterIds = [];
        $effectiveCutoffs = [];
        $earliestCutoff = null;

        foreach ($characters as $char) {
            $charId = $char->getId();
            $characterIds[] = $charId;
            $characterMap[$charId] = $char;

            $cutoff = $char->getPerformanceCutoffDate();
            
            // Get the earliest recorded asset data date
            $minAssetDate = $minAssetChangeDates[$charId] ?? null;
            $minTxDate = $minTxDates[$charId] ?? null;
            
            $earliestDataDate = null;
            if ($minAssetDate && $minTxDate) {
                $earliestDataDate = $minAssetDate < $minTxDate ? $minAssetDate : $minTxDate;
            } elseif ($minAssetDate) {
                $earliestDataDate = $minAssetDate;
            } else {
                $earliestDataDate = $minTxDate;
            }

            // The effective cutoff is the maximum of the configured cutoff and the earliest data date
            $effectiveCutoff = null;
            if ($cutoff && $earliestDataDate) {
                $effectiveCutoff = $cutoff > $earliestDataDate ? $cutoff : $earliestDataDate;
            } elseif ($cutoff) {
                $effectiveCutoff = $cutoff;
            } else {
                $effectiveCutoff = $earliestDataDate;
            }

            $effectiveCutoffs[$charId] = $effectiveCutoff;

            if ($effectiveCutoff !== null) {
                if ($earliestCutoff === null || $effectiveCutoff < $earliestCutoff) {
                    $earliestCutoff = $effectiveCutoff;
                }
            }
        }

        // Apply dynamic cutoff date based on characters' activation dates
        if ($earliestCutoff !== null && $startDate < $earliestCutoff) {
            $startDate = $earliestCutoff;
        }

        $queryStartDate = $earliestCutoff !== null ? $earliestCutoff : $startDate;

        // 2. Fetch all asset changes in the range
        $assetChanges = $this->entityManager->getRepository(EveCharacterAssetChange::class)->createQueryBuilder('c')
            ->where('c.character IN (:characters)')
            ->andWhere('c.loggedAt >= :start')
            ->andWhere('c.loggedAt <= :end')
            ->setParameter('characters', $characters)
            ->setParameter('start', $queryStartDate)
            ->setParameter('end', $endDate)
            ->orderBy('c.loggedAt', 'ASC')
            ->getQuery()
            ->getResult();

        // 3. Fetch all market transactions in the range (both buys and sells)
        $marketTransactions = $this->entityManager->getRepository(EveCharacterMarketTransaction::class)->createQueryBuilder('t')
            ->where('t.character IN (:characters)')
            ->andWhere('t.date >= :start')
            ->andWhere('t.date <= :end')
            ->setParameter('characters', $characters)
            ->setParameter('start', $queryStartDate)
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
            ->setParameter('start', $queryStartDate)
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
            ->setParameter('start', $queryStartDate)
            ->setParameter('end', $endDate)
            ->setParameter('refTypes', ['bounty_payout', 'agent_mission_reward'])
            ->getQuery()
            ->getResult();

        // 5b. Fetch all ship losses (Killmails where isLoss = true) in the range
        $killmails = $this->entityManager->getRepository(EveKillmail::class)->createQueryBuilder('k')
            ->where('k.character IN (:characters)')
            ->andWhere('k.isLoss = true')
            ->andWhere('k.killmailTime >= :start')
            ->andWhere('k.killmailTime <= :end')
            ->setParameter('characters', $characters)
            ->setParameter('start', $queryStartDate)
            ->setParameter('end', $endDate)
            ->getQuery()
            ->getResult();

        // 5b. Fetch manual performance entries in the range
        $manualEntries = $this->entityManager->getRepository(\App\Entity\PerformanceManualEntry::class)->createQueryBuilder('m')
            ->where('m.user = :user')
            ->andWhere('m.date >= :start')
            ->andWhere('m.date <= :end')
            ->setParameter('user', $user)
            ->setParameter('start', $queryStartDate)
            ->setParameter('end', $endDate)
            ->getQuery()
            ->getResult();

        // 6. Gather all unique type IDs to resolve names and metadata in bulk
        $typeIds = [];
        /** @var EveCharacterAssetChange $change */
        foreach ($assetChanges as $change) {
            $typeIds[] = $change->getTypeId();
        }
        /** @var EveCharacterMarketTransaction $tx */
        foreach ($marketTransactions as $tx) {
            $typeIds[] = $tx->getTypeId();
        }
        /** @var EveCharacterContract $contract */
        foreach ($contracts as $contract) {
            foreach ($contract->getItems() as $item) {
                if (isset($item['typeId'])) {
                    $typeIds[] = (int)$item['typeId'];
                }
            }
        }
        /** @var EveKillmail $km */
        foreach ($killmails as $km) {
            if ($km->getVictimShipTypeId()) {
                $typeIds[] = $km->getVictimShipTypeId();
            }
            $kmData = $km->getData();
            if (isset($kmData['victim']['items']) && is_array($kmData['victim']['items'])) {
                foreach ($kmData['victim']['items'] as $item) {
                    if (isset($item['item_type_id'])) {
                        $typeIds[] = (int)$item['item_type_id'];
                    }
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

        // Aggregate market transactions (buys and sells): [date][rawTypeId] => quantity
        $marketBuyAgg = [];
        $marketSellAgg = [];
        /** @var EveCharacterMarketTransaction $tx */
        foreach ($marketTransactions as $tx) {
            $charId = $tx->getCharacter()->getId();
            $cutoff = $effectiveCutoffs[$charId] ?? null;
            if ($cutoff !== null && $tx->getDate() < $cutoff) {
                continue;
            }

            $dateStr = $tx->getDate()->format('Y-m-d');
            $tid = $tx->getTypeId();
            $qty = (int)$tx->getQuantity();

            $comp = $compressionMap[$tid] ?? ['typeId' => $tid, 'ratio' => 1];
            $rawTid = $comp['typeId'];
            $ratio = $comp['ratio'];

            // Skip untracked items to avoid fake gains/losses
            if (!isset($trackedTypeIdsMap[$rawTid])) {
                continue;
            }

            if ($tx->isBuy()) {
                if (!isset($marketBuyAgg[$dateStr][$rawTid])) {
                    $marketBuyAgg[$dateStr][$rawTid] = 0;
                }
                $marketBuyAgg[$dateStr][$rawTid] += ($qty * $ratio);
            } else {
                if (!isset($marketSellAgg[$dateStr][$rawTid])) {
                    $marketSellAgg[$dateStr][$rawTid] = 0;
                }
                $marketSellAgg[$dateStr][$rawTid] += ($qty * $ratio);
            }
        }

        // Aggregate contract receipts: [date][rawTypeId] => quantity
        $contractRecAgg = [];
        /** @var EveCharacterContract $contract */
        foreach ($contracts as $contract) {
            $charId = $contract->getCharacter()->getId();
            $cutoff = $effectiveCutoffs[$charId] ?? null;
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

                    // Skip untracked items to avoid fake gains/losses
                    if (!isset($trackedTypeIdsMap[$rawTid])) {
                        continue;
                    }

                    if (!isset($contractRecAgg[$dateStr][$rawTid])) {
                        $contractRecAgg[$dateStr][$rawTid] = 0;
                    }
                    $contractRecAgg[$dateStr][$rawTid] += ($qty * $ratio);
                }
            }
        }

        // Process asset changes (net change per user aggregated by day): [date][rawTypeId] => quantity
        // Sum all changes of the day across all characters of the user to cancel out transfers
        $dayAgg = [];
        /** @var EveCharacterAssetChange $change */
        foreach ($assetChanges as $change) {
            $charId = $change->getCharacter()->getId();
            $cutoff = $effectiveCutoffs[$charId] ?? null;
            if ($cutoff !== null && $change->getLoggedAt() < $cutoff) {
                continue;
            }

            $loggedAt = $change->getLoggedAt();
            $dateStr = $loggedAt->format('Y-m-d');
            
            $tid = $change->getTypeId();
            $qty = (int)$change->getQuantity();

            $comp = $compressionMap[$tid] ?? ['typeId' => $tid, 'ratio' => 1];
            $rawTid = $comp['typeId'];
            $ratio = $comp['ratio'];

            if (!isset($dayAgg[$dateStr][$rawTid])) {
                $dayAgg[$dateStr][$rawTid] = 0;
            }
            $dayAgg[$dateStr][$rawTid] += ($qty * $ratio);
        }

        // Map all net changes per day (allowing negatives to offset sales and manual deletes)
        $assetChangeAgg = [];
        foreach ($dayAgg as $dateStr => $items) {
            foreach ($items as $rawTid => $netQty) {
                if (!isset($assetChangeAgg[$dateStr][$rawTid])) {
                    $assetChangeAgg[$dateStr][$rawTid] = 0;
                }
                $assetChangeAgg[$dateStr][$rawTid] += $netQty;
            }
        }

        // Aggregate manual entries: [date][] => entry
        $manualEntryAgg = [];
        /** @var \App\Entity\PerformanceManualEntry $entry */
        foreach ($manualEntries as $entry) {
            $dateStr = $entry->getDate()->format('Y-m-d');
            $manualEntryAgg[$dateStr][] = $entry;
        }

        $killmailDates = [];
        /** @var EveKillmail $km */
        foreach ($killmails as $km) {
            $killmailDates[] = $km->getKillmailTime()->format('Y-m-d');
        }

        // 11. Combine and calculate net items gained
        $dates = array_unique(array_merge(
            array_keys($assetChangeAgg),
            array_keys($marketBuyAgg),
            array_keys($marketSellAgg),
            array_keys($contractRecAgg),
            array_keys($manualEntryAgg),
            $killmailDates
        ));
        sort($dates);

        $carryOverBuy = [];
        $carryOverContract = [];
        $carryOverSell = [];

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
                        'ship_losses' => 0.0,
                        'other' => 0.0
                    ]
                ],
                'details' => []
            ];

            // A. Process wallet journal rewards first (aggregated by character and type per day)
            $dayRewards = [];
            /** @var EveCharacterWalletJournalEntry $entry */
            foreach ($journalEntries as $entry) {
                if ($entry->getDate()->format('Y-m-d') !== $dateStr) {
                    continue;
                }
                
                $charId = $entry->getCharacter()->getId();
                $cutoff = $effectiveCutoffs[$charId] ?? null;
                if ($cutoff !== null && $entry->getDate() < $cutoff) {
                    continue;
                }

                $amount = (float)$entry->getAmount();
                if ($amount <= 0) {
                    continue;
                }

                $charId = $entry->getCharacter()->getId();
                $charName = $entry->getCharacter()->getName();
                $refType = $entry->getRefType();
                
                $rewardName = match ($refType) {
                    'bounty_payout' => 'Kopfgeld-Auszahlung (Bounty)',
                    'agent_mission_reward' => 'Missionsbelohnung (Agent)',
                    'daily_goal_payouts' => 'Tägliche Belohnung (Daily Goal)',
                    default => 'Auszahlung'
                };

                // Check exclusion for this wallet reward group
                $exKey = sprintf('%s_%s_%s_%s', $dateStr, 'wallet_rewards', $rewardName, $charName);
                if (isset($exclusionMap[$exKey])) {
                    continue;
                }

                $aggKey = $charId . '_' . $refType;
                if (!isset($dayRewards[$aggKey])) {
                    $dayRewards[$aggKey] = [
                        'character' => $charName,
                        'category' => 'wallet_rewards',
                        'typeName' => $rewardName,
                        'quantity' => 0,
                        'price' => 0.0,
                        'totalValue' => 0.0,
                        'isWallet' => true,
                        'typeId' => 0
                    ];
                }

                $dayRewards[$aggKey]['quantity']++;
                $dayRewards[$aggKey]['totalValue'] += $amount;
                $dayData['summary']['byCategory']['wallet_rewards'] += $amount;
                $dayData['summary']['totalValue'] += $amount;
            }

            foreach ($dayRewards as $rewardData) {
                if ($rewardData['quantity'] > 0) {
                    $rewardData['price'] = $rewardData['totalValue'] / $rewardData['quantity'];
                }
                $dayData['details'][] = $rewardData;
            }

            // B. Process manual entries
            if (isset($manualEntryAgg[$dateStr])) {
                /** @var \App\Entity\PerformanceManualEntry $entry */
                foreach ($manualEntryAgg[$dateStr] as $entry) {
                    $amount = (float)$entry->getAmount();
                    $cat = $entry->getCategory();
                    $charName = $entry->getCharacter() ? $entry->getCharacter()->getName() : 'Manuelle Buchung';

                    if (isset($dayData['summary']['byCategory'][$cat])) {
                        $dayData['summary']['byCategory'][$cat] += $amount;
                    } else {
                        $dayData['summary']['byCategory']['other'] += $amount;
                    }
                    $dayData['summary']['totalValue'] += $amount;

                    $dayData['details'][] = [
                        'character' => $charName,
                        'category' => $cat,
                        'typeName' => $entry->getDescription(),
                        'quantity' => 1,
                        'price' => $amount,
                        'totalValue' => $amount,
                        'isWallet' => false,
                        'typeId' => 0,
                        'manualEntryId' => $entry->getId()
                    ];
                }
            }

            // B. Process net item changes (user-level)
            $tidsForDate = array_unique(array_merge(
                isset($assetChangeAgg[$dateStr]) ? array_keys($assetChangeAgg[$dateStr]) : [],
                isset($marketBuyAgg[$dateStr]) ? array_keys($marketBuyAgg[$dateStr]) : [],
                isset($marketSellAgg[$dateStr]) ? array_keys($marketSellAgg[$dateStr]) : [],
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
                $sellQty = $marketSellAgg[$dateStr][$rawTid] ?? 0;
                $contractQty = $contractRecAgg[$dateStr][$rawTid] ?? 0;

                $availableBuy = $buyQty + ($carryOverBuy[$rawTid] ?? 0);
                $availableContract = $contractQty + ($carryOverContract[$rawTid] ?? 0);
                $availableSell = $sellQty + ($carryOverSell[$rawTid] ?? 0);

                $netQty = 0;

                if ($changeQty > 0) {
                    $offset = $availableBuy + $availableContract;
                    if ($offset >= $changeQty) {
                        $remainingOffset = $offset - $changeQty;
                        if ($remainingOffset > 0) {
                            if ($availableBuy >= $remainingOffset) {
                                $carryOverBuy[$rawTid] = $remainingOffset;
                                $carryOverContract[$rawTid] = 0;
                            } else {
                                $carryOverBuy[$rawTid] = $availableBuy;
                                $carryOverContract[$rawTid] = $remainingOffset - $availableBuy;
                            }
                        } else {
                            $carryOverBuy[$rawTid] = 0;
                            $carryOverContract[$rawTid] = 0;
                        }
                        $carryOverSell[$rawTid] = $availableSell;
                        $netQty = 0;
                    } else {
                        $netQty = $changeQty - $offset;
                        $carryOverBuy[$rawTid] = 0;
                        $carryOverContract[$rawTid] = 0;
                        $carryOverSell[$rawTid] = $availableSell;
                    }
                } elseif ($changeQty < 0) {
                    $absChange = abs($changeQty);
                    if ($availableSell >= $absChange) {
                        $carryOverSell[$rawTid] = $availableSell - $absChange;
                        $carryOverBuy[$rawTid] = $availableBuy;
                        $carryOverContract[$rawTid] = $availableContract;
                        $netQty = 0;
                    } else {
                        $carryOverSell[$rawTid] = 0;
                        $carryOverBuy[$rawTid] = $availableBuy;
                        $carryOverContract[$rawTid] = $availableContract;
                        $netQty = 0;
                    }
                } else {
                    $carryOverBuy[$rawTid] = $availableBuy;
                    $carryOverContract[$rawTid] = $availableContract;
                    $carryOverSell[$rawTid] = $availableSell;
                    $netQty = 0;
                }

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
                    $rawTid === 34 ||
                    in_array($rawTid, [33577, 33539, 33521, 33527, 33536, 33543, 33545, 33546, 33547, 33548, 33556, 33558, 33560, 33562, 33564, 33566, 57442, 57443, 57444, 57445, 57446, 57447, 57448, 57449, 57450, 57451, 57452], true)
                ) {
                    $category = 'hacking_salvage';
                }

                $attributedChar = $getAttributedCharacter($rawTid, $dateStr);

                // Exclude check
                $exKey = sprintf('%s_%s_%s_%s', $dateStr, $category, $meta['name'], $attributedChar);
                if (isset($exclusionMap[$exKey])) {
                    continue;
                }

                $dayData['summary']['byCategory'][$category] += $totalValue;
                $dayData['summary']['totalValue'] += $totalValue;

                $dayData['details'][] = [
                    'character' => $attributedChar,
                    'category' => $category,
                    'typeName' => $meta['name'],
                    'quantity' => $netQty,
                    'price' => $price,
                    'totalValue' => $totalValue,
                    'isWallet' => false,
                    'typeId' => $rawTid
                ];
            }

            // E. Process ship losses (Killmails where isLoss = true)
            /** @var EveKillmail $km */
            foreach ($killmails as $km) {
                if ($km->getKillmailTime()->format('Y-m-d') !== $dateStr) {
                    continue;
                }

                $charId = $km->getCharacter()->getId();
                $cutoff = $effectiveCutoffs[$charId] ?? null;
                if ($cutoff !== null && $km->getKillmailTime() < $cutoff) {
                    continue;
                }

                $charName = $km->getCharacter()->getName();
                $shipTypeId = $km->getVictimShipTypeId();

                $shipMeta = $itemMetadata[$shipTypeId] ?? [
                    'name' => 'Ship #' . $shipTypeId
                ];
                $shipName = $shipMeta['name'];

                // Exclude check
                $exKey = sprintf('%s_%s_%s_%s', $dateStr, 'ship_losses', 'Verlust: ' . $shipName, $charName);
                if (isset($exclusionMap[$exKey])) {
                    continue;
                }
                
                // Calculate loss value based on global prices for the ship hull and all equipped/carried items
                $shipPrice = $globalPrices[$shipTypeId] ?? 0.0;
                $itemsPrice = 0.0;

                $kmData = $km->getData();
                if (isset($kmData['victim']['items']) && is_array($kmData['victim']['items'])) {
                    foreach ($kmData['victim']['items'] as $item) {
                        if (isset($item['item_type_id'])) {
                            $itemTid = (int)$item['item_type_id'];
                            $qty = (int)($item['quantity_destroyed'] ?? 0) + (int)($item['quantity_dropped'] ?? 0);
                            $price = $globalPrices[$itemTid] ?? 0.0;
                            $itemsPrice += ($qty * $price);
                        }
                    }
                }

                $totalLoss = -1.0 * ($shipPrice + $itemsPrice);

                $dayData['summary']['byCategory']['ship_losses'] += $totalLoss;
                $dayData['summary']['totalValue'] += $totalLoss;

                $shipMeta = $itemMetadata[$shipTypeId] ?? [
                    'name' => 'Ship #' . $shipTypeId
                ];
                $shipName = $shipMeta['name'];

                $dayData['details'][] = [
                    'character' => $charName,
                    'category' => 'ship_losses',
                    'typeName' => 'Verlust: ' . $shipName,
                    'quantity' => 1,
                    'price' => $totalLoss,
                    'totalValue' => $totalLoss,
                    'isWallet' => false,
                    'typeId' => $shipTypeId
                ];
            }

            if (!empty($dayData['details'])) {
                // Sort details by totalValue descending
                usort($dayData['details'], fn($a, $b) => $b['totalValue'] <=> $a['totalValue']);
                $dailyLedger[$dateStr] = $dayData;
            }
        }

        $startStr = $startDate->format('Y-m-d');
        $endStr = $endDate->format('Y-m-d');

        $filteredLedger = [];
        foreach ($dailyLedger as $dateStr => $dayData) {
            if ($dateStr >= $startStr && $dateStr <= $endStr) {
                $filteredLedger[$dateStr] = $dayData;
            }
        }

        // Return sorted descending by date
        krsort($filteredLedger);
        return $filteredLedger;
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

    /**
     * Resolves the list of tracked type IDs from custom lists and the SDE.
     * 
     * @return int[]
     */
    private function getTrackedTypeIds(): array
    {
        try {
            $listItems = $this->entityManager->getRepository(TrackingListItem::class)->findAll();
            $trackedTypeIds = [];
            foreach ($listItems as $item) {
                $trackedTypeIds[] = $item->getTypeId();
            }

            $sdeTypeIds = $this->sdeService->getPerformanceTypeIds();
            $trackedTypeIds = array_merge($trackedTypeIds, $sdeTypeIds);

            return array_values(array_unique(array_filter($trackedTypeIds)));
        } catch (\Exception $e) {
            return [];
        }
    }
}
