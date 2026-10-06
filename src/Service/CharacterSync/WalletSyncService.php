<?php

namespace App\Service\CharacterSync;

use App\Entity\EveCharacter;
use App\Entity\EveCharacterMarketTransaction;
use App\Entity\EveCharacterWalletJournalEntry;
use App\Service\Esi\EsiClient;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;

/**
 * Syncs a character's wallet balance, wallet journal and market transactions from ESI.
 */
class WalletSyncService
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly EsiClient $esiClient,
        private readonly LoggerInterface $logger
    ) {}

    public function syncBalance(EveCharacter $character): void
    {
        $this->logger->debug(sprintf('[Cron] Syncing wallet for character %s...', $character->getName()));
        
        // GET /characters/{character_id}/wallet/
        // Returns the ISK balance as a float
        $balance = $this->esiClient->request(
            'GET',
            sprintf('characters/%d/wallet/', $character->getId()),
            [],
            $character
        );

        // Convert to string to store as decimal without floating point issues
        $character->setWalletBalance(number_format((float) $balance, 2, '.', ''));
        $character->setLastWalletUpdate(new \DateTimeImmutable());
        
        $this->entityManager->flush();
        
        $this->logger->info(sprintf(
            '[Cron] Successfully updated wallet for character %s to %s ISK.',
            $character->getName(),
            $character->getWalletBalance()
        ));
    }

    public function syncJournal(EveCharacter $character): void
    {
        $this->logger->debug(sprintf('[Cron] Syncing wallet journal for character %s...', $character->getName()));
        
        $page = 1;
        $insertedCount = 0;
        $repo = $this->entityManager->getRepository(EveCharacterWalletJournalEntry::class);

        while (true) {
            try {
                $journalResponse = $this->esiClient->requestWithHeaders(
                    'GET',
                    sprintf('characters/%d/wallet/journal/', $character->getId()),
                    [
                        'query' => ['page' => $page]
                    ],
                    $character
                );
                $journalData = $journalResponse['data'];
                $totalPages = (int) ($journalResponse['headers']['x-pages'][0] ?? 1);

                if (empty($journalData)) {
                    break;
                }

                $hasExisting = false;
                // One lookup per page instead of one query per entry
                $pageRefIds = [];
                foreach ($journalData as $entryData) {
                    $pageRefIds[] = (string) $entryData['id'];
                }
                $existingRefIds = $repo->findExistingRefIds($character, $pageRefIds);

                foreach ($journalData as $entryData) {
                    $refId = (string) $entryData['id'];

                    if (isset($existingRefIds[$refId])) {
                        $hasExisting = true;
                        continue;
                    }

                    $entry = new EveCharacterWalletJournalEntry();
                    $entry->setCharacter($character);
                    $entry->setRefId($refId);
                    $entry->setDate(new \DateTimeImmutable($entryData['date']));
                    $entry->setRefType($entryData['ref_type']);
                    $entry->setAmount(number_format((float) ($entryData['amount'] ?? 0.0), 2, '.', ''));
                    $entry->setBalance(number_format((float) ($entryData['balance'] ?? 0.0), 2, '.', ''));
                    $entry->setDescription($entryData['description'] ?? null);
                    $entry->setFirstPartyId($entryData['first_party_id'] ?? null);
                    $entry->setSecondPartyId($entryData['second_party_id'] ?? null);
                    
                    if (isset($entryData['context_id'])) {
                        $entry->setContextId((string) $entryData['context_id']);
                    }
                    $entry->setContextIdType($entryData['context_id_type'] ?? null);
                    $entry->setReason($entryData['reason'] ?? null);
                    
                    if (isset($entryData['tax'])) {
                        $entry->setTax(number_format((float) $entryData['tax'], 2, '.', ''));
                    }
                    $entry->setTaxReceiverId($entryData['tax_receiver_id'] ?? null);

                    $this->entityManager->persist($entry);
                    $insertedCount++;
                }

                $this->entityManager->flush();

                // ESI returns descending chronological order.
                // If we hit any existing transaction, or this was the last page, stop fetching.
                if ($hasExisting || $page >= $totalPages) {
                    break;
                }

                $page++;
                if ($page > 10) {
                    break;
                }

            } catch (\Exception $e) {
                $this->logger->error(sprintf(
                    '[Cron] Failed to fetch wallet journal page %d for character %s: %s',
                    $page,
                    $character->getName(),
                    $e->getMessage()
                ));
                break;
            }
        }

        if ($insertedCount > 0) {
            $this->logger->info(sprintf(
                '[Cron] Successfully synchronized %d new wallet journal entries for character %s.',
                $insertedCount,
                $character->getName()
            ));
        }
    }

    public function syncMarketTransactions(EveCharacter $character): void
    {
        $this->logger->debug(sprintf('[Cron] Syncing market transactions for character %s...', $character->getName()));

        $fromId = null;
        $insertedCount = 0;
        $repo = $this->entityManager->getRepository(EveCharacterMarketTransaction::class);

        while (true) {
            try {
                $query = [];
                if ($fromId !== null) {
                    $query['from_id'] = $fromId;
                }

                $transData = $this->esiClient->request(
                    'GET',
                    sprintf('characters/%d/wallet/transactions/', $character->getId()),
                    [
                        'query' => $query
                    ],
                    $character
                );

                if (empty($transData) || !is_array($transData)) {
                    break;
                }

                $hasExisting = false;
                $lastTransId = null;

                // One lookup per page instead of one query per transaction
                $pageTransactionIds = [];
                foreach ($transData as $tData) {
                    $pageTransactionIds[] = (string) $tData['transaction_id'];
                }
                $existingTransactionIds = $repo->findExistingTransactionIds($character, $pageTransactionIds);

                foreach ($transData as $tData) {
                    $transId = (string) $tData['transaction_id'];

                    // from_id is inclusive: the first entry repeats the last one of the previous page
                    if ($transId === $fromId) {
                        continue;
                    }

                    if ($lastTransId === null || (int) $transId < (int) $lastTransId) {
                        $lastTransId = $transId;
                    }

                    if (isset($existingTransactionIds[$transId])) {
                        $hasExisting = true;
                        continue;
                    }

                    $transaction = new EveCharacterMarketTransaction();
                    $transaction->setCharacter($character);
                    $transaction->setTransactionId($transId);
                    $transaction->setDate(new \DateTimeImmutable($tData['date']));
                    $transaction->setTypeId((int) $tData['type_id']);
                    $transaction->setQuantity((string) $tData['quantity']);
                    $transaction->setUnitPrice(number_format((float) $tData['unit_price'], 2, '.', ''));
                    $transaction->setIsBuy((bool) $tData['is_buy']);
                    $transaction->setClientId((int) $tData['client_id']);
                    $transaction->setLocationId((string) $tData['location_id']);
                    $transaction->setJournalRefId((string) $tData['journal_ref_id']);

                    $this->entityManager->persist($transaction);
                    $insertedCount++;
                }

                $this->entityManager->flush();

                if ($hasExisting || count($transData) < 2500 || $lastTransId === null) {
                    break;
                }

                $fromId = (string) $lastTransId;

            } catch (\Exception $e) {
                $this->logger->error(sprintf(
                    '[Cron] Failed to fetch market transactions for character %s: %s',
                    $character->getName(),
                    $e->getMessage()
                ));
                break;
            }
        }

        if ($insertedCount > 0) {
            $this->logger->info(sprintf(
                '[Cron] Successfully synchronized %d new market transactions for character %s.',
                $insertedCount,
                $character->getName()
            ));
        }
    }
}
