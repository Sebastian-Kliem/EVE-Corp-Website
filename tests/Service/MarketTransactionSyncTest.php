<?php

namespace App\Tests\Service;

use App\Entity\EveCharacter;
use App\Entity\EveCharacterMarketTransaction;
use App\Repository\EveCharacterAssetRepository;
use App\Repository\EveCorporationAssetRepository;
use App\Service\Cron\UpdateCharacterDataTask;
use App\Service\Esi\EsiClient;
use App\Service\JitaPriceService;
use App\Service\PersonalCorpAssetService;
use App\Service\SdeService;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\EntityRepository;
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

        $transactionRepository = $this->createStub(EntityRepository::class);
        $transactionRepository->method('findOneBy')->willReturnCallback(function (array $criteria) use (&$persistedIds): ?object {
            // @phpstan-ignore isset.offset ($persistedIds is filled by reference during the sync)
            return isset($persistedIds[$criteria['transactionId']]) ? new \stdClass() : null;
        });

        $entityManager = $this->createStub(EntityManagerInterface::class);
        $entityManager->method('getRepository')->willReturn($transactionRepository);
        $entityManager->method('persist')->willReturnCallback(function (object $entity) use (&$persistedIds): void {
            if ($entity instanceof EveCharacterMarketTransaction) {
                $persistedIds[$entity->getTransactionId()] = true;
            }
        });

        $task = new UpdateCharacterDataTask(
            $entityManager,
            $esiClient,
            $this->createStub(EveCharacterAssetRepository::class),
            $this->createStub(EveCorporationAssetRepository::class),
            $this->createStub(SdeService::class),
            new NullLogger(),
            $this->createStub(JitaPriceService::class),
            $this->createStub(PersonalCorpAssetService::class)
        );

        $character = new EveCharacter();
        $character->setId(123);
        $character->setName('Trader');

        (new \ReflectionMethod($task, 'syncMarketTransactions'))->invoke($task, $character);

        $this->assertCount(count($allTransactionIds), $persistedIds);
        $this->assertSame([null, '4501', '2002'], $requestedFromIds);
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
