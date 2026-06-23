<?php

namespace App\Service\Cron;

use App\Entity\EveCharacter;
use App\Entity\EveCharacterContract;
use App\Service\Esi\EsiClient;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;

class UpdateCharacterContractsTask implements CronTaskInterface
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly EsiClient $esiClient,
        private readonly LoggerInterface $logger
    ) {}

    public function getCommandName(): string
    {
        return 'character:sync-contracts';
    }

    public function execute(): void
    {
        $characterRepository = $this->entityManager->getRepository(EveCharacter::class);
        /** @var EveCharacter[] $characters */
        $characters = $characterRepository->findAll();

        $this->logger->info(sprintf('[Cron] Starting sync-contracts for %d characters.', count($characters)));

        foreach ($characters as $character) {
            if (empty($character->getRefreshToken())) {
                continue;
            }

            try {
                $this->syncCharacterContracts($character);
            } catch (\Exception $e) {
                $this->logger->error(sprintf(
                    '[Cron] Failed to sync contracts for character %s (%d): %s',
                    $character->getName(),
                    $character->getId(),
                    $e->getMessage()
                ));
            }
        }

        $this->logger->info('[Cron] Finished sync-contracts execution.');
    }

    private function syncCharacterContracts(EveCharacter $character): void
    {
        $this->logger->debug(sprintf('[Cron] Syncing contracts for character %s...', $character->getName()));

        $page = 1;
        $allContracts = [];

        while (true) {
            try {
                $contracts = $this->esiClient->request(
                    'GET',
                    sprintf('characters/%d/contracts/', $character->getId()),
                    [
                        'query' => ['page' => $page]
                    ],
                    $character
                );

                if (empty($contracts) || !is_array($contracts)) {
                    break;
                }

                $allContracts = array_merge($allContracts, $contracts);
                
                if (count($contracts) < 1000) {
                    break;
                }
                $page++;
            } catch (\Symfony\Contracts\HttpClient\Exception\HttpExceptionInterface $e) {
                if ($e->getResponse()->getStatusCode() === 403) {
                    $this->logger->warning(sprintf('[Cron] Character %s lacks scope or permission for contracts.', $character->getName()));
                    return;
                }
                throw $e;
            } catch (\Exception $e) {
                if ($page === 1) {
                    throw $e;
                }
                break;
            }
        }

        if (empty($allContracts)) {
            return;
        }

        $repo = $this->entityManager->getRepository(EveCharacterContract::class);

        $this->entityManager->wrapInTransaction(function() use ($character, $allContracts, $repo) {
            foreach ($allContracts as $cData) {
                $contractId = (string)$cData['contract_id'];
                
                // Fetch existing contract to update or create new one
                $contract = $repo->findOneBy([
                    'character' => $character,
                    'contractId' => $contractId
                ]);

                if (!$contract) {
                    $contract = new EveCharacterContract();
                    $contract->setCharacter($character);
                    $contract->setContractId($contractId);
                }

                $contract->setType($cData['type']);
                $contract->setStatus($cData['status']);
                $contract->setStartLocationId(isset($cData['start_location_id']) ? (string)$cData['start_location_id'] : null);
                $contract->setEndLocationId(isset($cData['end_location_id']) ? (string)$cData['end_location_id'] : null);
                $contract->setPrice(isset($cData['price']) ? number_format((float)$cData['price'], 2, '.', '') : null);
                $contract->setReward(isset($cData['reward']) ? number_format((float)$cData['reward'], 2, '.', '') : null);
                $contract->setCollateral(isset($cData['collateral']) ? number_format((float)$cData['collateral'], 2, '.', '') : null);
                $contract->setBuyout(isset($cData['buyout']) ? number_format((float)$cData['buyout'], 2, '.', '') : null);
                
                $contract->setDateIssued(new \DateTimeImmutable($cData['date_issued']));
                $contract->setDateExpired(new \DateTimeImmutable($cData['date_expired']));
                $contract->setDateCompleted(isset($cData['date_completed']) ? new \DateTimeImmutable($cData['date_completed']) : null);
                
                $contract->setTitle(isset($cData['title']) ? $cData['title'] : null);
                $contract->setIssuerId((int)$cData['issuer_id']);
                $contract->setAcceptorId((int)$cData['acceptor_id']);

                // Fetch contract items if not already stored, or if contract was recently updated
                $items = [];
                // Only request items for item_exchange or auction, courier contracts don't list specific item outputs
                if ($cData['type'] !== 'courier') {
                    try {
                        $itemsData = $this->esiClient->request(
                            'GET',
                            sprintf('characters/%d/contracts/%d/items/', $character->getId(), $cData['contract_id']),
                            [],
                            $character
                        );
                        if (is_array($itemsData)) {
                            foreach ($itemsData as $iData) {
                                $items[] = [
                                    'typeId' => (int)$iData['type_id'],
                                    'quantity' => (int)$iData['quantity'],
                                    'isIncluded' => (bool)$iData['is_included']
                                ];
                            }
                        }
                    } catch (\Exception $e) {
                        $this->logger->warning(sprintf('[Cron] Failed to fetch items for contract %s: %s', $contractId, $e->getMessage()));
                    }
                }
                
                $contract->setItems($items);
                $this->entityManager->persist($contract);
            }
            $this->entityManager->flush();
        });

        $this->logger->info(sprintf(
            '[Cron] Successfully updated %d contracts for character %s.',
            count($allContracts),
            $character->getName()
        ));
    }
}
