<?php

namespace App\Service\CharacterSync;

use App\Entity\EveCharacter;
use App\Entity\EveCharacterMarketOrder;
use App\Service\Esi\EsiClient;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;

/**
 * Replaces a character's stored market orders with the current ESI state.
 */
class MarketOrderSyncService
{
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
    }
}
