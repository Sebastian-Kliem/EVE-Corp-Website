<?php

namespace App\Service\Cron;

use App\Entity\EveCharacter;
use App\Entity\EveCharacterMiningRecord;
use App\Repository\EveCharacterMiningRecordRepository;
use App\Service\Esi\EsiClient;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;

class UpdateCharacterMiningLedgerTask implements CronTaskInterface
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly EsiClient $esiClient,
        private readonly EveCharacterMiningRecordRepository $miningRecordRepository,
        private readonly LoggerInterface $logger
    ) {}

    public function getCommandName(): string
    {
        return 'character:sync-mining-ledger';
    }

    public function execute(): void
    {
        $characterRepository = $this->entityManager->getRepository(EveCharacter::class);
        /** @var EveCharacter[] $characters */
        $characters = $characterRepository->findAll();

        $this->logger->info(sprintf('[Cron] Starting sync-mining-ledger for %d characters.', count($characters)));

        foreach ($characters as $character) {
            if (empty($character->getRefreshToken())) {
                continue;
            }

            try {
                $this->syncMiningLedger($character);
            } catch (\Exception $e) {
                $this->logger->error(sprintf(
                    '[Cron] Failed to sync mining ledger for character %s (%d): %s',
                    $character->getName(),
                    $character->getId(),
                    $e->getMessage()
                ));
            }
        }

        $this->logger->info('[Cron] Finished sync-mining-ledger execution.');
    }

    private function syncMiningLedger(EveCharacter $character): void
    {
        $this->logger->debug(sprintf('[Cron] Syncing mining ledger for character %s...', $character->getName()));

        $page = 1;
        $allRecords = [];
        $totalPages = 1;

        while ($page <= $totalPages) {
            try {
                $response = $this->esiClient->requestWithHeaders(
                    'GET',
                    sprintf('characters/%d/mining/', $character->getId()),
                    [
                        'query' => ['page' => $page]
                    ],
                    $character
                );

                $records = $response['data'];
                $headers = $response['headers'];

                if (empty($records) || !is_array($records)) {
                    break;
                }

                $allRecords = array_merge($allRecords, $records);

                if ($page === 1 && isset($headers['x-pages'][0])) {
                    $totalPages = (int)$headers['x-pages'][0];
                }

                $page++;
            } catch (\Symfony\Contracts\HttpClient\Exception\HttpExceptionInterface $e) {
                if ($e->getResponse()->getStatusCode() === 404) {
                    break;
                }
                throw $e;
            }
        }

        if (empty($allRecords)) {
            $character->setLastMiningUpdate(new \DateTimeImmutable());
            $this->entityManager->flush();
            $this->logger->info(sprintf('[Cron] No mining records found for character %s.', $character->getName()));
            return;
        }

        // Find oldest date in the ESI response
        $oldestDateStr = null;
        foreach ($allRecords as $record) {
            $dateStr = $record['date'];
            if ($oldestDateStr === null || strcmp($dateStr, $oldestDateStr) < 0) {
                $oldestDateStr = $dateStr;
            }
        }

        $oldestDate = new \DateTimeImmutable($oldestDateStr);

        $this->entityManager->wrapInTransaction(function() use ($character, $allRecords, $oldestDate) {
            // 1. Delete records since oldestDate to overwrite them without duplicates
            $this->entityManager->createQueryBuilder()
                ->delete(EveCharacterMiningRecord::class, 'r')
                ->where('r.character = :char')
                ->andWhere('r.date >= :oldestDate')
                ->setParameter('char', $character)
                ->setParameter('oldestDate', $oldestDate->format('Y-m-d'))
                ->getQuery()
                ->execute();

            // 2. Insert new records in batches
            $batchSize = 250;
            $i = 0;

            foreach ($allRecords as $recordData) {
                $record = new EveCharacterMiningRecord();
                $record->setCharacter($character);
                $record->setDate(new \DateTimeImmutable($recordData['date']));
                $record->setSolarSystemId((int)$recordData['solar_system_id']);
                $record->setTypeId((int)$recordData['type_id']);
                $record->setQuantity((string)$recordData['quantity']);

                $this->entityManager->persist($record);
                $i++;

                if (($i % $batchSize) === 0) {
                    $this->entityManager->flush();
                }
            }

            $character->setLastMiningUpdate(new \DateTimeImmutable());
            $this->entityManager->flush();
        });

        $this->logger->info(sprintf(
            '[Cron] Successfully updated %d mining records (since %s) for character %s.',
            count($allRecords),
            $oldestDateStr,
            $character->getName()
        ));
    }
}
