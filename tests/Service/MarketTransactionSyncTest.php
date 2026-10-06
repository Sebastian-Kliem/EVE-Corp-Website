<?php

namespace App\Tests\Service;

use App\Entity\EveCharacter;
use App\Entity\EveCharacterMarketTransaction;
use App\Entity\EveCharacterWalletJournalEntry;
use App\Repository\EveCharacterAssetRepository;
use App\Repository\EveCharacterMarketTransactionRepository;
use App\Repository\EveCharacterWalletJournalEntryRepository;
use App\Repository\EveCorporationAssetRepository;
use App\Service\Cron\UpdateCharacterDataTask;
use App\Service\Esi\EsiClient;
use App\Service\JitaPriceService;
use App\Service\PersonalCorpAssetService;
use App\Service\SdeService;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

class MarketTransactionSyncTest extends TestCase
{
    private const PAGE_SIZE = 2500;

    public function testInclusiveFromIdPagesThroughAllTransactions(): void
    {
        // ESI returns newest first; from_id includes the referenced transaction
        $allTransactionIds = range(7000, 1, -1);

        $persistedIds = [];
        $requestedFromIds = [];

        $esiClient = $this->createStub(EsiClient::class);
        $esiClient->method('request')->willReturnCallback(function (string $method, string $path, array $options) use ($allTransactionIds, &$requestedFromIds): array {
            $fromId = $options['query']['from_id'] ?? null;
            $requestedFromIds[] = $fromId;
            $startIndex = $fromId === null ? 0 : array_search((int) $fromId, $allTransactionIds, true);

            $page = [];
            foreach (array_slice($allTransactionIds, $startIndex, self::PAGE_SIZE) as $transactionId) {
                $page[] = $this->_createTransaction($transactionId);
            }

            return $page;
        });

        $transactionRepository = $this->createStub(EveCharacterMarketTransactionRepository::class);
        $transactionRepository->method('findExistingTransactionIds')->willReturnCallback(function (EveCharacter $character, array $transactionIds) use (&$persistedIds): array {
            return array_intersect_key($persistedIds, array_flip($transactionIds));
        });

        $entityManager = $this->createStub(EntityManagerInterface::class);
        $entityManager->method('getRepository')->willReturn($transactionRepository);
        $entityManager->method('persist')->willReturnCallback(function (object $entity) use (&$persistedIds): void {
            if ($entity instanceof EveCharacterMarketTransaction) {
                $persistedIds[$entity->getTransactionId()] = true;
            }
        });

        $task = $this->_createTask($entityManager, $esiClient);

        $character = new EveCharacter();
        $character->setId(123);
        $character->setName('Trader');

        (new \ReflectionMethod($task, '_syncMarketTransactions'))->invoke($task, $character);

        $this->assertCount(count($allTransactionIds), $persistedIds);
        $this->assertSame([null, '4501', '2002'], $requestedFromIds);
    }

    public function testWalletJournalInsertsOnlyNewEntriesWithOneLookupPerPage(): void
    {
        $storedRefIds = ['3' => true, '2' => true, '1' => true];
        $lookupCount = 0;
        $insertedRefIds = [];

        $esiClient = $this->createStub(EsiClient::class);
        $esiClient->method('requestWithHeaders')->willReturn([
            'data' => [$this->_createJournalEntry(5), $this->_createJournalEntry(4), $this->_createJournalEntry(3), $this->_createJournalEntry(2)],
            'headers' => ['x-pages' => ['3']],
        ]);

        $journalRepository = $this->createStub(EveCharacterWalletJournalEntryRepository::class);
        $journalRepository->method('findExistingRefIds')->willReturnCallback(function (EveCharacter $character, array $refIds) use ($storedRefIds, &$lookupCount): array {
            $lookupCount++;
            return array_intersect_key($storedRefIds, array_flip($refIds));
        });

        $entityManager = $this->createStub(EntityManagerInterface::class);
        $entityManager->method('getRepository')->willReturn($journalRepository);
        $entityManager->method('persist')->willReturnCallback(function (object $entity) use (&$insertedRefIds): void {
            if ($entity instanceof EveCharacterWalletJournalEntry) {
                $insertedRefIds[] = $entity->getRefId();
            }
        });

        $character = new EveCharacter();
        $character->setId(123);
        $character->setName('Trader');

        $task = $this->_createTask($entityManager, $esiClient);
        (new \ReflectionMethod($task, '_syncWalletJournal'))->invoke($task, $character);

        // Known entries stop the paging, so page 2 is never requested
        $this->assertSame(['5', '4'], $insertedRefIds);
        $this->assertSame(1, $lookupCount);
    }

    private function _createTask(EntityManagerInterface $entityManager, EsiClient $esiClient): UpdateCharacterDataTask
    {
        return new UpdateCharacterDataTask(
            $entityManager,
            $esiClient,
            $this->createStub(EveCharacterAssetRepository::class),
            $this->createStub(EveCorporationAssetRepository::class),
            $this->createStub(SdeService::class),
            new NullLogger(),
            $this->createStub(JitaPriceService::class),
            $this->createStub(PersonalCorpAssetService::class)
        );
    }

    private function _createJournalEntry(int $refId): array
    {
        return [
            'id' => $refId,
            'date' => '2026-10-01T12:00:00Z',
            'ref_type' => 'bounty_prizes',
            'amount' => 1000.0,
            'balance' => 5000.0,
            'description' => 'Bounty',
        ];
    }

    private function _createTransaction(int $transactionId): array
    {
        return [
            'transaction_id' => $transactionId,
            'date' => '2026-10-01T12:00:00Z',
            'type_id' => 34,
            'quantity' => 1,
            'unit_price' => 5.0,
            'is_buy' => false,
            'client_id' => 1,
            'location_id' => 60003760,
            'journal_ref_id' => $transactionId,
        ];
    }
}
