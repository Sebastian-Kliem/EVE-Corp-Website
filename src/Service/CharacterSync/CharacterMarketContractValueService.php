<?php

namespace App\Service\CharacterSync;

use App\Entity\EveCharacter;
use App\Entity\EveCharacterContract;
use App\Entity\EveCharacterMarketOrder;
use App\Service\JitaPriceService;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Values ISK and items a character has tied up in personal market orders and open contracts (corporation ones are ignored).
 */
class CharacterMarketContractValueService
{
    private const OPEN_CONTRACT_STATUSES = ['outstanding', 'in_progress'];

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly JitaPriceService $jitaPriceService
    ) {}

    /**
     * Buy orders count with their escrow, sell orders with the remaining volume at the global average price.
     *
     * @return array{buyEscrow: float, sellOrders: float, total: float}
     */
    public function calculateMarketValue(EveCharacter $character): array
    {
        $prices = $this->jitaPriceService->getGlobalPrices();
        $buyEscrow = 0.0;
        $sellOrders = 0.0;

        foreach ($this->findPersonalMarketOrders($character) as $order) {
            if ($order->isBuy()) {
                $buyEscrow += (float)($order->getEscrow() ?? 0.0);
                continue;
            }
            $sellOrders += ($prices[$order->getTypeId()] ?? 0.0) * $order->getVolumeRemain();
        }

        return [
            'buyEscrow' => $buyEscrow,
            'sellOrders' => $sellOrders,
            'total' => $buyEscrow + $sellOrders,
        ];
    }

    /**
     * @return EveCharacterMarketOrder[]
     */
    public function findPersonalMarketOrders(EveCharacter $character): array
    {
        return $this->entityManager->getRepository(EveCharacterMarketOrder::class)->findBy([
            'character' => $character,
            'isCorporation' => false,
        ]);
    }

    /**
     * Own open contracts count with their included items and escrowed reward, accepted couriers with the paid collateral.
     */
    public function calculateContractsValue(EveCharacter $character): float
    {
        $prices = $this->jitaPriceService->getGlobalPrices();
        $contracts = $this->entityManager->getRepository(EveCharacterContract::class)->findBy([
            'character' => $character,
            'forCorporation' => false,
            'status' => self::OPEN_CONTRACT_STATUSES,
        ]);

        $totalValue = 0.0;
        foreach ($contracts as $contract) {
            if ($contract->getIssuerId() === $character->getId()) {
                $totalValue += $this->_calculateIssuedContractValue($contract, $prices);
            } elseif ($contract->getAcceptorId() === $character->getId() && $contract->getType() === 'courier') {
                $totalValue += (float)($contract->getCollateral() ?? 0.0);
            }
        }

        return $totalValue;
    }

    /**
     * @param array<int, float> $prices
     */
    private function _calculateIssuedContractValue(EveCharacterContract $contract, array $prices): float
    {
        $value = (float)($contract->getReward() ?? 0.0);

        foreach ($contract->getItems() as $item) {
            // Items with isIncluded = false are requested from the acceptor, not given away
            if (empty($item['isIncluded']) || !empty($item['isBlueprintCopy'])) {
                continue;
            }
            $typeId = (int)($item['typeId'] ?? 0);
            $value += ($prices[$typeId] ?? 0.0) * (int)($item['quantity'] ?? 0);
        }

        return $value;
    }
}
