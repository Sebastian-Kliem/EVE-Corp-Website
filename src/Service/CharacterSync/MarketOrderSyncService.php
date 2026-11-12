<?php

namespace App\Service\CharacterSync;

use App\Entity\EveCharacter;
use App\Entity\EveCharacterMarketOrder;
use App\Service\Esi\EsiClient;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;

/**
 * Replaces a character's stored market orders with the current ESI state and logs every order state for the broker fee attribution.
 */
class MarketOrderSyncService
{
    private const LOG_INSERT_CHUNK_SIZE = 500;

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly EsiClient $esiClient,
        private readonly LoggerInterface $logger
    ) {}

    public function sync(EveCharacter $character): void
    {
        $this->logger->debug(sprintf('[Cron] Syncing market orders for character %s...', $character->getName()));

        try {
            $response = $this->esiClient->requestAllPages(
                sprintf('characters/%d/orders/', $character->getId()),
                [],
                $character
            );

            if ($response['fromCache'] ?? false) {
                $this->logger->info(sprintf('[Cron] Market orders for character %s are still cached. Skipping update.', $character->getName()));
                return;
            }

            $ordersData = $response['data'];
        } catch (\Symfony\Contracts\HttpClient\Exception\HttpExceptionInterface $e) {
            if ($e->getResponse()->getStatusCode() === 404) {
                $ordersData = [];
            } else {
                throw $e;
            }
        }

        $this->entityManager->wrapInTransaction(function() use ($character, $ordersData) {
            // Delete all existing active market orders for this character
            $this->entityManager->createQueryBuilder()
                ->delete(EveCharacterMarketOrder::class, 'o')
                ->where('o.character = :character')
                ->setParameter('character', $character)
                ->getQuery()
                ->execute();

            $insertedCount = 0;
            foreach ($ordersData as $oData) {
                $order = new EveCharacterMarketOrder();
                $order->setCharacter($character);
                $order->setOrderId((string)$oData['order_id']);
                $order->setTypeId((int)$oData['type_id']);
                $order->setLocationId((string)$oData['location_id']);
                $order->setVolumeTotal((int)$oData['volume_total']);
                $order->setVolumeRemain((int)$oData['volume_remain']);
                $order->setPrice(number_format((float)$oData['price'], 2, '.', ''));
                if (isset($oData['escrow'])) {
                    $order->setEscrow(number_format((float)$oData['escrow'], 2, '.', ''));
                }
                $order->setIsBuy((bool)($oData['is_buy_order'] ?? false));
                $order->setIsCorporation((bool)($oData['is_corporation'] ?? false));
                $order->setIssued(new \DateTimeImmutable($oData['issued']));
                $order->setDuration((int)$oData['duration']);
                $order->setRange((string)$oData['range']);
                if (isset($oData['min_volume'])) {
                    $order->setMinVolume((int)$oData['min_volume']);
                }

                $this->entityManager->persist($order);
                $insertedCount++;
            }

            $this->entityManager->flush();

            if ($insertedCount > 0) {
                $this->logger->info(sprintf(
                    '[Cron] Successfully synchronized %d active market orders for character %s.',
                    $insertedCount,
                    $character->getName()
                ));
            }
        });

        $this->_logOrderStates($character, $ordersData);
    }

    /**
     * Closed orders of the last 90 days (ESI keeps no more), only their final state is known.
     */
    public function syncHistory(EveCharacter $character): void
    {
        try {
            $response = $this->esiClient->requestAllPages(
                sprintf('characters/%d/orders/history/', $character->getId()),
                [],
                $character
            );
        } catch (\Symfony\Contracts\HttpClient\Exception\HttpExceptionInterface $e) {
            if ($e->getResponse()->getStatusCode() === 404) {
                return;
            }
            throw $e;
        }

        if (!($response['fromCache'] ?? false)) {
            $this->_logOrderStates($character, $response['data']);
        }
    }

    /**
     * @param array<int, array<string, mixed>> $ordersData ESI order rows, corporation orders are skipped (their fees hit the corp wallet)
     */
    private function _logOrderStates(EveCharacter $character, array $ordersData): void
    {
        $rows = [];
        foreach ($ordersData as $oData) {
            if (!empty($oData['is_corporation']) || empty($oData['issued'])) {
                continue;
            }
            $rows[] = [
                (int)$character->getId(),
                (string)$oData['order_id'],
                (int)$oData['type_id'],
                (new \DateTimeImmutable($oData['issued']))->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s'),
                number_format((float)$oData['price'], 2, '.', ''),
                (int)$oData['volume_total'],
                (int)$oData['volume_remain'],
                !empty($oData['is_buy_order']) ? 1 : 0,
                (int)($oData['duration'] ?? 0),
            ];
        }

        foreach (array_chunk($rows, self::LOG_INSERT_CHUNK_SIZE) as $chunk) {
            $this->_insertLogChunk($chunk);
        }
    }

    /**
     * @param list<list<int|string>> $rows
     */
    private function _insertLogChunk(array $rows): void
    {
        $placeholders = [];
        $parameters = [];
        foreach ($rows as $row) {
            $placeholders[] = '(?, ?, ?, ?, ?, ?, ?, ?, ?)';
            foreach ($row as $value) {
                $parameters[] = $value;
            }
        }

        // Known states are skipped by the unique key (character, order, issued)
        $this->entityManager->getConnection()->executeStatement(
            'INSERT IGNORE INTO eve_character_market_order_log
                (character_id, order_id, type_id, issued, price, volume_total, volume_remain, is_buy, duration)
             VALUES ' . implode(', ', $placeholders),
            $parameters
        );
    }
}
